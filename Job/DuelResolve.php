<?php

namespace Sylphian\UserPets\Job;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Duel\WinnerPicker;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\XpSource;
use XF\Entity\User;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use XF\Repository\UserAlertRepository;

/**
 * Fights an accepted duel. The accepted -> completed change is conditional and the win/loss
 * counters and rewards are written in the same transaction after it, so a resumed or duplicated
 * run finds the duel completed and neither counts nor awards anything.
 */
class DuelResolve extends AbstractJob
{
	public const int MAX_ATTEMPTS = 5;

	/** Content type written to the XP history for duel wins. */
	public const string XP_CONTENT_TYPE = 'syl_userpet_duel';

	protected $defaultData = [
		'duel_id' => 0,
		'attempts' => 0,
	];

	public function run($maxRunTime): JobResult
	{
		$duelId = (int) $this->data['duel_id'];
		if (!$duelId)
		{
			return $this->complete();
		}

		try
		{
			$outcome = $this->resolveDuel($duelId);
		}
		catch (\Throwable $e)
		{
			$this->data['attempts'] = (int) $this->data['attempts'] + 1;

			if ($this->data['attempts'] >= self::MAX_ATTEMPTS)
			{
				Logger::error('Failed to resolve duel after multiple attempts, the duel was cancelled', [
					'duel_id' => $duelId,
					'attempts' => $this->data['attempts'],
					'exception' => $e->getMessage(),
				]);

				$this->cancelUnresolvableDuel($duelId);

				return $this->complete();
			}

			Logger::warning('Failed to resolve duel, retrying', [
				'duel_id' => $duelId,
				'attempts' => $this->data['attempts'],
				'exception' => $e->getMessage(),
			]);

			return $this->resume();
		}

		if ($outcome)
		{
			$this->sendResultAlerts($outcome);
		}

		return $this->complete();
	}

	/**
	 * @return array{duel:UserPetsDuel,winner:UserPets,loser:UserPets,win_exp:int,lose_stats:int}|null
	 *         null when there was nothing (left) to resolve
	 */
	protected function resolveDuel(int $duelId): ?array
	{
		$duelRepo = $this->getDuelRepo();

		/** @var UserPetsDuel|null $duel */
		$duel = $this->app->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId);
		if (!$duel || $duel->status !== 'accepted')
		{
			return null;
		}

		$challengerPet = $duel->ChallengerPet;
		$opponentPet = $duel->OpponentPet;
		if (!$challengerPet || !$opponentPet)
		{
			Logger::warning('Duel cancelled because a pet no longer exists', [
				'duel_id' => $duelId,
				'challenger_pet_id' => $duel->challenger_pet_id,
				'opponent_pet_id' => $duel->opponent_pet_id,
			]);

			$duelRepo->transitionDuelStatus($duel, 'accepted', 'cancelled');

			return null;
		}

		$winnerPet = WinnerPicker::pick($challengerPet, $opponentPet);
		$loserPet = $winnerPet->pet_id === $challengerPet->pet_id ? $opponentPet : $challengerPet;

		$options = $this->app->options();
		$winExp = max(0, (int) $options->sylphian_userpets_duel_win_exp);
		$loseStats = max(0, (int) $options->sylphian_userpets_duel_lose_stats);

		$db = $this->app->db();
		$db->beginTransaction();

		try
		{
			if (!$duelRepo->completeDuel($duel, $winnerPet->pet_id, $loserPet->pet_id))
			{
				$db->rollback();

				return null;
			}

			$duelRepo->incrementDuelCounters($winnerPet, $loserPet);

			if ($loseStats > 0)
			{
				$loserPet->hunger = max(0, $loserPet->hunger - $loseStats);
				$loserPet->happiness = max(0, $loserPet->happiness - $loseStats);
				$loserPet->sleepiness = max(0, $loserPet->sleepiness - $loseStats);
				$loserPet->save(true, false);
			}

			$awardedExp = $this->getPetsRepo()->awardExperienceToPet(
				$winnerPet,
				$winExp,
				false,
				XpSource::DUEL,
				self::XP_CONTENT_TYPE,
				$duelId
			) ? $winExp : 0;

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();

			// the entities still hold the rolled back values; a retry must start from the database
			$em = $this->app->em();
			$em->detachEntity($duel);
			$em->detachEntity($challengerPet);
			$em->detachEntity($opponentPet);

			throw $e;
		}

		Logger::notice('Duel completed', [
			'duel_id' => $duelId,
			'winner_pet_id' => $winnerPet->pet_id,
			'winner_user_id' => $winnerPet->user_id,
			'loser_pet_id' => $loserPet->pet_id,
			'loser_user_id' => $loserPet->user_id,
			'win_exp' => $awardedExp,
		]);

		return [
			'duel' => $duel,
			'winner' => $winnerPet,
			'loser' => $loserPet,
			'win_exp' => $awardedExp,
			'lose_stats' => $loseStats,
		];
	}

	/**
	 * Runs after the result is committed; a failure here must not re-run the duel.
	 *
	 * @param array{duel:UserPetsDuel,winner:UserPets,loser:UserPets,win_exp:int,lose_stats:int} $outcome
	 */
	protected function sendResultAlerts(array $outcome): void
	{
		try
		{
			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->app->repository('XF:UserAlert');

			/** @var User|null $winnerUser */
			$winnerUser = $outcome['winner']->User;
			/** @var User|null $loserUser */
			$loserUser = $outcome['loser']->User;

			// The opponent name is stored raw; the alert template shows its own "unknown user" fallback.
			$winnerName = $winnerUser ? DisplayName::decode($winnerUser->username) : '';
			$loserName = $loserUser ? DisplayName::decode($loserUser->username) : '';

			if ($winnerUser && !UserPetOptOut::isDisabledForUser($winnerUser))
			{
				$alertRepo->alertFromUser(
					$winnerUser,
					$winnerUser,
					'syl_userpet',
					$outcome['winner']->pet_id,
					'duel_win',
					[
						'opponent_name' => $loserName,
						'exp_gained' => $outcome['win_exp'],
						'duel_id' => $outcome['duel']->duel_id,
					]
				);
			}

			if ($loserUser && !UserPetOptOut::isDisabledForUser($loserUser))
			{
				$alertRepo->alertFromUser(
					$loserUser,
					$loserUser,
					'syl_userpet',
					$outcome['loser']->pet_id,
					'duel_loss',
					[
						'opponent_name' => $winnerName,
						'stats_lost' => $outcome['lose_stats'],
						'duel_id' => $outcome['duel']->duel_id,
					]
				);
			}
		}
		catch (\Throwable $e)
		{
			Logger::error('Failed to send duel result alerts', [
				'duel_id' => $outcome['duel']->duel_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * After the last failed attempt the duel would otherwise stay "accepted" forever and block
	 * both pets' history; nothing was awarded, so it is cancelled.
	 */
	protected function cancelUnresolvableDuel(int $duelId): void
	{
		try
		{
			/** @var UserPetsDuel|null $duel */
			$duel = $this->app->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId);
			if ($duel)
			{
				$this->getDuelRepo()->transitionDuelStatus($duel, 'accepted', 'cancelled');
			}
		}
		catch (\Throwable $e)
		{
			Logger::error('Could not cancel an unresolvable duel', [
				'duel_id' => $duelId,
				'exception' => $e->getMessage(),
			]);
		}
	}

	protected function getDuelRepo(): UserPetsDuelRepository
	{
		/** @var UserPetsDuelRepository */
		return $this->app->repository('Sylphian\UserPets:UserPetsDuel');
	}

	protected function getPetsRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository */
		return $this->app->repository('Sylphian\UserPets:UserPets');
	}

	public function getStatusMessage(): string
	{
		return (string) \XF::phrase('sylphian_userpets_job_duel_resolve_status', [
			'duel_id' => $this->data['duel_id'],
		]);
	}

	public function canCancel(): bool
	{
		return true;
	}

	public function canTriggerByChoice(): bool
	{
		return false;
	}
}

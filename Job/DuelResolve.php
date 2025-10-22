<?php

namespace Sylphian\UserPets\Job;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\DuelAlgorithms\DuelFactory;
use XF\Entity\User;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use XF\Repository\UserAlertRepository;

class DuelResolve extends AbstractJob
{
	/**
	 * Default data structure for this job
	 */
	protected $defaultData = [
		'duel_id' => 0,
		'attempts' => 0,
	];

	/**
	 * Run the job
	 *
	 * @param float $maxRunTime Maximum time to run this job
	 * @return JobResult
	 */
	public function run($maxRunTime): JobResult
	{
		if (empty($this->data['duel_id']))
		{
			return $this->complete();
		}

		$duelId = $this->data['duel_id'];

		try
		{
			/** @var UserPetsDuel $duel */
			$duel = $this->app->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId);
			if (!$duel || $duel->status !== 'accepted')
			{
				Logger::error('Duel job found invalid duel', [
					'duel_id' => $duelId,
					'status' => $duel ? $duel->status : 'null',
				]);
				return $this->complete();
			}

			$challengerPet = $duel->ChallengerPet;
			$opponentPet = $duel->OpponentPet;

			if (!$challengerPet || !$opponentPet)
			{
				Logger::error('Duel job found missing pets', [
					'duel_id' => $duelId,
					'challenger_pet_id' => $duel->challenger_pet_id,
					'opponent_pet_id' => $duel->opponent_pet_id,
				]);
				return $this->complete();
			}

			$algorithm = DuelFactory::getAlgorithm();
			$result = $algorithm->calculateWinner(
				$challengerPet->toArray(),
				$opponentPet->toArray()
			);

			$winnerPet = ($result['pet_id'] === $challengerPet->pet_id) ? $challengerPet : $opponentPet;
			$loserPet = ($winnerPet->pet_id === $challengerPet->pet_id) ? $opponentPet : $challengerPet;

			$winExp = \XF::options()->sylphian_userpets_duel_win_exp;
			$loseStats = \XF::options()->sylphian_userpets_duel_lose_stats;

			if ($winExp > 0)
			{
				/** @var UserPetsRepository $petsRepo */
				$petsRepo = $this->app->repository('Sylphian\UserPets:UserPets');
				$petsRepo->awardPetExperience($winnerPet->user_id, $winExp, false);
			}

			if ($loseStats > 0)
			{
				$loserPet->hunger = max(0, $loserPet->hunger - $loseStats);
				$loserPet->happiness = max(0, $loserPet->happiness - $loseStats);
				$loserPet->sleepiness = max(0, $loserPet->sleepiness - $loseStats);
				$loserPet->save();
			}

			/** @var UserPetsDuelRepository $duelRepo */
			$duelRepo = $this->app->repository('Sylphian\UserPets:UserPetsDuel');
			$duelRepo->updateDuelStatus($duelId, 'completed', [
				'winner_pet_id' => $winnerPet->pet_id,
				'loser_pet_id' => $loserPet->pet_id,
			]);

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->app->repository('XF:UserAlert');

			/** @var User $winnerUser */
			$winnerUser = $this->app->em()->find('XF:User', $winnerPet->user_id);
			/** @var User $loserUser */
			$loserUser = $this->app->em()->find('XF:User', $loserPet->user_id);

			$winnerUsername = $winnerUser ? $winnerUser->username : 'Unknown';
			$loserUsername = $loserUser ? $loserUser->username : 'Unknown';

			$alertRepo->alertFromUser(
				$winnerUser,
				$winnerUser,
				'syl_userpet',
				$winnerPet->pet_id,
				'duel_win',
				[
					'opponent_name' => $loserUsername,
					'exp_gained' => $winExp,
				]
			);

			$alertRepo->alertFromUser(
				$loserUser,
				$loserUser,
				'syl_userpet',
				$loserPet->pet_id,
				'duel_loss',
				[
					'opponent_name' => $winnerUsername,
					'stats_lost' => $loseStats,
				]
			);

			Logger::notice('Duel completed', [
				'duel_id' => $duelId,
				'winner_pet_id' => $winnerPet->pet_id,
				'winner_user_id' => $winnerPet->user_id,
				'loser_pet_id' => $loserPet->pet_id,
				'loser_user_id' => $loserPet->user_id,
			]);

			return $this->complete();
		}
		catch (\Exception $e)
		{
			if ($this->data['attempts'] >= 5)
			{
				Logger::error('Failed to resolve duel after multiple attempts', [
					'duel_id' => $duelId,
					'error' => $e->getMessage(),
				]);
				return $this->complete();
			}

			$this->data['attempts']++;
			return $this->resume();
		}
	}

	/**
	 * Get a descriptive status message for this job
	 *
	 * @return string
	 */
	public function getStatusMessage(): string
	{
		return sprintf(
			'Resolving duel (ID: %d)',
			$this->data['duel_id']
		);
	}

	/**
	 * Can this job be cancelled by user action?
	 *
	 * @return bool
	 */
	public function canCancel(): bool
	{
		return true;
	}

	/**
	 * Can this job be triggered manually by user choice?
	 *
	 * @return bool
	 */
	public function canTriggerByChoice(): bool
	{
		return false;
	}
}

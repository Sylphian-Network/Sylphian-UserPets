<?php

namespace Sylphian\UserPets\Service;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\DuelPermission;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\PrintableException;
use XF\Repository\UserAlertRepository;
use XF\Service\AbstractService;

/**
 * Duel lifecycle: pending -> accepted -> completed (by the DuelResolve job), or pending ->
 * declined / cancelled (by the challenger, or by the hourly cron when the request expires).
 * Every status change is a conditional update, so a duel can only leave a status once even when
 * two requests race.
 */
class UserPetDuel extends AbstractService
{
	public const string RESOLVE_JOB = 'Sylphian\UserPets:DuelResolve';

	/** An accepted duel normally resolves within seconds; after this it counts as stuck. */
	public const int STUCK_ACCEPTED_SECONDS = 600;

	public const int EXPIRE_BATCH_SIZE = 200;

	public const int REQUEUE_BATCH_SIZE = 100;

	/**
	 * Creates a new duel challenge between two pets
	 */
	public function createDuelChallenge(int $challengerPetId, int $opponentPetId): DuelChallengeResult
	{
		try
		{
			/** @var UserPets|null $challengerPet */
			$challengerPet = $this->em()->find('Sylphian\UserPets:UserPets', $challengerPetId, ['User']);
			/** @var UserPets|null $opponentPet */
			$opponentPet = $this->em()->find('Sylphian\UserPets:UserPets', $opponentPetId, ['User']);

			if (!$challengerPet || !$opponentPet)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_PETS_NOT_FOUND);
			}

			if ($challengerPetId === $opponentPetId || $challengerPet->user_id === $opponentPet->user_id)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_SAME_PET);
			}

			$challengerUser = $challengerPet->User;
			$opponentUser = $opponentPet->User;
			if (!$challengerUser || !$opponentUser)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN);
			}

			if (UserPetOptOut::isDisabledForUser($challengerUser) || !DuelPermission::canBeChallenged($opponentUser))
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_USER_DISABLED);
			}

			if (!DuelPermission::canDuel($challengerUser))
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_NOT_PERMITTED);
			}

			$cooldownPolicy = DuelPermission::getCooldownPolicy($challengerUser);
			$remainingTime = $this->getRemainingCooldown($challengerPet, $cooldownPolicy['cooldown_seconds']);
			if ($remainingTime > 0)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_ON_COOLDOWN, null, [
					'remaining_time' => $remainingTime,
				]);
			}

			$duelRepo = $this->getDuelRepo();

			$db = $this->db();
			$db->beginTransaction();

			try
			{
				$duelRepo->lockPets($challengerPetId, $opponentPetId);

				$existingDuel = $duelRepo->findExistingPendingDuelBetweenPets($challengerPetId, $opponentPetId);
				if ($existingDuel)
				{
					$db->rollback();

					return new DuelChallengeResult(DuelChallengeResult::ERROR_DUEL_ALREADY_EXISTS, $existingDuel);
				}

				$pendingOutgoingDuel = $duelRepo->findPendingOutgoingDuelForPet($challengerPetId);
				if ($pendingOutgoingDuel)
				{
					$db->rollback();

					return new DuelChallengeResult(DuelChallengeResult::ERROR_OUTGOING_DUEL_PENDING, $pendingOutgoingDuel);
				}

				$duel = $duelRepo->createDuelChallenge($challengerPetId, $opponentPetId);

				$db->commit();
			}
			catch (\Throwable $e)
			{
				$db->rollback();

				throw $e;
			}

			$duelRepo->sendDuelChallengeAlert($duel);

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (\Throwable $e)
		{
			return $this->unexpectedFailure('Failed to create duel challenge', $e, [
				'challenger_pet_id' => $challengerPetId,
				'opponent_pet_id' => $opponentPetId,
			]);
		}
	}

	/**
	 * The opponent accepts: pending -> accepted, the challenger's cooldown starts and the duel
	 * is queued for resolution, all in one transaction.
	 */
	public function acceptChallenge(int $duelId, int $userId): DuelChallengeResult
	{
		try
		{
			$duel = $this->getDuelForParticipant($duelId, $userId, 'opponent', 'sylphian_userpets_cannot_accept_other_duels', $failure);
			if (!$duel)
			{
				return $failure;
			}

			$challengerPet = $duel->ChallengerPet;
			$opponentPet = $duel->OpponentPet;
			if (!$challengerPet || !$opponentPet || !$challengerPet->User || !$opponentPet->User)
			{
				return $this->failure(DuelChallengeResult::ERROR_PETS_NOT_FOUND, 'sylphian_userpets_invalid_pet_data');
			}

			// Checked before the status changes so a refused acceptance leaves the duel pending.
			if (UserPetOptOut::isDisabledForUser($opponentPet->User) || !DuelPermission::canBeChallenged($challengerPet->User))
			{
				return $this->failure(DuelChallengeResult::ERROR_USER_DISABLED, 'sylphian_userpets_disabled_pet');
			}

			if (!DuelPermission::canDuel($opponentPet->User))
			{
				return $this->failure(DuelChallengeResult::ERROR_NOT_PERMITTED, 'do_not_have_permission');
			}

			$db = $this->db();
			$db->beginTransaction();

			try
			{
				if (!$this->getDuelRepo()->transitionDuelStatus($duel, 'pending', 'accepted'))
				{
					$db->rollback();

					return $this->failure(DuelChallengeResult::ERROR_UNKNOWN, 'sylphian_userpets_duel_no_longer_pending');
				}

				$challengerPet->last_duel_time = \XF::$time;
				$challengerPet->save(true, false);

				$this->queueResolveJob($duel->duel_id);

				$db->commit();
			}
			catch (\Throwable $e)
			{
				$db->rollback();

				$this->em()->detachEntity($duel);
				$this->em()->detachEntity($challengerPet);

				throw $e;
			}

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (\Throwable $e)
		{
			return $this->unexpectedFailure('Failed to accept duel challenge', $e, [
				'duel_id' => $duelId,
				'user_id' => $userId,
			]);
		}
	}

	/**
	 * The opponent declines: pending -> declined, then the challenger is alerted.
	 */
	public function rejectChallenge(int $duelId, int $userId): DuelChallengeResult
	{
		try
		{
			$duel = $this->getDuelForParticipant($duelId, $userId, 'opponent', 'sylphian_userpets_cannot_reject_other_duels', $failure);
			if (!$duel)
			{
				return $failure;
			}

			if (!$this->getDuelRepo()->transitionDuelStatus($duel, 'pending', 'declined'))
			{
				return $this->failure(DuelChallengeResult::ERROR_UNKNOWN, 'sylphian_userpets_duel_no_longer_pending');
			}

			$this->sendDeclinedAlert($duel);

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (\Throwable $e)
		{
			return $this->unexpectedFailure('Failed to reject duel challenge', $e, [
				'duel_id' => $duelId,
				'user_id' => $userId,
			]);
		}
	}

	/**
	 * The challenger withdraws: pending -> cancelled.
	 */
	public function cancelChallenge(int $duelId, int $userId): DuelChallengeResult
	{
		try
		{
			$duel = $this->getDuelForParticipant($duelId, $userId, 'challenger', 'sylphian_userpets_cannot_cancel_other_duels', $failure);
			if (!$duel)
			{
				return $failure;
			}

			if (!$this->getDuelRepo()->transitionDuelStatus($duel, 'pending', 'cancelled'))
			{
				return $this->failure(DuelChallengeResult::ERROR_UNKNOWN, 'sylphian_userpets_duel_no_longer_pending');
			}

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (\Throwable $e)
		{
			return $this->unexpectedFailure('Failed to cancel duel challenge', $e, [
				'duel_id' => $duelId,
				'user_id' => $userId,
			]);
		}
	}

	/**
	 * Withdraws pending requests older than the expiry option (pending -> cancelled) and tells
	 * each challenger. Works on the oldest requests first, at most $limit per call.
	 *
	 * @return int requests withdrawn by this call
	 */
	public function expirePendingChallenges(int $limit = self::EXPIRE_BATCH_SIZE): int
	{
		$duelRepo = $this->getDuelRepo();

		$days = $duelRepo->getRequestExpiryDays();
		if ($days <= 0 || $limit <= 0)
		{
			return 0;
		}

		$expired = 0;

		foreach ($duelRepo->findExpiredPendingDuels(\XF::$time - $days * 86400, $limit) AS $duel)
		{
			if (!$duelRepo->transitionDuelStatus($duel, 'pending', 'cancelled'))
			{
				continue;
			}

			$expired++;
			$this->sendExpiredAlert($duel);
		}

		return $expired;
	}

	/**
	 * Queues the resolution again for accepted duels whose job was lost.
	 *
	 * @return int duels queued
	 */
	public function requeueStuckDuels(int $limit = self::REQUEUE_BATCH_SIZE): int
	{
		$duelIds = $this->getDuelRepo()->getStuckAcceptedDuelIds(\XF::$time - self::STUCK_ACCEPTED_SECONDS, $limit);

		foreach ($duelIds AS $duelId)
		{
			$this->queueResolveJob($duelId);
		}

		return count($duelIds);
	}

	protected function queueResolveJob(int $duelId): void
	{
		$this->app->jobManager()->enqueueUnique(
			$this->getDuelRepo()->getResolveJobKey($duelId),
			self::RESOLVE_JOB,
			['duel_id' => $duelId],
			false
		);
	}

	public function getRemainingCooldown(UserPets $challengerPet, int $cooldownSeconds): int
	{
		$lastDuelTime = $challengerPet->last_duel_time;
		if ($cooldownSeconds <= 0 || $lastDuelTime <= 0)
		{
			return 0;
		}

		return max(0, ($lastDuelTime + $cooldownSeconds) - \XF::$time);
	}

	/**
	 * Loads a pending duel in which the user's pet has the given role.
	 *
	 * @param 'challenger'|'opponent' $role
	 */
	protected function getDuelForParticipant(
		int $duelId,
		int $userId,
		string $role,
		string $notParticipantPhrase,
		?DuelChallengeResult &$failure = null
	): ?UserPetsDuel
	{
		/** @var UserPetsDuel|null $duel */
		$duel = $this->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId, ['ChallengerPet.User', 'OpponentPet.User']);
		if (!$duel)
		{
			$failure = $this->failure(DuelChallengeResult::ERROR_UNKNOWN, 'sylphian_userpets_duel_not_found');
			return null;
		}

		$petIds = $this->getDuelRepo()->getPetIdsForUser($userId);
		$rolePetId = $role === 'challenger' ? $duel->challenger_pet_id : $duel->opponent_pet_id;
		if (!in_array($rolePetId, $petIds, true))
		{
			$failure = $this->failure(DuelChallengeResult::ERROR_UNKNOWN, $notParticipantPhrase);
			return null;
		}

		if ($duel->status !== 'pending')
		{
			$failure = $this->failure(DuelChallengeResult::ERROR_UNKNOWN, 'sylphian_userpets_duel_no_longer_pending');
			return null;
		}

		return $duel;
	}

	protected function sendDeclinedAlert(UserPetsDuel $duel): void
	{
		try
		{
			$challengerUser = $duel->ChallengerPet?->User;
			$opponentUser = $duel->OpponentPet?->User;
			if (!$challengerUser || !$opponentUser)
			{
				return;
			}

			if (UserPetOptOut::isDisabledForUser($challengerUser) || UserPetOptOut::isDisabledForUser($opponentUser))
			{
				return;
			}

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->repository('XF:UserAlert');
			$alertRepo->alertFromUser(
				$challengerUser,
				$opponentUser,
				'syl_userpet',
				$duel->challenger_pet_id,
				'duel_declined',
				[
					'opponent_name' => DisplayName::decode($opponentUser->username),
				]
			);
		}
		catch (\Throwable $e)
		{
			Logger::error('Failed to send duel declined alert', [
				'exception' => $e->getMessage(),
				'duel_id' => $duel->duel_id,
			]);
		}
	}

	protected function sendExpiredAlert(UserPetsDuel $duel): void
	{
		try
		{
			$challengerUser = $duel->ChallengerPet?->User;
			if (!$challengerUser || UserPetOptOut::isDisabledForUser($challengerUser))
			{
				return;
			}

			$opponentUser = $duel->OpponentPet?->User;

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->repository('XF:UserAlert');
			$alertRepo->alertFromUser(
				$challengerUser,
				$opponentUser,
				'syl_userpet',
				$duel->challenger_pet_id,
				'duel_expired',
				[
					'opponent_name' => $opponentUser ? DisplayName::decode($opponentUser->username) : '',
					'duel_id' => $duel->duel_id,
				]
			);
		}
		catch (\Throwable $e)
		{
			Logger::error('Failed to send duel expired alert', [
				'exception' => $e->getMessage(),
				'duel_id' => $duel->duel_id,
			]);
		}
	}

	protected function failure(string $status, string $phraseName): DuelChallengeResult
	{
		return new DuelChallengeResult($status, null, [
			'error' => \XF::phrase($phraseName),
		]);
	}

	/**
	 * Logs the real cause; members only see it when the exception is meant to be shown to them.
	 */
	protected function unexpectedFailure(string $logMessage, \Throwable $e, array $context): DuelChallengeResult
	{
		Logger::error($logMessage, $context + [
			'exception' => $e->getMessage(),
			'exception_class' => get_class($e),
		]);

		$error = $e instanceof PrintableException
			? $e->getMessage()
			: \XF::phrase('sylphian_userpets_unknown_error');

		return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
			'error' => $error,
		]);
	}

	protected function getDuelRepo(): UserPetsDuelRepository
	{
		/** @var UserPetsDuelRepository */
		return $this->repository('Sylphian\UserPets:UserPetsDuel');
	}
}

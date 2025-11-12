<?php

namespace Sylphian\UserPets\Service;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\Entity\User;
use XF\PrintableException;
use XF\Repository\UserAlertRepository;

class UserPetDuel
{
	/**
	 * Creates a new duel challenge between two pets
	 *
	 * @param int $challengerPetId The challenger pet ID
	 * @param int $opponentPetId The opponent pet ID
	 * @return DuelChallengeResult Result object containing status and duel if successful
	 */
	public function createDuelChallenge(int $challengerPetId, int $opponentPetId): DuelChallengeResult
	{
		$app = \XF::app();

		try
		{
			/** @var UserPets $challengerPet */
			$challengerPet = $app->em()->find('Sylphian\UserPets:UserPets', $challengerPetId);
			/** @var UserPets $opponentPet */
			$opponentPet = $app->em()->find('Sylphian\UserPets:UserPets', $opponentPetId);

			if (!$challengerPet || !$opponentPet)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_PETS_NOT_FOUND);
			}

			if ($challengerPetId === $opponentPetId)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_SAME_PET);
			}

			if (UserPetOptOut::isDisabledByUserId($challengerPet->user_id) || UserPetOptOut::isDisabledByUserId($opponentPet->user_id))
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_USER_DISABLED);
			}

			$lastDuelTime = $challengerPet->last_duel_time;
			$currentTime = \XF::$time;
			$cooldownTimeHours = \XF::options()->sylphian_userpets_duel_cooldown_time;
			$cooldownTimeSeconds = $cooldownTimeHours * 3600;

			if ($lastDuelTime > 0 && ($lastDuelTime + $cooldownTimeSeconds) > $currentTime)
			{
				$remainingTime = ($lastDuelTime + $cooldownTimeSeconds) - $currentTime;

				return new DuelChallengeResult(
					DuelChallengeResult::ERROR_ON_COOLDOWN,
					null,
					[
						'remaining_time' => $remainingTime,
					]
				);
			}

			/** @var UserPetsDuelRepository $duelRepo */
			$duelRepo = $app->repository('Sylphian\UserPets:UserPetsDuel');

			$existingDuel = $duelRepo->findExistingPendingDuelBetweenPets($challengerPetId, $opponentPetId);
			if ($existingDuel)
			{
				return new DuelChallengeResult(
					DuelChallengeResult::ERROR_DUEL_ALREADY_EXISTS,
					$existingDuel
				);
			}

			$duel = $duelRepo->createDuelChallenge($challengerPetId, $opponentPetId);

			$duelRepo->sendDuelChallengeAlert($duel);

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (PrintableException|\Exception $e)
		{
			Logger::error('Failed to create duel challenge', [
				'exception' => $e->getMessage(),
				'challenger_pet_id' => $challengerPetId,
				'opponent_pet_id' => $opponentPetId,
			]);

			return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Accept a duel challenge
	 *
	 * @param int $duelId The duel ID
	 * @param int $userId The user ID of the person accepting
	 * @return DuelChallengeResult Result object containing status and duel if successful
	 */
	public function acceptChallenge(int $duelId, int $userId): DuelChallengeResult
	{
		$app = \XF::app();

		try
		{
			/** @var UserPetsDuel $duel */
			$duel = $app->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId);
			if (!$duel)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Duel not found',
				]);
			}

			/** @var UserPets $pet */
			$pet = $app->finder('Sylphian\UserPets:UserPets')
				->where('user_id', $userId)
				->fetchOne();

			if (!$pet || $duel->opponent_pet_id != $pet->pet_id)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Cannot accept others duels',
				]);
			}

			if ($duel->status != 'pending')
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Duel no longer pending',
				]);
			}

			/** @var UserPetsDuelRepository $duelRepo */
			$duelRepo = $app->repository('Sylphian\UserPets:UserPetsDuel');
			$duel = $duelRepo->updateDuelStatus($duelId, 'accepted');

			$challengerPet = $duel->ChallengerPet;

			$challengerPet->last_duel_time = \XF::$time;

			$challengerPet->save();

			$jobManager = $app->jobManager();
			$jobManager->enqueue(
				'Sylphian\UserPets:DuelResolve',
				[
					'duel_id' => $duel->duel_id,
				]
			);

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (PrintableException|\Exception $e)
		{
			Logger::error('Failed to accept duel challenge', [
				'exception' => $e->getMessage(),
				'duel_id' => $duelId,
				'user_id' => $userId,
			]);

			return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Reject a duel challenge
	 *
	 * @param int $duelId The duel ID
	 * @param int $userId The user ID of the person rejecting
	 * @return DuelChallengeResult Result object containing status and duel if successful
	 */
	public function rejectChallenge(int $duelId, int $userId): DuelChallengeResult
	{
		$app = \XF::app();

		try
		{
			/** @var UserPetsDuel $duel */
			$duel = $app->em()->find('Sylphian\UserPets:UserPetsDuel', $duelId);
			if (!$duel)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Duel not found',
				]);
			}

			/** @var UserPets $pet */
			$pet = $app->finder('Sylphian\UserPets:UserPets')
				->where('user_id', $userId)
				->fetchOne();

			if (!$pet || $duel->opponent_pet_id != $pet->pet_id)
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Cannot reject others duels',
				]);
			}

			if ($duel->status != 'pending')
			{
				return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
					'error' => 'Duel no longer pending',
				]);
			}

			/** @var UserPetsDuelRepository $duelRepo */
			$duelRepo = $app->repository('Sylphian\UserPets:UserPetsDuel');
			$duel = $duelRepo->updateDuelStatus($duelId, 'declined');

			$challengerPet = $duel->ChallengerPet;
			$opponentPet = $duel->OpponentPet;

			if ($challengerPet && $opponentPet)
			{
				/** @var User $challengerUser */
				$challengerUser = $app->em()->find('XF:User', $challengerPet->user_id);

				/** @var User $opponentUser */
				$opponentUser = $app->em()->find('XF:User', $opponentPet->user_id);

				if ($challengerUser && $opponentUser)
				{
					/** @var UserAlertRepository $alertRepo */
					$alertRepo = $app->repository('XF:UserAlert');

					$alertRepo->alertFromUser(
						$challengerUser,
						$opponentUser,
						'syl_userpet',
						$challengerPet->pet_id,
						'duel_declined',
						[
							'opponent_name' => $opponentUser->username,
						]
					);
				}
			}

			return new DuelChallengeResult(DuelChallengeResult::SUCCESS, $duel);
		}
		catch (PrintableException|\Exception $e)
		{
			Logger::error('Failed to reject duel challenge', [
				'exception' => $e->getMessage(),
				'duel_id' => $duelId,
				'user_id' => $userId,
			]);

			return new DuelChallengeResult(DuelChallengeResult::ERROR_UNKNOWN, null, [
				'error' => $e->getMessage(),
			]);
		}
	}
}

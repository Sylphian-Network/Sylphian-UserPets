<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\UserPetOptOut;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use XF\Repository\UserAlertRepository;

class UserPetsDuelRepository extends Repository
{
	/**
	 * Count completed duel wins for a user (based on their pets winning).
	 */
	public function getUserDuelWins(int $userId): int
	{
		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('status', 'completed')
			->where('winner_pet_id', '>', 0)
			->where('WinnerPet.user_id', $userId)
			->total();
	}

	/**
	 * Count completed duel losses for a user (based on their pets losing).
	 */
	public function getUserDuelLosses(int $userId): int
	{
		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('status', 'completed')
			->where('loser_pet_id', '>', 0)
			->where('LoserPet.user_id', $userId)
			->total();
	}

	/**
	 * Convenience method that returns [wins, losses] for a user.
	 */
	public function getUserWinLossStats(int $userId): array
	{
		$wins = $this->getUserDuelWins($userId);
		$losses = $this->getUserDuelLosses($userId);
		return ['wins' => $wins, 'losses' => $losses];
	}

	/**
	 * Top N users by wins (with losses), used by leaderboard.
	 * Returns an ordered array of rows: [ ['user_id' => int, 'wins' => int, 'losses' => int], ... ]
	 */
	public function getTopUsersByWins(int $limit): array
	{
		$limit = max(0, $limit);
		if ($limit === 0)
		{
			return [];
		}

		$em = $this->em;
		$duelTable = $em->getEntityStructure('Sylphian\UserPets:UserPetsDuel')->table;
		$petsTable = $em->getEntityStructure('Sylphian\UserPets:UserPets')->table;
		$db = $this->db();

		$sql = "
			SELECT t.user_id,
			       SUM(t.wins)   AS wins,
			       SUM(t.losses) AS losses
			FROM (
				SELECT p.user_id, 1 AS wins, 0 AS losses
				FROM {$duelTable} d
				INNER JOIN {$petsTable} p ON p.pet_id = d.winner_pet_id
				WHERE d.status = 'completed'

				UNION ALL

				SELECT p.user_id, 0 AS wins, 1 AS losses
				FROM {$duelTable} d
				INNER JOIN {$petsTable} p ON p.pet_id = d.loser_pet_id
				WHERE d.status = 'completed'
			) AS t
			GROUP BY t.user_id
			HAVING SUM(t.wins) > 0
			ORDER BY wins DESC, t.user_id
			LIMIT {$limit}
		";

		return $db->fetchAll($sql);
	}

	/**
	 * Replaces the inline pending-duel lookup in the service.
	 */
	public function findExistingPendingDuelBetweenPets(int $petAId, int $petBId): ?UserPetsDuel
	{
		/** @var UserPetsDuel|null */
		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->whereOr([
				['challenger_pet_id', '=', $petAId, 'opponent_pet_id', '=', $petBId],
				['challenger_pet_id', '=', $petBId, 'opponent_pet_id', '=', $petAId],
			])
			->where('status', 'pending')
			->fetchOne();
	}

	/**
	 * Create a new duel challenge
	 *
	 * @param int $challengerPetId The challenger pet ID
	 * @param int $opponentPetId The opponent pet ID
	 * @return UserPetsDuel The created duel entity
	 * @throws PrintableException If the duel could not be created
	 */
	public function createDuelChallenge(int $challengerPetId, int $opponentPetId): UserPetsDuel
	{
		/** @var UserPetsDuel $duel */
		$duel = $this->em->create('Sylphian\UserPets:UserPetsDuel');
		$duel->challenger_pet_id = $challengerPetId;
		$duel->opponent_pet_id = $opponentPetId;
		$duel->status = 'pending';
		$duel->created_at = \XF::$time;
		$duel->save();

		return $duel;
	}

	/**
	 * Update a duel's status
	 *
	 * @param int $duelId The duel ID
	 * @param string $status The new status ('accepted', 'declined', 'completed')
	 * @param array $additionalData Additional data to update (e.g., winner_pet_id, loser_pet_id)
	 * @return UserPetsDuel|null The updated duel or null if not found
	 * @throws PrintableException If the duel could not be updated
	 */
	public function updateDuelStatus(int $duelId, string $status, array $additionalData = []): ?UserPetsDuel
	{
		/** @var UserPetsDuel $duel */
		$duel = $this->em->find('Sylphian\UserPets:UserPetsDuel', $duelId);

		if (!$duel)
		{
			return null;
		}

		$duel->status = $status;

		if ($status === 'completed')
		{
			$duel->completed_at = \XF::$time;

			if (isset($additionalData['winner_pet_id']))
			{
				$duel->winner_pet_id = $additionalData['winner_pet_id'];
			}

			if (isset($additionalData['loser_pet_id']))
			{
				$duel->loser_pet_id = $additionalData['loser_pet_id'];
			}
		}

		$duel->save();

		return $duel;
	}

	/**
	 * Send a duel challenge alert to a user
	 *
	 * @param UserPetsDuel $duel The duel entity
	 * @return bool True if the alert was sent, false otherwise
	 */
	public function sendDuelChallengeAlert(UserPetsDuel $duel): bool
	{
		try
		{
			$app = \XF::app();

			$challengerPet = $duel->ChallengerPet;
			$opponentPet = $duel->OpponentPet;

			if (!$challengerPet || !$opponentPet)
			{
				return false;
			}

			/** @var User $challengerUser */
			$challengerUser = $app->em()->find('XF:User', $challengerPet->user_id);

			/** @var User $opponentUser */
			$opponentUser = $app->em()->find('XF:User', $opponentPet->user_id);

			if (!$challengerUser || !$opponentUser)
			{
				return false;
			}

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $app->repository('XF:UserAlert');

			if (!UserPetOptOut::isDisabledByUserId($opponentUser->user_id) && !UserPetOptOut::isDisabledByUserId($challengerUser->user_id))
			{
				$alertRepo->alertFromUser(
					$opponentUser,
					$challengerUser,
					'syl_userpet',
					$opponentPet->pet_id,
					'duel_challenge',
					[
						'challenger_name' => $challengerPet->User->username,
						'duel_id' => $duel->duel_id,
					]
				);
			}

			return true;
		}
		catch (\Exception $e)
		{
			Logger::error('Failed to send duel challenge alert', [
				'exception' => $e->getMessage(),
				'duel_id' => $duel->duel_id,
			]);

			return false;
		}
	}
}

<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\UserPetOptOut;
use XF\Entity\User;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use XF\Repository\UserAlertRepository;

/**
 * A pet takes part in a duel either as challenger or as opponent. Lookups by pet go through the
 * two columns separately (UNION ALL, each branch on its own index) instead of an OR. Win and loss
 * totals live on the pet row (duel_wins, duel_losses): DuelResolve adds to them in the
 * transaction that completes a duel and recountDuelCounters() rebuilds them from the duels.
 */
class UserPetsDuelRepository extends Repository
{
	public const string TABLE = 'xf_user_pets_duels';

	public const string PETS_TABLE = 'xf_user_pets';

	/** Unique job key prefix of the DuelResolve job, followed by the duel id. */
	public const string RESOLVE_JOB_KEY_PREFIX = 'sylUserPetsDuel';

	public const array HISTORY_FILTERS = ['all', 'wins', 'losses', 'declined', 'cancelled'];

	public function getIncomingPendingDuelFinderForPet(int $petId): Finder
	{
		return $this->getDuelFinderWithPets()
			->where('opponent_pet_id', $petId)
			->where('status', 'pending')
			->order('created_at', 'DESC');
	}

	public function getOutgoingPendingDuelFinderForPet(int $petId): Finder
	{
		return $this->getDuelFinderWithPets()
			->where('challenger_pet_id', $petId)
			->where('status', 'pending')
			->order('created_at', 'DESC');
	}

	public function getAcceptedDuelsForPet(int $petId): AbstractCollection
	{
		return $this->fetchDuelsByIds($this->getDuelIdsForPet($petId, ['accepted']));
	}

	public function getDuelHistoryForPet(int $petId, string $filter = 'all', int $page = 1, int $perPage = 10): AbstractCollection
	{
		$page = max(1, $page);
		$perPage = max(1, $perPage);
		$offset = ($page - 1) * $perPage;

		switch ($this->normalizeHistoryFilter($filter))
		{
			case 'wins':
				return $this->getDuelFinderWithPets()
					->where('winner_pet_id', $petId)
					->where('status', 'completed')
					->order('created_at', 'DESC')
					->order('duel_id', 'DESC')
					->limit($perPage, $offset)
					->fetch();

			case 'losses':
				return $this->getDuelFinderWithPets()
					->where('loser_pet_id', $petId)
					->where('status', 'completed')
					->order('created_at', 'DESC')
					->order('duel_id', 'DESC')
					->limit($perPage, $offset)
					->fetch();

			case 'declined':
				$ids = $this->getDuelIdsForPet($petId, ['declined'], $perPage, $offset);
				break;

			case 'cancelled':
				$ids = $this->getDuelIdsForPet($petId, ['cancelled'], $perPage, $offset);
				break;

			default:
				$ids = $this->getDuelIdsForPet($petId, [], $perPage, $offset);
		}

		return $this->fetchDuelsByIds($ids);
	}

	public function countDuelHistoryForPet(int $petId, string $filter = 'all'): int
	{
		switch ($this->normalizeHistoryFilter($filter))
		{
			case 'wins':
				return $this->countDuelWinsForPets([$petId]);

			case 'losses':
				return $this->countDuelLossesForPets([$petId]);

			case 'declined':
				return $this->countDuelsForPet($petId, ['declined']);

			case 'cancelled':
				return $this->countDuelsForPet($petId, ['cancelled']);

			default:
				return $this->countDuelsForPet($petId);
		}
	}

	public function normalizeHistoryFilter(string $filter): string
	{
		return in_array($filter, self::HISTORY_FILTERS, true) ? $filter : 'all';
	}

	/**
	 * Duel ids of a pet, newest first.
	 *
	 * @param string[] $statuses empty for every status
	 * @return int[]
	 */
	public function getDuelIdsForPet(int $petId, array $statuses = [], int $limit = 0, int $offset = 0): array
	{
		$db = $this->db();
		$statusSql = $statuses ? ' AND status IN (' . $db->quote($statuses) . ')' : '';

		$branchLimit = '';
		if ($limit > 0)
		{
			// each branch only needs the rows that can still reach the requested page
			$branchLimit = ' ORDER BY created_at DESC, duel_id DESC LIMIT ' . (max(0, $offset) + $limit);
		}

		$sql = '
			SELECT duel_id
			FROM (
				(
					SELECT duel_id, created_at
					FROM ' . self::TABLE . '
					WHERE challenger_pet_id = ?' . $statusSql . $branchLimit . '
				)
				UNION ALL
				(
					SELECT duel_id, created_at
					FROM ' . self::TABLE . '
					WHERE opponent_pet_id = ? AND challenger_pet_id <> ?' . $statusSql . $branchLimit . '
				)
			) AS pet_duels
			ORDER BY created_at DESC, duel_id DESC
		';

		if ($limit > 0)
		{
			$sql = $db->limit($sql, $limit, $offset);
		}

		return array_map('intval', $db->fetchAllColumn($sql, [$petId, $petId, $petId]));
	}

	/**
	 * @param string[] $statuses empty for every status
	 */
	public function countDuelsForPet(int $petId, array $statuses = []): int
	{
		$db = $this->db();
		$statusSql = $statuses ? ' AND status IN (' . $db->quote($statuses) . ')' : '';

		return (int) $db->fetchOne(
			'
				SELECT
					(SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE challenger_pet_id = ?' . $statusSql . ')
					+ (SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE opponent_pet_id = ? AND challenger_pet_id <> ?' . $statusSql . ')
			',
			[$petId, $petId, $petId]
		);
	}

	/**
	 * Every counter of the duel overview in one query.
	 *
	 * @return array{wins:int,losses:int,pending_incoming:int,pending_outgoing:int,active:int,declined:int,cancelled:int,total:int}
	 */
	public function getPetDuelOverview(int $petId): array
	{
		$overview = $this->getEmptyOverview();

		$rows = $this->db()->fetchAll(
			'
				SELECT status, direction,
					COUNT(*) AS duel_count,
					SUM(winner_pet_id = ?) AS wins,
					SUM(loser_pet_id = ?) AS losses
				FROM (
					SELECT status, winner_pet_id, loser_pet_id, \'out\' AS direction
					FROM ' . self::TABLE . '
					WHERE challenger_pet_id = ?
					UNION ALL
					SELECT status, winner_pet_id, loser_pet_id, \'in\' AS direction
					FROM ' . self::TABLE . '
					WHERE opponent_pet_id = ? AND challenger_pet_id <> ?
				) AS pet_duels
				GROUP BY status, direction
			',
			[$petId, $petId, $petId, $petId, $petId]
		);

		foreach ($rows AS $row)
		{
			$count = (int) $row['duel_count'];
			$overview['total'] += $count;

			switch ($row['status'])
			{
				case 'completed':
					$overview['wins'] += (int) $row['wins'];
					$overview['losses'] += (int) $row['losses'];
					break;

				case 'pending':
					$overview[$row['direction'] === 'in' ? 'pending_incoming' : 'pending_outgoing'] += $count;
					break;

				case 'accepted':
					$overview['active'] += $count;
					break;

				case 'declined':
					$overview['declined'] += $count;
					break;

				case 'cancelled':
					$overview['cancelled'] += $count;
					break;
			}
		}

		return $overview;
	}

	public function getUserDuelOverview(int $userId): array
	{
		$petIds = $this->getPetIdsForUser($userId);
		if (!$petIds)
		{
			return $this->getEmptyOverview();
		}

		return $this->getPetDuelOverview(reset($petIds));
	}

	/**
	 * @return int[]
	 */
	public function getPetIdsForUser(int $userId): array
	{
		if (!$userId)
		{
			return [];
		}

		$rows = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->order('pet_id')
			->fetchColumns('pet_id');

		return array_map('intval', array_column($rows, 'pet_id'));
	}

	/**
	 * Completed duel wins of a user's pet, from the counter on the pet row.
	 */
	public function getUserDuelWins(int $userId): int
	{
		return $this->getUserWinLossStats($userId)['wins'];
	}

	/**
	 * Completed duel losses of a user's pet, from the counter on the pet row.
	 */
	public function getUserDuelLosses(int $userId): int
	{
		return $this->getUserWinLossStats($userId)['losses'];
	}

	/**
	 * @return array{wins:int,losses:int}
	 */
	public function getUserWinLossStats(int $userId): array
	{
		if ($userId <= 0)
		{
			return ['wins' => 0, 'losses' => 0];
		}

		$row = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->fetchColumns('duel_wins', 'duel_losses');
		$row = reset($row) ?: [];

		return [
			'wins' => (int) ($row['duel_wins'] ?? 0),
			'losses' => (int) ($row['duel_losses'] ?? 0),
		];
	}

	/**
	 * Exact count over the duels; used for history paging and the counter rebuild.
	 *
	 * @param int[] $petIds
	 */
	public function countDuelWinsForPets(array $petIds): int
	{
		if (!$petIds)
		{
			return 0;
		}

		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('winner_pet_id', $petIds)
			->where('status', 'completed')
			->total();
	}

	/**
	 * @param int[] $petIds
	 */
	public function countDuelLossesForPets(array $petIds): int
	{
		if (!$petIds)
		{
			return 0;
		}

		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('loser_pet_id', $petIds)
			->where('status', 'completed')
			->total();
	}

	/**
	 * Top N users by wins (with losses), best first.
	 *
	 * @return array<int,array{user_id:int,wins:int,losses:int}>
	 */
	public function getTopUsersByWins(int $limit): array
	{
		return $this->getTopUsersByCounter('duel_wins', $limit);
	}

	/**
	 * Top N users by losses (with wins), most losses first.
	 *
	 * @return array<int,array{user_id:int,wins:int,losses:int}>
	 */
	public function getTopUsersByLosses(int $limit): array
	{
		return $this->getTopUsersByCounter('duel_losses', $limit);
	}

	/**
	 * A user has at most one pet, so ranking pets ranks their owners. Ties go to the newer pet:
	 * with both directions descending the counter index alone gives the order, without a sort
	 * over every pet that has a result. Pets without an owner row are not ranked.
	 *
	 * @param 'duel_wins'|'duel_losses' $column
	 * @return array<int,array{user_id:int,wins:int,losses:int}>
	 */
	protected function getTopUsersByCounter(string $column, int $limit): array
	{
		$limit = max(0, $limit);
		if ($limit === 0)
		{
			return [];
		}

		$rows = $this->finder('Sylphian\UserPets:UserPets')
			->with('User', true)
			->where($column, '>', 0)
			->order($column, 'DESC')
			->order('pet_id', 'DESC')
			->limit($limit)
			->fetchColumns('user_id', 'duel_wins', 'duel_losses');

		return array_values(array_map(fn (array $row) => [
			'user_id' => (int) $row['user_id'],
			'wins' => (int) $row['duel_wins'],
			'losses' => (int) $row['duel_losses'],
		], $rows));
	}

	/**
	 * Called inside the transaction that completed the duel. The increments are done in SQL so
	 * two duels of the same pet finishing at once both count; the entities are updated to match
	 * so a later save of them does not see a change.
	 */
	public function incrementDuelCounters(UserPets $winnerPet, UserPets $loserPet): void
	{
		$db = $this->db();

		$db->query('UPDATE ' . self::PETS_TABLE . ' SET duel_wins = duel_wins + 1 WHERE pet_id = ?', $winnerPet->pet_id);
		$db->query('UPDATE ' . self::PETS_TABLE . ' SET duel_losses = duel_losses + 1 WHERE pet_id = ?', $loserPet->pet_id);

		$winnerPet->setAsSaved('duel_wins', $winnerPet->duel_wins + 1);
		$loserPet->setAsSaved('duel_losses', $loserPet->duel_losses + 1);
	}

	/**
	 * Sets duel_wins and duel_losses of the given pets to the number of completed duels they won
	 * and lost. Safe to run any number of times.
	 *
	 * @param int[] $petIds
	 */
	public function recountDuelCounters(array $petIds): void
	{
		$petIds = array_values(array_unique(array_filter(array_map('intval', $petIds), fn (int $id) => $id > 0)));
		if (!$petIds)
		{
			return;
		}

		$db = $this->db();
		$db->query(
			'
				UPDATE ' . self::PETS_TABLE . ' AS pet
				SET pet.duel_wins = (
						SELECT COUNT(*)
						FROM ' . self::TABLE . ' AS duel
						WHERE duel.winner_pet_id = pet.pet_id
							AND duel.status = ?
					),
					pet.duel_losses = (
						SELECT COUNT(*)
						FROM ' . self::TABLE . ' AS duel
						WHERE duel.loser_pet_id = pet.pet_id
							AND duel.status = ?
					)
				WHERE pet.pet_id IN (' . $db->quote($petIds) . ')
			',
			['completed', 'completed']
		);
	}

	/**
	 * Days after which an unanswered request is withdrawn; 0 keeps requests until answered.
	 */
	public function getRequestExpiryDays(): int
	{
		return max(0, (int) (\XF::options()->sylphian_userpets_duel_request_expiry_days ?? 0));
	}

	/**
	 * When a pending request is withdrawn, or 0 when it never is (no expiry or not pending).
	 * The hourly cron does the withdrawing, so it can happen up to an hour later.
	 */
	public function getRequestExpiryDate(UserPetsDuel $duel): int
	{
		$days = $this->getRequestExpiryDays();
		if ($days <= 0 || $duel->status !== 'pending')
		{
			return 0;
		}

		return $duel->created_at + $days * 86400;
	}

	/**
	 * Pending requests created before the cut-off, oldest first, with both pets and owners.
	 *
	 * @return AbstractCollection<UserPetsDuel>
	 */
	public function findExpiredPendingDuels(int $createdBefore, int $limit): AbstractCollection
	{
		if ($createdBefore <= 0 || $limit <= 0)
		{
			return $this->em->getEmptyCollection();
		}

		return $this->getDuelFinderWithPets()
			->where('status', 'pending')
			->where('created_at', '<', $createdBefore)
			->order('duel_id')
			->limit($limit)
			->fetch();
	}

	/**
	 * Accepted duels that should have been resolved by now but have no DuelResolve job queued.
	 * There is no accepted date: accepting sets the challenger's last_duel_time in the same
	 * transaction, so the later of that and the creation date stands for it. A duel whose job
	 * is still queued (or running) is left to that job; queueing it again would reset its
	 * attempt counter and release its run lock.
	 *
	 * @return int[]
	 */
	public function getStuckAcceptedDuelIds(int $acceptedBefore, int $limit): array
	{
		if ($acceptedBefore <= 0 || $limit <= 0)
		{
			return [];
		}

		$db = $this->db();

		$duelIds = array_map('intval', $db->fetchAllColumn(
			$db->limit(
				'
					SELECT duel.duel_id
					FROM ' . self::TABLE . ' AS duel
					LEFT JOIN ' . self::PETS_TABLE . ' AS challenger ON (challenger.pet_id = duel.challenger_pet_id)
					WHERE duel.status = ?
						AND GREATEST(duel.created_at, IFNULL(challenger.last_duel_time, 0)) < ?
					ORDER BY duel.duel_id
				',
				$limit
			),
			['accepted', $acceptedBefore]
		));
		if (!$duelIds)
		{
			return [];
		}

		$keys = array_map(fn (int $duelId) => $this->getResolveJobKey($duelId), $duelIds);
		$queued = array_flip($db->fetchAllColumn(
			'SELECT unique_key FROM xf_job WHERE unique_key IN (' . $db->quote($keys) . ')'
		));

		return array_values(array_filter(
			$duelIds,
			fn (int $duelId) => !isset($queued[$this->getResolveJobKey($duelId)])
		));
	}

	public function getResolveJobKey(int $duelId): string
	{
		return self::RESOLVE_JOB_KEY_PREFIX . $duelId;
	}

	public function findExistingPendingDuelBetweenPets(int $petAId, int $petBId): ?UserPetsDuel
	{
		$duelId = $this->db()->fetchOne(
			'
				SELECT duel_id
				FROM ' . self::TABLE . '
				WHERE status = ?
					AND (
						(challenger_pet_id = ? AND opponent_pet_id = ?)
						OR
						(challenger_pet_id = ? AND opponent_pet_id = ?)
					)
				ORDER BY duel_id DESC
				LIMIT 1
			',
			['pending', $petAId, $petBId, $petBId, $petAId]
		);

		if (!$duelId)
		{
			return null;
		}

		/** @var UserPetsDuel|null */
		return $this->em->find('Sylphian\UserPets:UserPetsDuel', (int) $duelId);
	}

	public function findPendingOutgoingDuelForPet(int $petId): ?UserPetsDuel
	{
		/** @var UserPetsDuel|null */
		return $this->getOutgoingPendingDuelFinderForPet($petId)->fetchOne();
	}

	/**
	 * Row-locks the given pets in a stable order (inside a transaction) so concurrent challenges
	 * involving the same pets are checked and created one after another.
	 */
	public function lockPets(int ...$petIds): void
	{
		$petIds = array_values(array_unique(array_filter($petIds)));
		if (!$petIds)
		{
			return;
		}

		sort($petIds);

		$db = $this->db();
		$db->fetchAllColumn(
			'
				SELECT pet_id
				FROM xf_user_pets
				WHERE pet_id IN (' . $db->quote($petIds) . ')
				ORDER BY pet_id
				FOR UPDATE
			'
		);
	}

	/**
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
		$duel->save(true, false);

		return $duel;
	}

	/**
	 * Moves a duel to a new status only if it is still in the expected one, so two requests (or a
	 * re-run job) can never both act on the same duel. The entity is updated to match.
	 *
	 * @param array<string,int> $extra further columns to write with the status
	 * @return bool true when this call made the change
	 */
	public function transitionDuelStatus(UserPetsDuel $duel, string $fromStatus, string $toStatus, array $extra = []): bool
	{
		$allowed = $duel->structure()->columns['status']['allowedValues'];
		if (!in_array($fromStatus, $allowed, true) || !in_array($toStatus, $allowed, true))
		{
			throw new \InvalidArgumentException("Unknown duel status transition {$fromStatus} -> {$toStatus}");
		}

		$values = ['status' => $toStatus] + $extra;

		$affected = $this->db()->update(
			self::TABLE,
			$values,
			'duel_id = ? AND status = ?',
			[$duel->duel_id, $fromStatus]
		);

		if ($affected !== 1)
		{
			return false;
		}

		foreach ($values AS $column => $value)
		{
			$duel->setAsSaved($column, $value);
		}

		return true;
	}

	public function completeDuel(UserPetsDuel $duel, int $winnerPetId, int $loserPetId): bool
	{
		return $this->transitionDuelStatus($duel, 'accepted', 'completed', [
			'winner_pet_id' => $winnerPetId,
			'loser_pet_id' => $loserPetId,
			'completed_at' => \XF::$time,
		]);
	}

	/**
	 * For user deletion: removes the pets' duels that can no longer be fought (pending or
	 * accepted). Finished duels stay, they are the other member's history and win/loss record;
	 * the deleted pet id then simply resolves to no pet.
	 *
	 * @param int[] $petIds
	 * @return int deleted rows
	 */
	public function deleteOpenDuelsForPets(array $petIds): int
	{
		$petIds = array_values(array_unique(array_filter(array_map('intval', $petIds))));
		if (!$petIds)
		{
			return 0;
		}

		$db = $this->db();
		$open = "status IN ('pending', 'accepted')";
		$quoted = $db->quote($petIds);

		return $db->delete(self::TABLE, $open . ' AND challenger_pet_id IN (' . $quoted . ')')
			+ $db->delete(self::TABLE, $open . ' AND opponent_pet_id IN (' . $quoted . ')');
	}

	/**
	 * For user merge when both users have a pet and the source pet is removed: the source pet's
	 * finished duels move to the target pet. Duels between the two pets would become self-duels
	 * and open duels of the source pet can no longer be fought, so both are removed. The win and
	 * loss counters of both pets are recounted afterwards.
	 *
	 * @return int rows changed or removed
	 */
	public function mergeDuelsIntoPet(int $fromPetId, int $toPetId): int
	{
		if ($fromPetId <= 0 || $toPetId <= 0 || $fromPetId === $toPetId)
		{
			return 0;
		}

		$db = $this->db();

		$changed = $db->delete(
			self::TABLE,
			'(challenger_pet_id = ? AND opponent_pet_id = ?) OR (challenger_pet_id = ? AND opponent_pet_id = ?)',
			[$fromPetId, $toPetId, $toPetId, $fromPetId]
		);
		$changed += $this->deleteOpenDuelsForPets([$fromPetId]);

		foreach (['challenger_pet_id', 'opponent_pet_id', 'winner_pet_id', 'loser_pet_id'] AS $column)
		{
			$changed += $db->update(self::TABLE, [$column => $toPetId], $column . ' = ?', $fromPetId);
		}

		$this->recountDuelCounters([$fromPetId, $toPetId]);

		return $changed;
	}

	/**
	 * @return bool True if the alert was sent, false otherwise
	 */
	public function sendDuelChallengeAlert(UserPetsDuel $duel): bool
	{
		try
		{
			$challengerUser = $duel->ChallengerPet?->User;
			$opponentUser = $duel->OpponentPet?->User;

			if (!$challengerUser || !$opponentUser)
			{
				return false;
			}

			if (UserPetOptOut::isDisabledForUser($opponentUser) || UserPetOptOut::isDisabledForUser($challengerUser))
			{
				return false;
			}

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->repository('XF:UserAlert');

			return (bool) $alertRepo->alertFromUser(
				$opponentUser,
				$challengerUser,
				'syl_userpet',
				$duel->opponent_pet_id,
				'duel_challenge',
				[
					'challenger_name' => DisplayName::decode($challengerUser->username),
					'duel_id' => $duel->duel_id,
				]
			);
		}
		catch (\Throwable $e)
		{
			Logger::error('Failed to send duel challenge alert', [
				'exception' => $e->getMessage(),
				'duel_id' => $duel->duel_id,
			]);

			return false;
		}
	}

	/**
	 * @param int[] $duelIds newest first
	 */
	protected function fetchDuelsByIds(array $duelIds): AbstractCollection
	{
		if (!$duelIds)
		{
			return $this->em->getEmptyCollection();
		}

		return $this->getDuelFinderWithPets()
			->where('duel_id', $duelIds)
			->fetch()
			->sortByList($duelIds);
	}

	protected function getDuelFinderWithPets(): Finder
	{
		return $this->finder('Sylphian\UserPets:UserPetsDuel')
			->with(['ChallengerPet.User', 'OpponentPet.User']);
	}

	/**
	 * @return array{wins:int,losses:int,pending_incoming:int,pending_outgoing:int,active:int,declined:int,cancelled:int,total:int}
	 */
	protected function getEmptyOverview(): array
	{
		return [
			'wins' => 0,
			'losses' => 0,
			'pending_incoming' => 0,
			'pending_outgoing' => 0,
			'active' => 0,
			'declined' => 0,
			'cancelled' => 0,
			'total' => 0,
		];
	}
}

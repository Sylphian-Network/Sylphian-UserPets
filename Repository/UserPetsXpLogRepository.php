<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\XpSource;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

/**
 * The XP ledger. Every change of a pet's experience is written here by
 * UserPetsRepository::savePetWithExperience(), so a pet's history can be shown and audited.
 */
class UserPetsXpLogRepository extends Repository
{
	public const TABLE = 'xf_user_pets_xp_log';

	/**
	 * Writes one row with a plain insert (no entity), so it is cheap on hot paths such as new posts.
	 * Zero amounts are not logged.
	 */
	public function log(
		UserPets $pet,
		int $amount,
		string $source,
		string $contentType = '',
		int $contentId = 0,
		int $actorUserId = 0
	): void
	{
		if ($amount === 0 || !$pet->pet_id)
		{
			return;
		}

		if (!in_array($source, XpSource::all(), true))
		{
			$source = XpSource::OTHER;
		}

		$this->db()->insert(self::TABLE, [
			'pet_id' => (int) $pet->pet_id,
			'user_id' => (int) $pet->user_id,
			'amount' => $amount,
			'source' => $source,
			'content_type' => substr($contentType, 0, 25),
			'content_id' => max(0, $contentId),
			'actor_user_id' => max(0, $actorUserId),
			'level_after' => (int) $pet->level,
			'log_date' => \XF::$time,
		]);
	}

	public function findLogsForPet(int $petId): Finder
	{
		return $this->finder('Sylphian\UserPets:UserPetsXpLog')
			->where('pet_id', $petId)
			->order([['log_date', 'DESC'], ['log_id', 'DESC']]);
	}

	/**
	 * @return int rows deleted
	 */
	public function pruneLogs(int $olderThan, int $limit = 10000): int
	{
		if ($olderThan <= 0)
		{
			return 0;
		}

		return (int) $this->db()->delete(
			self::TABLE,
			'log_date < ?',
			$olderThan,
			'',
			'log_date',
			max(1, $limit)
		);
	}

	public function deleteLogsForPets(array $petIds): int
	{
		$petIds = array_values(array_unique(array_filter(array_map('intval', $petIds))));
		if (!$petIds)
		{
			return 0;
		}

		return (int) $this->db()->delete(self::TABLE, 'pet_id IN (' . $this->db()->quote($petIds) . ')');
	}

	/**
	 * The rows follow pets that were handed to another member (user merge).
	 *
	 * @param int[] $petIds
	 */
	public function reassignLogsToUser(array $petIds, int $toUserId): int
	{
		$petIds = array_values(array_unique(array_filter(array_map('intval', $petIds))));
		if (!$petIds || $toUserId <= 0)
		{
			return 0;
		}

		return (int) $this->db()->update(
			self::TABLE,
			['user_id' => $toUserId],
			'pet_id IN (' . $this->db()->quote($petIds) . ')'
		);
	}

	/**
	 * A merged account's pet keeps the source pet's history.
	 */
	public function reassignLogs(int $fromPetId, int $toPetId, int $toUserId): int
	{
		if ($fromPetId <= 0 || $toPetId <= 0 || $fromPetId === $toPetId)
		{
			return 0;
		}

		return (int) $this->db()->update(
			self::TABLE,
			['pet_id' => $toPetId, 'user_id' => $toUserId],
			'pet_id = ?',
			$fromPetId
		);
	}
}

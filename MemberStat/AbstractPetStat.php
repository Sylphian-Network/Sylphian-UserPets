<?php

namespace Sylphian\UserPets\MemberStat;

use Sylphian\UserPets\Helper\UserPetOptOut;
use XF\Entity\MemberStat;
use XF\Finder\UserFinder;

/**
 * Pet member stats rank pets first (ordered reads of indexed pet columns), then keep the users
 * that pass the stat's criteria and have not disabled their pet; the users come in one query
 * with the relations the opt-out check reads. Values are returned as plain integers: the stats
 * are defined with a numeric sort order so XenForo formats them for each viewer, also when the
 * results come from the member stat cache.
 */
abstract class AbstractPetStat
{
	public const UNLIMITED_USER_CAP = 200;

	/** Relations of the ranked users, loaded with them; UserPetOptOut reads the last two. */
	public const USER_WITH = ['Profile', 'UserPet', 'PermissionCombination'];

	public static function getUserLimit(MemberStat $memberStat): int
	{
		return $memberStat->user_limit > 0 ? (int) $memberStat->user_limit : self::UNLIMITED_USER_CAP;
	}

	/**
	 * Over-fetch so opted-out and filtered users do not leave the list short.
	 */
	public static function getCandidateLimit(MemberStat $memberStat): int
	{
		return self::getUserLimit($memberStat) * 3;
	}

	/**
	 * @param array<int,int> $rankedValues user_id => value, best first
	 * @return array<int,int> user_id => value, best first, at most the stat's user limit
	 */
	public static function filterRankedUsers(MemberStat $memberStat, UserFinder $finder, array $rankedValues): array
	{
		if (!$rankedValues)
		{
			return [];
		}

		$users = $finder
			->where('user_id', array_keys($rankedValues))
			->with(self::USER_WITH)
			->fetch();

		$limit = self::getUserLimit($memberStat);
		$output = [];

		foreach ($rankedValues AS $userId => $value)
		{
			$user = $users[$userId] ?? null;
			if (!$user || UserPetOptOut::isDisabledForUser($user))
			{
				continue;
			}

			$output[$userId] = $value;
			if (count($output) >= $limit)
			{
				break;
			}
		}

		return $output;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows pet rows, best first
	 * @return array<int,int> user_id => value; a user's first (best) row wins
	 */
	public static function mapRowsByUser(array $rows, string $valueColumn): array
	{
		$ranked = [];
		foreach ($rows AS $row)
		{
			$userId = (int) ($row['user_id'] ?? 0);
			if ($userId > 0 && !isset($ranked[$userId]))
			{
				$ranked[$userId] = (int) ($row[$valueColumn] ?? 0);
			}
		}

		return $ranked;
	}
}

<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\Leaderboard\Provider\AbstractProvider;
use Sylphian\UserPets\Helper\UserPetOptOut;
use XF\App;
use XF\Entity\User;

/**
 * The ranking is an ordered read of the pet table's counter columns; the ranked rows (ids and
 * numbers only) are cached briefly when a cache is configured. Users are loaded fresh for every
 * view in one query, together with what the display and the opt-out check read (profile, pet
 * row, permission combination), so filtering costs no query per user.
 */
abstract class AbstractPetProvider extends AbstractProvider
{
	public const int CACHE_TTL = 300;

	/** Relations of the ranked users, loaded with them; UserPetOptOut reads the last two. */
	public const array USER_WITH = ['Profile', 'UserPet', 'PermissionCombination'];

	/**
	 * Ranked candidates, best first: each row has a user_id and the provider's metric values.
	 *
	 * @return array<int,array<string,int|float>>
	 */
	abstract protected function fetchRankedRows(App $app, int $limit): array;

	/**
	 * Metric columns of one ranked row (everything but position and username).
	 */
	abstract protected function buildMetrics(array $rankedRow): array;

	public function fetchRows(App $app): array
	{
		$limit = $this->getLimit();
		if ($limit <= 0)
		{
			return [];
		}

		// opted-out members are dropped after ranking, so a few spare candidates are fetched
		$ranked = $this->getRankedRows($app, max($limit * 5, 25));
		if (!$ranked)
		{
			return [];
		}

		$userIds = array_map('intval', array_column($ranked, 'user_id'));
		$users = $app->em()->findByIds('XF:User', $userIds, self::USER_WITH);

		$rows = [];
		$position = 1;
		foreach ($ranked AS $rankedRow)
		{
			/** @var User|null $user */
			$user = $users[(int) $rankedRow['user_id']] ?? null;
			if (!$user || UserPetOptOut::isDisabledForUser($user))
			{
				continue;
			}

			$rows[] = ['position' => $position++, 'username' => $user] + $this->buildMetrics($rankedRow);

			if (count($rows) >= $limit)
			{
				break;
			}
		}

		return $rows;
	}

	public function canView(App $app): bool
	{
		return true;
	}

	protected function getRankedRows(App $app, int $candidateLimit): array
	{
		$ttl = $this->getCacheTtl();
		$cache = $ttl > 0 ? $app->cache('sylphianUserPets') : null;
		$cacheKey = 'sylUserPets_leaderboard_' . $this->getKey() . '_' . $candidateLimit;

		if ($cache)
		{
			try
			{
				$cached = $cache->fetch($cacheKey);
				if (is_array($cached))
				{
					return $cached;
				}
			}
			catch (\Throwable $e)
			{
				$cache = null;
			}
		}

		$ranked = $this->fetchRankedRows($app, $candidateLimit);

		if ($cache)
		{
			try
			{
				$cache->save($cacheKey, $ranked, $ttl);
			}
			catch (\Throwable $e)
			{
				// an unavailable cache only costs the aggregate query on the next view
			}
		}

		return $ranked;
	}

	protected function getCacheTtl(): int
	{
		return static::CACHE_TTL;
	}

	protected static function getWinLossRate(int $wins, int $losses): float
	{
		return round($losses > 0 ? $wins / $losses : (float) $wins, 1);
	}
}

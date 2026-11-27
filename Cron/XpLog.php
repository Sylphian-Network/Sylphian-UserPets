<?php

namespace Sylphian\UserPets\Cron;

use Sylphian\UserPets\Repository\UserPetsXpLogRepository;

class XpLog
{
	public const BATCH_SIZE = 5000;

	public const MAX_BATCHES = 40;

	/**
	 * Seconds one run may spend deleting; a large backlog is worked off over several days.
	 */
	public const MAX_RUN_SECONDS = 10;

	/**
	 * Daily: removes XP history older than the "sylphian_userpets_xp_log_days" option
	 * (0 keeps it forever).
	 */
	public static function pruneOldLogs(): void
	{
		self::prune(self::BATCH_SIZE, self::MAX_BATCHES, self::MAX_RUN_SECONDS);
	}

	/**
	 * Deletes in batches of $batchSize rows and stops after $maxBatches batches, after
	 * $maxSeconds or when nothing older is left.
	 *
	 * @return int Rows deleted
	 */
	public static function prune(int $batchSize, int $maxBatches, float $maxSeconds = self::MAX_RUN_SECONDS): int
	{
		$days = (int) \XF::options()->sylphian_userpets_xp_log_days;
		if ($days <= 0)
		{
			return 0;
		}

		$batchSize = max(1, $batchSize);
		$cutOff = \XF::$time - $days * 86400;
		$deadline = microtime(true) + max(0.0, $maxSeconds);

		/** @var UserPetsXpLogRepository $repo */
		$repo = \XF::repository(UserPetsXpLogRepository::class);

		$total = 0;
		for ($batch = 0; $batch < $maxBatches; $batch++)
		{
			$deleted = $repo->pruneLogs($cutOff, $batchSize);
			$total += $deleted;

			if ($deleted < $batchSize || microtime(true) >= $deadline)
			{
				break;
			}
		}

		return $total;
	}
}

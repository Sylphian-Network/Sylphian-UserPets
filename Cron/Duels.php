<?php

namespace Sylphian\UserPets\Cron;

use Sylphian\UserPets\Service\UserPetDuel;

/**
 * Hourly duel housekeeping. Each part works on a bounded batch; what is left over is picked up
 * by the next run.
 */
class Duels
{
	public static function runHourly(): void
	{
		$service = static::getDuelService();

		// one part failing must not keep the other from running
		try
		{
			$service->expirePendingChallenges();
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'Pet duel request expiry: ');
		}

		try
		{
			$service->requeueStuckDuels();
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'Pet duel re-queue: ');
		}
	}

	protected static function getDuelService(): UserPetDuel
	{
		/** @var UserPetDuel */
		return \XF::app()->service('Sylphian\UserPets:UserPetDuel');
	}
}

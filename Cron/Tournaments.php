<?php

namespace Sylphian\UserPets\Cron;

use Sylphian\UserPets\Service\Tournament\Scheduler;

class Tournaments
{
	/**
	 * Opens and closes registration, starts tournaments and plays interval rounds whose
	 * schedule dates are due.
	 */
	public static function runSchedule(): void
	{
		/** @var Scheduler $scheduler */
		$scheduler = \XF::app()->service('Sylphian\UserPets:Tournament\Scheduler');
		$scheduler->run();
	}
}

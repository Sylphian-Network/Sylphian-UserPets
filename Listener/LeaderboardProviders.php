<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Leaderboard\Provider\DuelWinsProvider;
use Sylphian\UserPets\Leaderboard\Provider\ExperienceProvider;

class LeaderboardProviders
{
	public static function providers(array &$providers): void
	{
		$providers[] = new ExperienceProvider();
		$providers[] = new DuelWinsProvider();
	}
}

<?php

namespace Sylphian\UserPets\Duel;

final class CareScore
{
	/**
	 * How well a pet is cared for, from 0.0 (all stats at 0) to 1.0 (all stats at 100).
	 *
	 * Every stat runs from 0 to 100 where 100 is best, including hunger and sleepiness.
	 *
	 * @param array{hunger: int, sleepiness: int, happiness: int} $pet
	 */
	public static function of(array $pet): float
	{
		return ($pet['hunger'] + $pet['sleepiness'] + $pet['happiness']) / 300;
	}
}

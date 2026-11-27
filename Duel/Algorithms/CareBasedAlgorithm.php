<?php

namespace Sylphian\UserPets\Duel\Algorithms;

/**
 * Only care matters: equal care is an even match, and the better-cared pet gains half the
 * care difference. The bounds keep an upset possible even between the extremes.
 */
class CareBasedAlgorithm extends AbstractDuelAlgorithm
{
	public const float MIN_CHANCE = 0.05;
	public const float MAX_CHANCE = 0.95;

	public function getKey(): string
	{
		return 'care';
	}

	public function getLabel(): string
	{
		return (string) \XF::phrase('sylphian_userpets_duel_algorithm_care_based');
	}

	public function calculateWinChance(array $petA, array $petB): float
	{
		$chance = self::NEUTRAL_CHANCE + (static::getCareScore($petA) - static::getCareScore($petB)) / 2;

		return static::clamp($chance, self::MIN_CHANCE, self::MAX_CHANCE);
	}
}

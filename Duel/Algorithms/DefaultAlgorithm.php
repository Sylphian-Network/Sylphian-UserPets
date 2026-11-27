<?php

namespace Sylphian\UserPets\Duel\Algorithms;

class DefaultAlgorithm extends AbstractDuelAlgorithm
{
	public function getKey(): string
	{
		return 'default';
	}

	public function getLabel(): string
	{
		return (string) \XF::phrase('sylphian_userpets_duel_algorithm_default');
	}

	public function calculateWinChance(array $petA, array $petB): float
	{
		return self::NEUTRAL_CHANCE;
	}
}

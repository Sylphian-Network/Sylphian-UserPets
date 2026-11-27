<?php

namespace Sylphian\UserPets\Duel\Algorithms;

/**
 * Score = level x (1 + care): a fully cared pet fights at twice its level, a neglected one at
 * its bare level. The chance is the share of both scores.
 */
class WeightedAlgorithm extends AbstractDuelAlgorithm
{
	public function getKey(): string
	{
		return 'weight';
	}

	public function getLabel(): string
	{
		return (string) \XF::phrase('sylphian_userpets_duel_algorithm_weighted');
	}

	public function calculateWinChance(array $petA, array $petB): float
	{
		return static::chanceFromScores($this->getScore($petA), $this->getScore($petB));
	}

	public function getScore(array $pet): float
	{
		return static::getNumber($pet, 'level') * (1 + static::getCareScore($pet));
	}
}

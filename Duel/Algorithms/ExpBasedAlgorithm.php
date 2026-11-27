<?php

namespace Sylphian\UserPets\Duel\Algorithms;

/**
 * Score = level + ln(1 + experience) + care (0..1); the chance is the share of both scores.
 */
class ExpBasedAlgorithm extends AbstractDuelAlgorithm
{
	public function getKey(): string
	{
		return 'exp';
	}

	public function getLabel(): string
	{
		return (string) \XF::phrase('sylphian_userpets_duel_algorithm_exp_based');
	}

	public function calculateWinChance(array $petA, array $petB): float
	{
		return static::chanceFromScores($this->getScore($petA), $this->getScore($petB));
	}

	public function getScore(array $pet): float
	{
		return static::getNumber($pet, 'level')
			+ log1p(static::getNumber($pet, 'experience'))
			+ static::getCareScore($pet);
	}
}

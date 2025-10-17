<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

class WeightedAlgorithm implements DuelAlgorithmInterface
{
	/**
	 * Weighted probability based on level and care stats.
	 * Higher-level pets with better care stats have better chances.
	 */
	public function calculateWinner(array $petA, array $petB): array
	{
		$scoreA = $petA['level'] * (1 + ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 100);
		$scoreB = $petB['level'] * (1 + ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 100);
		$probA = $scoreA / ($scoreA + $scoreB);
		return (mt_rand() / mt_getrandmax()) < $probA ? $petA : $petB;
	}
}

<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

class ExpBasedAlgorithm implements DuelAlgorithmInterface
{
	/**
	 * Calculates a weighted chance using level, experience, and care stats.
	 */
	public function calculateWinner(array $petA, array $petB): array
	{
		$scoreA = $petA['level'] + log($petA['exp'] + 1) + ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 100;
		$scoreB = $petB['level'] + log($petB['exp'] + 1) + ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 100;
		$probA = $scoreA / ($scoreA + $scoreB);
		return (mt_rand() / mt_getrandmax()) < $probA ? $petA : $petB;
	}
}

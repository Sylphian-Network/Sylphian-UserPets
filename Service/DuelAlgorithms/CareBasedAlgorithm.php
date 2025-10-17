<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

class CareBasedAlgorithm implements DuelAlgorithmInterface
{
	/**
	 * Calculates a chance based on pet happiness, hunger, and sleepiness.
	 * The winner is more likely the pet with better care stats.
	 */
	public function calculateWinner(array $petA, array $petB): array
	{
		$modifierA = ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 200;
		$modifierB = ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 200;
		$chanceA = 0.5 + $modifierA - $modifierB;
		$chanceA = max(0.05, min(0.95, $chanceA));
		return (mt_rand() / mt_getrandmax()) < $chanceA ? $petA : $petB;
	}
}

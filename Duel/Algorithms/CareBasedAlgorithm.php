<?php

namespace Sylphian\UserPets\Duel\Algorithms;

class CareBasedAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'care';
	}
	public function getLabel(): string
	{
		return 'Care-based';
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		$modifierA = ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 200;
		$modifierB = ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 200;
		$chanceA = 0.5 + $modifierA - $modifierB;
		$chanceA = max(0.05, min(0.95, $chanceA));
		return (mt_rand() / mt_getrandmax()) < $chanceA ? $petA : $petB;
	}
}

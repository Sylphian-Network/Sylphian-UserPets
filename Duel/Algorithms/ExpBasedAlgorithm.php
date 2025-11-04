<?php

namespace Sylphian\UserPets\Duel\Algorithms;

class ExpBasedAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'exp';
	}

	public function getLabel(): string
	{
		return 'Exp-based';
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		$scoreA = $petA['level'] + log($petA['experience'] + 1) + ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 100;
		$scoreB = $petB['level'] + log($petB['experience'] + 1) + ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 100;
		$probA = $scoreA / ($scoreA + $scoreB);
		return (mt_rand() / mt_getrandmax()) < $probA ? $petA : $petB;
	}
}

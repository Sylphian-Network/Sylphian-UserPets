<?php

namespace Sylphian\UserPets\Duel\Algorithms;

class WeightedAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'weight';
	}

	public function getLabel(): string
	{
		return 'Weighted';
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		$scoreA = $petA['level'] * (1 + ($petA['happiness'] - $petA['hunger'] - $petA['sleepiness']) / 100);
		$scoreB = $petB['level'] * (1 + ($petB['happiness'] - $petB['hunger'] - $petB['sleepiness']) / 100);
		$probA = $scoreA / ($scoreA + $scoreB);
		return (mt_rand() / mt_getrandmax()) < $probA ? $petA : $petB;
	}
}

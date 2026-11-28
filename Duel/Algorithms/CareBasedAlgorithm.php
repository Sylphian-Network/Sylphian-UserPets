<?php

namespace Sylphian\UserPets\Duel\Algorithms;

use Sylphian\UserPets\Duel\CareScore;

class CareBasedAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'care';
	}
	public function getLabel(): string
	{
		return \XF::phrase('sylphian_userpets_algorithm_care_based')->render();
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		$chanceA = 0.5 + (CareScore::of($petA) - CareScore::of($petB)) / 2;
		$chanceA = max(0.05, min(0.95, $chanceA));
		return (mt_rand() / mt_getrandmax()) < $chanceA ? $petA : $petB;
	}
}

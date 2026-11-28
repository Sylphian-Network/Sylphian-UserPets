<?php

namespace Sylphian\UserPets\Duel\Algorithms;

use Sylphian\UserPets\Duel\CareScore;

class ExpBasedAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'exp';
	}

	public function getLabel(): string
	{
		return \XF::phrase('sylphian_userpets_algorithm_exp_based')->render();
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		$scoreA = $this->score($petA);
		$scoreB = $this->score($petB);
		$total = $scoreA + $scoreB;
		$probA = $total > 0 ? $scoreA / $total : 0.5;
		return (mt_rand() / mt_getrandmax()) < $probA ? $petA : $petB;
	}

	protected function score(array $pet): float
	{
		return $pet['level'] + log(max(0, $pet['experience']) + 1) + 2 * CareScore::of($pet);
	}
}

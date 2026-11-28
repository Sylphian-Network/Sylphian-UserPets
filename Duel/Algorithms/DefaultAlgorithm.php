<?php

namespace Sylphian\UserPets\Duel\Algorithms;

class DefaultAlgorithm implements DuelAlgorithmInterface
{
	public function getKey(): string
	{
		return 'default';
	}
	public function getLabel(): string
	{
		return \XF::phrase('sylphian_userpets_algorithm_default')->render();
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		return mt_rand(0, 1) ? $petA : $petB;
	}
}

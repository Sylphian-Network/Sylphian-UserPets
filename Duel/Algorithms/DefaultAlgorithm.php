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
		return 'Default (50/50)';
	}

	public function calculateWinner(array $petA, array $petB): array
	{
		return mt_rand(0, 1) ? $petA : $petB;
	}
}

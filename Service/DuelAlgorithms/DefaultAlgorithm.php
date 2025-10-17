<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

class DefaultAlgorithm implements DuelAlgorithmInterface
{
	/**
	 * Simple 50/50 chance regardless of pet stats.
	 */
	public function calculateWinner(array $petA, array $petB): array
	{
		return mt_rand(0, 1) ? $petA : $petB;
	}
}

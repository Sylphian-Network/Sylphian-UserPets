<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

interface DuelAlgorithmInterface
{
	public function calculateWinner(array $petA, array $petB): array;
}

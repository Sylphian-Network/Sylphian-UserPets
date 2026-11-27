<?php

namespace Sylphian\UserPets\Duel\Algorithms;

interface DuelAlgorithmInterface
{
	/** Short, stable key like "default", "care", "exp", "weight"; stored in the duel algorithm option. */
	public function getKey(): string;

	/** Label shown in the admin option. */
	public function getLabel(): string;

	/**
	 * Receives two pet rows (UserPets::toArray()) and must return one of them unchanged: callers
	 * identify the winner by its pet_id. Hunger, sleepiness and happiness are "high = good".
	 *
	 * @param array $petA
	 * @param array $petB
	 * @return array
	 */
	public function calculateWinner(array $petA, array $petB): array;
}

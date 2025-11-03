<?php

namespace Sylphian\UserPets\Duel\Algorithms;

interface DuelAlgorithmInterface
{
	/** Short, stable key like "default", "care", "exp", "weighted" */
	public function getKey(): string;

	/** Human-friendly label for ACP */
	public function getLabel(): string;

	/**
	 * Must return the winning pet as an array compatible with your job usage.
	 *
	 * @param array $petA
	 * @param array $petB
	 * @return array
	 */
	public function calculateWinner(array $petA, array $petB): array;
}

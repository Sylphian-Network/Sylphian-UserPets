<?php

namespace Sylphian\UserPets\Duel;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Duel\Algorithms\DefaultAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\DuelAlgorithmInterface;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\PetManager;

/**
 * Decides a fight between two pets for duels and tournament matches.
 */
class WinnerPicker
{
	/**
	 * Brings both pets' stats up to date (stored stats only decay when something updates them),
	 * then lets the algorithm choose. A failing or misbehaving algorithm falls back to an even
	 * match so a duel or tournament match is never left unresolved.
	 */
	public static function pick(UserPets $petA, UserPets $petB, ?DuelAlgorithmInterface $algorithm = null): UserPets
	{
		static::refreshStats($petA);
		static::refreshStats($petB);

		$algorithm = $algorithm ?? AlgorithmRegistry::resolve();

		try
		{
			$result = $algorithm->calculateWinner($petA->toArray(), $petB->toArray());
		}
		catch (\Throwable $e)
		{
			Logger::error('Duel algorithm failed, the default algorithm decided instead', [
				'algorithm_key' => $algorithm->getKey(),
				'exception' => $e->getMessage(),
				'pet_a_id' => $petA->pet_id,
				'pet_b_id' => $petB->pet_id,
			]);

			$algorithm = new DefaultAlgorithm();
			$result = $algorithm->calculateWinner($petA->toArray(), $petB->toArray());
		}

		$winnerPetId = (int) ($result['pet_id'] ?? 0);
		if ($winnerPetId === (int) $petA->pet_id)
		{
			return $petA;
		}
		if ($winnerPetId === (int) $petB->pet_id)
		{
			return $petB;
		}

		Logger::warning('Duel algorithm returned neither pet, the default algorithm decided instead', [
			'algorithm_key' => $algorithm->getKey(),
			'returned_pet_id' => $winnerPetId,
			'pet_a_id' => $petA->pet_id,
			'pet_b_id' => $petB->pet_id,
		]);

		$fallback = (new DefaultAlgorithm())->calculateWinner($petA->toArray(), $petB->toArray());

		return (int) $fallback['pet_id'] === (int) $petA->pet_id ? $petA : $petB;
	}

	public static function refreshStats(UserPets $pet): void
	{
		try
		{
			(new PetManager($pet))->updateStats();
		}
		catch (\Throwable $e)
		{
			Logger::error('Could not update pet stats before a duel, stored stats were used', [
				'pet_id' => $pet->pet_id,
				'exception' => $e->getMessage(),
			]);
		}
	}
}

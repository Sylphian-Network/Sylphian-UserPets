<?php

namespace Sylphian\UserPets\Stats;

/**
 * Represents a pet's happiness stat.
 */
class Happiness extends Stat
{
	/** Amount happiness increases when the pet plays */
	public const int PLAY_AMOUNT = 20;

	/** Hunger cost incurred when the pet plays */
	public const int HUNGER_COST = 5;

	/** Name of the critical state when happiness is too low */
	protected string $criticalState = 'Unhappy';

	/**
	 * Play with the pet, increasing happiness and slightly decreasing hunger.
	 *
	 * @param Hunger $hunger The pet's hunger stat, which is affected by play
	 */
	public function play(Hunger $hunger): void
	{
		$this->increase(self::PLAY_AMOUNT);
		$hunger->decrease(self::HUNGER_COST);
	}

	/**
	 * Determine if the happiness stat is in a critical state.
	 *
	 * @return bool True if the pet is unhappy
	 */
	public function isCritical(): bool
	{
		return $this->value <= $this->criticalThreshold;
	}
}

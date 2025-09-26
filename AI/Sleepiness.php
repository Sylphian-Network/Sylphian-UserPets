<?php

namespace Sylphian\UserPets\AI;

/**
 * Represents a pet's sleepiness stat.
 */
class Sleepiness extends Stat
{
	/** Amount the sleepiness stat increases when the pet sleeps */
	public const int SLEEP_AMOUNT = 20;

	/** Name of the critical state when sleepiness is too low */
	protected string $criticalState = 'Tired';

	/**
	 * Perform the sleep action, increasing the sleepiness stat.
	 */
	public function sleep(): void
	{
		$this->increase(self::SLEEP_AMOUNT);
	}

	/**
	 * Determine if the sleepiness stat is in a critical state.
	 *
	 * @return bool True if the pet is too tired
	 */
	public function isCritical(): bool
	{
		return $this->value <= $this->criticalThreshold;
	}
}

<?php

namespace Sylphian\UserPets\AI;

/**
 * Represents a pet's hunger stat.
 */
class Hunger extends Stat
{
	/** Amount the hunger stat increases when the pet is fed */
	public const int FEED_AMOUNT = 20;

	/** Name of the critical state when hunger is too low */
	protected string $criticalState = 'Hungry';

	/**
	 * Feed the pet, increasing the hunger stat.
	 */
	public function feed(): void
	{
		$this->increase(self::FEED_AMOUNT);
	}

	/**
	 * Determine if the hunger stat is in a critical state.
	 *
	 * @return bool True if the pet is starving
	 */
	public function isCritical(): bool
	{
		return $this->value <= $this->criticalThreshold;
	}
}

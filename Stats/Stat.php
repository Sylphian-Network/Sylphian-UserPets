<?php

namespace Sylphian\UserPets\Stats;

abstract class Stat
{
	/**
	 * Current value of the stat (0-100)
	 *
	 * @var int
	 */
	protected int $value;

	/**
	 * Rate at which the stat decreases per minute
	 *
	 * @var int
	 */
	protected int $rate;

	/**
	 * Name of the state to set when this stat is critical.
	 *
	 * @var string
	 */
	protected string $criticalState;

	/**
	 * Threshold at or below which the stat is considered critical.
	 *
	 * @var int
	 */
	protected int $criticalThreshold = 20;

	/**
	 * Constructor.
	 *
	 * @param int $value Initial stat value (0-100)
	 * @param int $rate Rate of change per minute
	 * @param int $criticalThreshold Threshold for critical state (default 20)
	 */
	public function __construct(int $value, int $rate, int $criticalThreshold = 20)
	{
		$this->value = $value;
		$this->rate = $rate;
		$this->criticalThreshold = $criticalThreshold;
	}

	/**
	 * Get the current value of the stat.
	 *
	 * @return int Current stat value
	 */
	public function getValue(): int
	{
		return $this->value;
	}

	/**
	 * Set the stat value, clamped between 0 and 100.
	 *
	 * @param int $value New stat value
	 */
	public function setValue(int $value): void
	{
		$this->value = max(0, min(100, $value));
	}

	/**
	 * Get the rate value
	 *
	 * @return int
	 */
	public function getRate(): int
	{
		return $this->rate;
	}

	/**
	 * Increase the stat by a given amount.
	 *
	 * @param int $amount Amount to increase
	 */
	public function increase(int $amount): void
	{
		$this->setValue($this->value + $amount);
	}

	/**
	 * Decrease the stat by a given amount.
	 *
	 * @param int $amount Amount to decrease
	 */
	public function decrease(int $amount): void
	{
		$this->setValue($this->value - $amount);
	}

	/**
	 * Update the stat based on elapsed minutes.
	 * Default implementation decreases value by rate * elapsedMinutes.
	 *
	 * @param int $elapsedMinutes Number of minutes since last update
	 */
	public function update(int $intervals = 1): void
	{
		$this->decrease($this->rate * $intervals);
	}

	/**
	 * Determine whether the stat is in a critical state.
	 *
	 * @return bool True if stat value is at or below critical threshold
	 */
	abstract public function isCritical(): bool;

	/**
	 * Get the critical state name associated with this stat.
	 *
	 * @return string Critical state
	 */
	public function getCriticalState(): string
	{
		return $this->criticalState;
	}
}

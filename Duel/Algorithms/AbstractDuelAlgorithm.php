<?php

namespace Sylphian\UserPets\Duel\Algorithms;

/**
 * Shared scoring for the built-in algorithms.
 *
 * Hunger, sleepiness and happiness are all "high = good" (fed, rested, happy), so a well-cared
 * pet has a care score close to 1 and a neglected one close to 0. Every score is non-negative
 * and every chance is clamped to 0..1, whatever the pet arrays contain.
 */
abstract class AbstractDuelAlgorithm implements DuelAlgorithmInterface
{
	public const float NEUTRAL_CHANCE = 0.5;

	/**
	 * Probability (0..1) that $petA beats $petB.
	 */
	abstract public function calculateWinChance(array $petA, array $petB): float;

	public function calculateWinner(array $petA, array $petB): array
	{
		return $this->roll() < $this->calculateWinChance($petA, $petB) ? $petA : $petB;
	}

	public static function getCareScore(array $pet): float
	{
		$total = static::getStat($pet, 'hunger')
			+ static::getStat($pet, 'sleepiness')
			+ static::getStat($pet, 'happiness');

		return static::clamp($total / 300);
	}

	protected static function getStat(array $pet, string $key): float
	{
		return static::clamp(static::getNumber($pet, $key), 0.0, 100.0);
	}

	protected static function getNumber(array $pet, string $key): float
	{
		$value = $pet[$key] ?? 0;
		if (!is_numeric($value))
		{
			return 0.0;
		}

		$value = (float) $value;

		return is_finite($value) && $value > 0 ? $value : 0.0;
	}

	/**
	 * Share of the combined score; two zero scores are an even match instead of 0 / 0.
	 */
	protected static function chanceFromScores(float $scoreA, float $scoreB): float
	{
		$scoreA = is_finite($scoreA) && $scoreA > 0 ? $scoreA : 0.0;
		$scoreB = is_finite($scoreB) && $scoreB > 0 ? $scoreB : 0.0;

		$total = $scoreA + $scoreB;
		if ($total <= 0.0 || !is_finite($total))
		{
			return self::NEUTRAL_CHANCE;
		}

		return static::clamp($scoreA / $total);
	}

	protected static function clamp(float $value, float $min = 0.0, float $max = 1.0): float
	{
		if (is_nan($value))
		{
			return ($min + $max) / 2;
		}

		return max($min, min($max, $value));
	}

	/**
	 * Uniform in [0, 1), so a chance of 1 always wins and a chance of 0 never does.
	 */
	protected function roll(): float
	{
		$max = mt_getrandmax();

		return mt_rand(0, $max - 1) / $max;
	}
}

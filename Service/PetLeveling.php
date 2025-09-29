<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPets;

class PetLeveling
{
	/**
	 * The base experience coefficient for the polynomial growth formula.
	 * This affects how quickly experience requirements increase per level.
	 *
	 * @var float
	 */
	protected float $baseCoefficient = 100;

	/**
	 * The polynomial power used in the growth formula.
	 * Higher values create a steeper curve.
	 *
	 * @var float
	 */
	protected float $polynomialPower = 1.5;

	/**
	 * The flat rate of experience gained per action.
	 *
	 * @var int
	 */
	protected int $experiencePerAction = 10;

	/**
	 * Constructor.
	 *
	 * @param float|null $baseCoefficient Optional custom base coefficient
	 * @param float|null $polynomialPower Optional custom polynomial power
	 * @param int|null $experiencePerAction Optional custom experience per action
	 */
	public function __construct(?float $baseCoefficient = null, ?float $polynomialPower = null, ?int $experiencePerAction = null)
	{
		$options = \XF::options();

		$this->baseCoefficient = $baseCoefficient ?? (float) ($options->sylphian_userpets_base_coefficient);
		$this->polynomialPower = $polynomialPower ?? (float) ($options->sylphian_userpets_polynomial_power);
		$this->experiencePerAction = $experiencePerAction ?? (int) ($options->sylphian_userpets_experience_per_action);
	}

	/**
	 * Calculate the experience required to reach a specific level.
	 *
	 * Uses a polynomial growth formula: baseCoefficient * (level ^ polynomialPower)
	 *
	 * @param int $level The level to calculate experience for
	 * @return int The total experience required
	 */
	public function getExperienceRequiredForLevel(int $level): int
	{
		// Level 1 requires 0 experience
		if ($level <= 1)
		{
			return 0;
		}

		return (int) ceil($this->baseCoefficient * pow($level, $this->polynomialPower));
	}

	/**
	 * Get the experience needed to level up from the current level.
	 *
	 * @param UserPets $pet The pet entity
	 * @return int The experience needed to reach the next level
	 */
	public function getExperienceNeededToLevelUp(UserPets $pet): int
	{
		$currentLevel = $pet->level;
		$currentExp = $pet->experience;
		$nextLevelExp = $this->getExperienceRequiredForLevel($currentLevel + 1);

		return max(0, $nextLevelExp - $currentExp);
	}

	/**
	 * Calculate the appropriate level for a given amount of experience.
	 *
	 * @param int $experience The total experience
	 * @return int The appropriate level
	 */
	public function calculateLevelFromExperience(int $experience): int
	{
		$level = 1;

		while ($this->getExperienceRequiredForLevel($level + 1) <= $experience)
		{
			$level++;
		}

		return $level;
	}

	/**
	 * Add experience to the pet and handle level ups.
	 *
	 * @param UserPets $pet The pet entity
	 * @param int $amount The amount of experience to add (defaults to the standard action amount)
	 * @return bool True if the pet leveled up, false otherwise
	 */
	public function addExperience(UserPets $pet, ?int $amount = null): bool
	{
		if ($amount === null)
		{
			$amount = $this->experiencePerAction;
		}

		$oldLevel = $pet->level;
		$pet->experience += $amount;

		$newLevel = $this->calculateLevelFromExperience($pet->experience);
		if ($newLevel > $oldLevel)
		{
			$pet->level = $newLevel;
			return true;
		}

		return false;
	}

	/**
	 * Get the current progress percentage towards the next level.
	 *
	 * @param UserPets $pet The pet entity
	 * @return float Progress percentage (0-100)
	 */
	public function getLevelProgressPercentage(UserPets $pet): float
	{
		$currentLevel = $pet->level;
		$currentExp = $pet->experience;
		$currentLevelExp = $this->getExperienceRequiredForLevel($currentLevel);
		$nextLevelExp = $this->getExperienceRequiredForLevel($currentLevel + 1);

		$levelExpDiff = $nextLevelExp - $currentLevelExp;
		if ($levelExpDiff <= 0)
		{
			return 100.0;
		}

		$progress = ($currentExp - $currentLevelExp) / $levelExpDiff * 100;
		return min(100.0, max(0.0, $progress));
	}
}

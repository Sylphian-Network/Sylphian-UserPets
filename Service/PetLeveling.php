<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPets;

class PetLeveling
{
	/**
	 * The level column is a 32-bit unsigned integer; extreme option values
	 * (tiny coefficient, low power) would otherwise produce levels that do not fit.
	 */
	public const MAX_LEVEL = 1000000;

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
	 * Map of action types to their corresponding experience values
	 *
	 * @var array<string,int>
	 */
	protected array $actionExpMap;

	/**
	 * @param float|null $baseCoefficient Optional custom base coefficient
	 * @param float|null $polynomialPower Optional custom polynomial power
	 */
	public function __construct(?float $baseCoefficient = null, ?float $polynomialPower = null)
	{
		$options = \XF::options();

		$this->baseCoefficient = $this->sanitizeCoefficient(
			$baseCoefficient ?? (float) $options->sylphian_userpets_base_coefficient
		);
		$this->polynomialPower = $this->sanitizePower(
			$polynomialPower ?? (float) $options->sylphian_userpets_polynomial_power
		);

		$this->actionExpMap = [
			'feed' => max(0, (int) $options->sylphian_userpets_experience_per_feed),
			'sleep' => max(0, (int) $options->sylphian_userpets_experience_per_sleep),
			'play' => max(0, (int) $options->sylphian_userpets_experience_per_play),
		];
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
		$nextLevelExp = $this->getExperienceRequiredForLevel($pet->level + 1);

		return max(0, $nextLevelExp - $pet->experience);
	}

	/**
	 * The highest level whose requirement is covered by the given experience.
	 *
	 * ceil(c * n^p) <= e holds exactly when n <= (e / c)^(1 / p) for integer e, so the
	 * closed form is exact in real arithmetic; the two loops only absorb float rounding
	 * and keep the result identical to getExperienceRequiredForLevel().
	 *
	 * @param int $experience The total experience
	 * @return int The appropriate level
	 */
	public function calculateLevelFromExperience(int $experience): int
	{
		if ($experience <= 0)
		{
			return 1;
		}

		$estimate = floor(pow($experience / $this->baseCoefficient, 1 / $this->polynomialPower));
		$level = (int) max(1, min(self::MAX_LEVEL, $estimate));

		while ($level < self::MAX_LEVEL && $this->getExperienceRequiredForLevel($level + 1) <= $experience)
		{
			$level++;
		}

		while ($level > 1 && $this->getExperienceRequiredForLevel($level) > $experience)
		{
			$level--;
		}

		return $level;
	}

	/**
	 * Add a specific amount of experience to the pet and recalculate its level.
	 * The pet is not saved: store the change with UserPetsRepository::savePetWithExperience(),
	 * which also writes it to the XP log.
	 *
	 * @param UserPets $pet The pet entity
	 * @param int $amount The amount of experience to add (negative removes experience)
	 * @return bool True if the pet leveled up, false otherwise
	 */
	public function addExperience(UserPets $pet, int $amount): bool
	{
		$oldLevel = (int) $pet->level;
		$pet->experience = max(0, (int) $pet->experience + $amount);

		$newLevel = $this->calculateLevelFromExperience((int) $pet->experience);
		$pet->level = $newLevel;

		return $newLevel > $oldLevel;
	}

	/**
	 * Add the configured experience for an action type to the pet and recalculate its level.
	 * The pet is not saved.
	 *
	 * @param UserPets $pet The pet entity
	 * @param string|null $actionType The type of action performed (feed, sleep, play)
	 * @return bool True if the pet leveled up, false otherwise
	 */
	public function addExperienceForAction(UserPets $pet, ?string $actionType = null): bool
	{
		return $this->addExperience($pet, $this->getExperienceForAction($actionType));
	}

	/**
	 * Get the experience amount for a specific action type. Unknown actions earn nothing.
	 *
	 * @param string|null $actionType The type of action (feed, sleep, play)
	 * @return int The experience amount for this action
	 */
	public function getExperienceForAction(?string $actionType = null): int
	{
		if ($actionType === null || !isset($this->actionExpMap[$actionType]))
		{
			return 0;
		}

		return $this->actionExpMap[$actionType];
	}

	/**
	 * Get the current progress percentage towards the next level.
	 *
	 * @param UserPets $pet The pet entity
	 * @return float Progress percentage (0-100)
	 */
	public function getLevelProgressPercentage(UserPets $pet): float
	{
		$currentLevelExp = $this->getExperienceRequiredForLevel($pet->level);
		$nextLevelExp = $this->getExperienceRequiredForLevel($pet->level + 1);

		$levelExpDiff = $nextLevelExp - $currentLevelExp;
		if ($levelExpDiff <= 0)
		{
			return 100.0;
		}

		$progress = ($pet->experience - $currentLevelExp) / $levelExpDiff * 100;
		return min(100.0, max(0.0, $progress));
	}

	protected function sanitizeCoefficient(float $value): float
	{
		return (is_finite($value) && $value >= 1) ? $value : 1.0;
	}

	protected function sanitizePower(float $value): float
	{
		if (!is_finite($value) || $value < 0.1)
		{
			return 0.1;
		}

		return min($value, 10.0);
	}
}

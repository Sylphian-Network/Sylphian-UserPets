<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Stats\Happiness;
use Sylphian\UserPets\Stats\Hunger;
use Sylphian\UserPets\Stats\Sleepiness;
use XF\PrintableException;

/**
 * Manages a user's pet, including stat updates, actions, and state determination.
 */
class PetManager
{
	public const ACTIONS = ['feed', 'sleep', 'play'];

	/**
	 * The pet entity being managed.
	 *
	 * @var UserPets
	 */
	protected UserPets $pet;

	/**
	 * Array of stat objects keyed by stat name (e.g., 'hunger', 'sleepiness', 'happiness').
	 *
	 * @var array<string, Hunger|Sleepiness|Happiness>
	 */
	protected array $stats = [];

	/**
	 * Map of action names to callable functions that perform them.
	 *
	 * @var array<string, callable>
	 */
	protected array $actionMap = [];

	/**
	 * @param UserPets $pet The pet entity to manage
	 */
	public function __construct(UserPets $pet)
	{
		$this->pet = $pet;

		$options = \XF::options();

		$this->stats = [
			'hunger' => new Hunger(
				(int) $pet->hunger,
				(int) $options->sylphian_userpets_hunger_decay,
				(int) $options->sylphian_userpets_hunger_critical_threshold
			),
			'sleepiness' => new Sleepiness(
				(int) $pet->sleepiness,
				(int) $options->sylphian_userpets_sleepiness_decay,
				(int) $options->sylphian_userpets_sleepiness_critical_threshold
			),
			'happiness' => new Happiness(
				(int) $pet->happiness,
				(int) $options->sylphian_userpets_happiness_decay,
				(int) $options->sylphian_userpets_happiness_critical_threshold
			),
		];

		$this->actionMap = [
			'feed' => fn () => $this->stats['hunger']->feed(),
			'sleep' => fn () => $this->stats['sleepiness']->sleep(),
			'play' => fn () => $this->stats['happiness']->play($this->stats['hunger']),
		];
	}

	/**
	 * Brings a pet's stats up to date (decay since the last update) and saves it when
	 * anything changed. Duels and tournaments call this before comparing pets.
	 *
	 * @throws PrintableException If saving the pet fails
	 */
	public static function refresh(UserPets $pet): bool
	{
		return (new self($pet))->updateStats();
	}

	/**
	 * Applies the decay for every full update interval that elapsed since the last update.
	 *
	 * The remainder of a started interval is kept: last_update only advances by the
	 * intervals that were applied, so frequent views do not slow the decay down.
	 *
	 * @param bool $save Save the pet when stats changed
	 * @return bool True if at least one interval was applied
	 * @throws PrintableException If saving the pet fails
	 */
	public function updateStats(bool $save = true): bool
	{
		$intervalSeconds = $this->getUpdateIntervalSeconds();
		$lastUpdate = (int) $this->pet->last_update;
		$elapsed = \XF::$time - $lastUpdate;

		if ($elapsed < $intervalSeconds)
		{
			return false;
		}

		$intervals = intdiv($elapsed, $intervalSeconds);

		foreach ($this->stats AS $key => $stat)
		{
			$stat->update($intervals);
			$this->pet->$key = $stat->getValue();
		}

		$this->determineState();
		$this->pet->last_update = $lastUpdate + $intervals * $intervalSeconds;

		if ($save)
		{
			$this->pet->save();
		}

		return true;
	}

	public function getUpdateIntervalSeconds(): int
	{
		return max(1, (int) \XF::options()->sylphian_userpets_stat_update_interval) * 60;
	}

	public static function isValidAction(string $action): bool
	{
		return in_array($action, self::ACTIONS, true);
	}

	/**
	 * Determines the pet's current state based on critical stats.
	 *
	 * If multiple stats are critical, their states are combined into a comma-separated string.
	 */
	protected function determineState(): void
	{
		$activeStates = [];

		foreach ($this->stats AS $stat)
		{
			if ($stat->isCritical())
			{
				$activeStates[] = $stat->getCriticalState();
			}
		}

		$this->pet->state = $activeStates ? implode(',', $activeStates) : 'idle';
	}

	/**
	 * Syncs all stat objects back to the pet entity.
	 */
	protected function syncStats(): void
	{
		foreach ($this->stats AS $key => $stat)
		{
			$this->pet->$key = $stat->getValue();
		}
	}

	/**
	 * Performs a pet action: applies pending decay, the action itself and the action XP,
	 * then saves the pet once.
	 *
	 * The caller is responsible for the opt-out and cooldown checks.
	 *
	 * @param string $action The action to perform (feed, sleep, play)
	 * @return bool False for an unknown action (nothing is changed) or when saving failed
	 */
	public function performAction(string $action): bool
	{
		if (!isset($this->actionMap[$action]))
		{
			return false;
		}

		$this->updateStats(false);

		($this->actionMap[$action])();
		$this->syncStats();
		$this->determineState();

		$this->pet->last_action_time = \XF::$time;

		/** @var UserPetsRepository $petsRepo */
		$petsRepo = \XF::repository('Sylphian\UserPets:UserPets');

		return $petsRepo->savePetWithExperience($this->pet, (new PetLeveling())->getExperienceForAction($action));
	}
}

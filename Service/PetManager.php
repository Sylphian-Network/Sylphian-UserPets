<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\AI\Happiness;
use Sylphian\UserPets\AI\Hunger;
use Sylphian\UserPets\AI\Sleepiness;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\PrintableException;

/**
 * Manages a user's pet, including stat updates, actions, and state determination.
 */
class PetManager
{
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
	 * Constructor.
	 *
	 * Initializes stats and sets up the action map for the pet.
	 *
	 * @param UserPets $pet The pet entity to manage
	 */
	public function __construct(UserPets $pet)
	{
		$this->pet = $pet;

		$this->stats = [
			'hunger' => new Hunger(
				$pet->hunger,
				\XF::options()->sylphian_userpets_hunger_decay,
				\XF::options()->sylphian_userpets_hunger_critical_threshold
			),
			'sleepiness' => new Sleepiness(
				$pet->sleepiness,
				\XF::options()->sylphian_userpets_sleepiness_decay,
				\XF::options()->sylphian_userpets_sleepiness_critical_threshold
			),
			'happiness' => new Happiness(
				$pet->happiness,
				\XF::options()->sylphian_userpets_happiness_decay,
				\XF::options()->sylphian_userpets_happiness_critical_threshold
			),
		];

		$this->actionMap = [
			'feed' => fn () => $this->stats['hunger']->feed(),
			'sleep' => fn () => $this->stats['sleepiness']->sleep(),
			'play' => fn () => $this->stats['happiness']->play($this->stats['hunger']),
		];
	}

	/**
	 * Updates all pet stats based on the elapsed time.
	 *
	 * Stats are only updated if the elapsed time exceeds the configured update interval.
	 * After updating, the pet's state is determined and the entity is saved.
	 *
	 * @throws PrintableException If saving the pet fails
	 */
	public function updateStats(): void
	{
		$elapsed = floor((\XF::$time - $this->pet->last_update) / 60);
		$interval = max(1, \XF::options()->sylphian_userpets_stat_update_interval);

		if ($elapsed < $interval)
		{
			return;
		}

		$intervals = floor($elapsed / $interval);

		foreach ($this->stats AS $key => $stat)
		{
			$stat->update($intervals);
			$this->pet->$key = $stat->getValue();
		}

		$this->determineState();
		$this->pet->last_update = \XF::$time;
		$this->pet->save();
	}

	/**
	 * Determines the pet's current state based on critical stats.
	 *
	 * If multiple stats are critical, their states are combined into a comma-separated string.
	 * Defaults to 'Idle' if no stat is critical.
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

		$this->pet->state = $activeStates ? implode(', ', $activeStates) : 'Idle';
	}

	/**
	 * Syncs all stat objects back to the pet entity.
	 *
	 * This ensures that the pet entity always reflects the current values of all stats.
	 */
	protected function syncStats(): void
	{
		foreach ($this->stats AS $key => $stat)
		{
			$this->pet->$key = $stat->getValue();
		}
	}

	/**
	 * Performs a pet action.
	 *
	 * Updates the stats first, executes the action if it exists, syncs stats, determines state, and saves the pet.
	 *
	 * @param string $action The action to perform (e.g., 'feed', 'sleep', 'play')
	 * @throws PrintableException If saving the pet fails
	 */
	public function performAction(string $action): void
	{
		$this->updateStats();

		if (isset($this->actionMap[$action]))
		{
			($this->actionMap[$action])();
			$this->syncStats();
			$this->determineState();

			$petLeveling = new PetLeveling();
			$expAmount = $petLeveling->getExperienceForAction($action);

			/** @var UserPetsRepository $petsRepo */
			$petsRepo = \XF::repository('Sylphian\UserPets:UserPets');
			$petsRepo->awardPetExperience($this->pet->user_id, $expAmount);
		}
	}
}

<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\AI\Happiness;
use Sylphian\UserPets\AI\Hunger;
use Sylphian\UserPets\AI\Sleepiness;
use Sylphian\UserPets\Entity\UserPets;

class PetManager
{
	protected UserPets $pet;
	protected Hunger $hunger;
	protected Sleepiness $sleepiness;
	protected Happiness $happiness;

	public function __construct(UserPets $pet)
	{
		$this->pet = $pet;

		$this->hunger = new Hunger($pet->hunger, 2);
		$this->sleepiness = new Sleepiness($pet->sleepiness, 1);
		$this->happiness = new Happiness($pet->happiness, 1);
	}

	public function updateStats(): void
	{
		$elapsed = floor((\XF::$time - $this->pet->last_update) / 60);

		if ($elapsed > 0)
		{
			$this->hunger->update($elapsed);
			$this->sleepiness->update($elapsed);
			$this->happiness->update($elapsed);

			$this->pet->hunger = $this->hunger->getValue();
			$this->pet->sleepiness = $this->sleepiness->getValue();
			$this->pet->happiness = $this->happiness->getValue();

			$this->determineState();
			$this->pet->last_update = \XF::$time;
			$this->pet->save();
		}
	}

	protected function determineState(): void
	{
		if ($this->hunger->isStarving())
		{
			$this->pet->state = 'hungry';
		}
		else if ($this->sleepiness->isExhausted())
		{
			$this->pet->state = 'sleeping';
		}
		else if ($this->happiness->isSad())
		{
			$this->pet->state = 'sad';
		}
		else
		{
			$this->pet->state = 'idle';
		}
	}

	public function performAction(string $action): void
	{
		$this->updateStats();

		switch ($action)
		{
			case 'feed':
				$this->hunger->feed();
				$this->pet->hunger = $this->hunger->getValue();
				break;
			case 'sleep':
				$this->sleepiness->sleep();
				$this->pet->sleepiness = $this->sleepiness->getValue();
				break;
			case 'play':
				$this->happiness->play($this->hunger);
				$this->pet->happiness = $this->happiness->getValue();
				$this->pet->hunger = $this->hunger->getValue();
				break;
		}

		$this->determineState();
		$this->pet->save();
	}
}

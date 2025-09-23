<?php

namespace Sylphian\UserPets\AI;

class Happiness extends Stat
{
	public function update(int $elapsedMinutes): void
	{
		$this->setValue($this->value - ($this->rate * $elapsedMinutes));
	}

	public function play(Hunger $hunger): void
	{
		$this->increase(20);
		$hunger->decrease(5);
	}

	public function isSad(): bool
	{
		return $this->value <= 20;
	}
}

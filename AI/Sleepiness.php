<?php

namespace Sylphian\UserPets\AI;

class Sleepiness extends Stat
{
	public function update(int $elapsedMinutes): void
	{
		$this->setValue($this->value - ($this->rate * $elapsedMinutes));
	}

	public function sleep(): void
	{
		$this->increase(20);
	}

	public function isExhausted(): bool
	{
		return $this->value <= 20;
	}
}

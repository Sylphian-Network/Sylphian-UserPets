<?php

namespace Sylphian\UserPets\AI;

class Hunger extends Stat
{
	public function update(int $elapsedMinutes): void
	{
		$this->setValue($this->value - ($this->rate * $elapsedMinutes));
	}

	public function feed(): void
	{
		$this->increase(20);
	}

	public function isStarving(): bool
	{
		return $this->value <= 20;
	}
}

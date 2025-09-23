<?php

namespace Sylphian\UserPets\AI;

abstract class Stat
{
	protected int $value;
	protected int $rate;

	public function __construct(int $value, int $rate)
	{
		$this->value = $value;
		$this->rate = $rate;
	}

	public function getValue(): int
	{
		return $this->value;
	}

	public function setValue(int $value): void
	{
		$this->value = max(0, min(100, $value));
	}

	public function increase(int $amount): void
	{
		$this->setValue($this->value + $amount);
	}

	public function decrease(int $amount): void
	{
		$this->setValue($this->value - $amount);
	}

	abstract public function update(int $elapsedMinutes): void;
}

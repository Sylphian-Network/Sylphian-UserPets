<?php

namespace Sylphian\UserPets\Duel;

use Sylphian\UserPets\Duel\Algorithms\DuelAlgorithmInterface;

class AlgorithmManager
{
	/** @var array<string,DuelAlgorithmInterface> */
	protected array $algorithms = [];

	/**
	 * @param array<int,DuelAlgorithmInterface> $algorithms
	 */
	public function __construct(array $algorithms)
	{
		foreach ($algorithms AS $algo)
		{
			$this->algorithms[$algo->getKey()] = $algo;
		}
	}

	/** @return array<string,DuelAlgorithmInterface> */
	public function all(): array
	{
		return $this->algorithms;
	}

	public function get(string $key): ?DuelAlgorithmInterface
	{
		return $this->algorithms[$key] ?? null;
	}
}

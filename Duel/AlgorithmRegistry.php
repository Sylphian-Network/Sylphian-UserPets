<?php

namespace Sylphian\UserPets\Duel;

use Sylphian\UserPets\Duel\Algorithms\CareBasedAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\DefaultAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\DuelAlgorithmInterface;
use Sylphian\UserPets\Duel\Algorithms\ExpBasedAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\WeightedAlgorithm;

class AlgorithmRegistry
{
	public const string DEFAULT_KEY = 'default';

	/**
	 * Build manager: hardcode built-ins, then allow external add-ons via event.
	 */
	public static function buildManager(): AlgorithmManager
	{
		/** @var array<int,DuelAlgorithmInterface> $algorithms */
		$algorithms = [
			new DefaultAlgorithm(),
			new CareBasedAlgorithm(),
			new ExpBasedAlgorithm(),
			new WeightedAlgorithm(),
		];

		\XF::app()->fire('sylphian_userpets_duel_algorithms', [ &$algorithms ]);

		$uniq = [];
		foreach ($algorithms AS $algo)
		{
			if ($algo instanceof DuelAlgorithmInterface)
			{
				$uniq[$algo->getKey()] = $algo;
			}
		}

		return new AlgorithmManager(array_values($uniq));
	}

	/**
	 * Resolve the algorithm by key or the current option value; an unknown or empty key falls
	 * back to the default algorithm.
	 */
	public static function resolve(?string $key = null): DuelAlgorithmInterface
	{
		$manager = self::buildManager();

		$value = $key;
		if ($value === null || $value === '')
		{
			$value = (string) (\XF::options()->sylphian_userpets_duel_algorithm ?? '');
		}

		if ($value !== '')
		{
			$algo = $manager->get($value);
			if ($algo)
			{
				return $algo;
			}
		}

		return $manager->get(self::DEFAULT_KEY) ?? new DefaultAlgorithm();
	}

	/**
	 * @return string[]
	 */
	public static function getValidKeys(): array
	{
		return array_keys(self::buildManager()->all());
	}
}

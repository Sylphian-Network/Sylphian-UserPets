<?php

namespace Sylphian\UserPets\Duel;

use Sylphian\UserPets\Duel\Algorithms\CareBasedAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\DefaultAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\DuelAlgorithmInterface;
use Sylphian\UserPets\Duel\Algorithms\ExpBasedAlgorithm;
use Sylphian\UserPets\Duel\Algorithms\WeightedAlgorithm;

class AlgorithmRegistry
{
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

		// De-dupe by key
		$uniq = [];
		foreach ($algorithms AS $algo)
		{
			$uniq[$algo->getKey()] = $algo;
		}

		return new AlgorithmManager(array_values($uniq));
	}

	/**
	 * Resolve the algorithm by key or current option value; fallback to default.
	 */
	public static function resolve(?string $key = null): DuelAlgorithmInterface
	{
		$manager = self::buildManager();

		$value = $key;
		if ($value === null || $value === '')
		{
			$value = \XF::options()->sylphian_userpets_duel_algorithm ?? '';
		}

		if ($value !== '')
		{
			$algo = $manager->get($value);
			if ($algo)
			{
				return $algo;
			}
		}

		$all = $manager->all();
		if (isset($all['default']))
		{
			return $all['default'];
		}

		return new DefaultAlgorithm();
	}
}

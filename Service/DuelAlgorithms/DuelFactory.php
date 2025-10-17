<?php

namespace Sylphian\UserPets\Service\DuelAlgorithms;

class DuelFactory
{
	public static function getAlgorithm(?string $class = null): DuelAlgorithmInterface
	{
		if (!$class)
		{
			$class = \XF::options()->sylphian_userpets_duel_algorithm;
		}

		if (!\is_string($class) || !\class_exists($class) || !\is_subclass_of($class, DuelAlgorithmInterface::class))
		{
			$class = DefaultAlgorithm::class;
		}

		$class = \XF::app()->extendClass($class);
		return new $class();
	}
}

<?php

namespace Sylphian\UserPets\Option;

use Sylphian\UserPets\Duel\AlgorithmRegistry;
use XF\Entity\Option;
use XF\Option\AbstractOption;

class DuelAlgorithm extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		$data = static::getSelectData($option, $htmlParams);

		return static::getTemplater()->formSelectRow(
			$data['controlOptions'],
			$data['choices'],
			$data['rowOptions']
		);
	}

	protected static function getSelectData(Option $option, array $htmlParams): array
	{
		$choices = self::getChoices();

		return [
			'choices' => $choices,
			'controlOptions' => static::getControlOptions($option, $htmlParams),
			'rowOptions' => static::getRowOptions($option, $htmlParams),
		];
	}

	/** Returns [key => ['value' => key, 'label' => label]] for all registered algorithms. */
	public static function getChoices(): array
	{
		$manager = AlgorithmRegistry::buildManager();
		$choices = [];
		foreach ($manager->all() AS $algo)
		{
			$choices[$algo->getKey()] = [
				'value' => $algo->getKey(),
				'label' => $algo->getLabel(),
			];
		}

		uasort($choices, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
		return $choices;
	}
}

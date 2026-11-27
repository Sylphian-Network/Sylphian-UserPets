<?php

namespace Sylphian\UserPets\Option;

use Sylphian\UserPets\Duel\AlgorithmRegistry;
use XF\Entity\Option;
use XF\Option\AbstractOption;

class DuelAlgorithm extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		$choices = self::getChoices();
		$selected = self::getSelectedKey((string) $option->option_value, $choices);

		return static::getTemplater()->formSelectRow(
			static::getControlOptions($option, $htmlParams, $selected),
			$choices,
			static::getRowOptions($option, $htmlParams)
		);
	}

	/**
	 * Validation callback (validation_method "verifyOption"): only registered algorithm keys.
	 */
	public static function verifyOption(&$value, Option $option, $optionId = null): bool
	{
		$value = is_string($value) ? trim($value) : '';

		if (!in_array($value, AlgorithmRegistry::getValidKeys(), true))
		{
			$option->error(\XF::phrase('please_enter_valid_value'), $optionId ?? $option->option_id);
			return false;
		}

		return true;
	}

	/**
	 * A stored value that is no longer a registered key (an old class name, a removed add-on's
	 * algorithm) is shown as the algorithm that actually runs: the default one.
	 */
	public static function getSelectedKey(string $storedValue, array $choices): string
	{
		if (isset($choices[$storedValue]))
		{
			return $storedValue;
		}

		if (isset($choices[AlgorithmRegistry::DEFAULT_KEY]))
		{
			return AlgorithmRegistry::DEFAULT_KEY;
		}

		return (string) (array_key_first($choices) ?? '');
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

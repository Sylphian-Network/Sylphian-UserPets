<?php

namespace Sylphian\UserPets\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;
use XF\Repository\ThreadTypeRepository;

class ContentThreadTypeMultiSelect extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		$selected = self::parseStoredValue($option->option_value);
		$choices = [];

		foreach (self::getThreadTypeChoices() AS $typeId => $label)
		{
			$choices[] = [
				'value' => $typeId,
				'label' => $label,
				'selected' => in_array($typeId, $selected, true),
			];
		}

		$controlOptions = static::getControlOptions($option, $htmlParams);
		$controlOptions['multiple'] = true;
		$controlOptions['size'] = 8;

		return static::getTemplater()->formSelectRow(
			$controlOptions,
			$choices,
			static::getRowOptions($option, $htmlParams)
		);
	}

	public static function verifyOption(&$value): bool
	{
		if (!is_array($value))
		{
			$value = [];
			return true;
		}

		$allowed = array_keys(self::getThreadTypeChoices());
		$values = [];

		foreach ($value AS $entry)
		{
			$entry = strtolower(trim((string) $entry));
			if ($entry !== '' && in_array($entry, $allowed, true))
			{
				$values[] = $entry;
			}
		}

		$values = array_values(array_unique($values));
		sort($values, SORT_NATURAL | SORT_FLAG_CASE);

		$value = $values;
		return true;
	}

	/**
	 * Thread types of active add-ons, labelled with XenForo's own thread_type.<id> phrases.
	 *
	 * @return array<string, \XF\Phrase|string>
	 */
	protected static function getThreadTypeChoices(): array
	{
		/** @var ThreadTypeRepository $repo */
		$repo = \XF::repository('XF:ThreadType');

		$choices = [];
		foreach ($repo->getThreadTypeListData() AS $typeId => $title)
		{
			$typeId = strtolower((string) $typeId);
			if ($typeId !== '')
			{
				$choices[$typeId] = $title;
			}
		}

		ksort($choices, SORT_NATURAL);

		return $choices;
	}

	/**
	 * @return array<int,string>
	 */
	protected static function parseStoredValue($value): array
	{
		if (is_array($value))
		{
			$values = [];

			foreach ($value AS $part)
			{
				$part = strtolower(trim((string) $part));
				if ($part !== '')
				{
					$values[] = $part;
				}
			}

			return array_values(array_unique($values));
		}

		$value = (string) $value;
		$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$values = [];

		foreach ($parts AS $part)
		{
			$part = strtolower(trim($part));
			if ($part !== '')
			{
				$values[] = $part;
			}
		}

		return array_values(array_unique($values));
	}
}

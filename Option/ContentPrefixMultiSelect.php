<?php

namespace Sylphian\UserPets\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;

class ContentPrefixMultiSelect extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		$selected = self::parseStoredValue($option->option_value);

		$choices = [
			[
				'value' => 0,
				'label' => (string) \XF::phrase('sylphian_userpets_no_prefix'),
				'selected' => in_array(0, $selected, true),
			],
		];

		$prefixes = \XF::finder('XF:ThreadPrefix')
			->order('prefix_id')
			->fetch();

		foreach ($prefixes AS $prefix)
		{
			$choices[] = [
				'value' => (int) $prefix->prefix_id,
				'label' => (string) $prefix->title,
				'selected' => in_array((int) $prefix->prefix_id, $selected, true),
			];
		}

		$controlOptions = static::getControlOptions($option, $htmlParams);
		$controlOptions['multiple'] = true;
		$controlOptions['size'] = 10;

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

		$values = [];
		foreach ($value AS $entry)
		{
			if (!is_numeric($entry))
			{
				continue;
			}

			$entry = (int) $entry;
			if ($entry >= 0)
			{
				$values[] = $entry;
			}
		}

		$values = array_values(array_unique($values));
		sort($values, SORT_NUMERIC);

		$value = $values;
		return true;
	}

	/**
	 * @return array<int,int>
	 */
	protected static function parseStoredValue($value): array
	{
		if (is_array($value))
		{
			$values = [];
			foreach ($value AS $part)
			{
				if (is_numeric($part))
				{
					$values[] = (int) $part;
				}
			}

			return array_values(array_unique($values));
		}

		$value = (string) $value;
		$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$values = [];

		foreach ($parts AS $part)
		{
			if (is_numeric($part))
			{
				$values[] = (int) $part;
			}
		}

		return array_values(array_unique($values));
	}
}

<?php

namespace Sylphian\UserPets\Option;

use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
use XF\Entity\Option;
use XF\Option\AbstractOption;

/**
 * The default spritesheet option: a select of the sheets whose file exists. The value is
 * the file name; '' leaves the choice to the render fallback.
 */
class SpriteSheet extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		return static::getTemplater()->formSelectRow(
			static::getControlOptions($option, $htmlParams),
			static::getSpritesheetOptions($option, $htmlParams),
			static::getRowOptions($option, $htmlParams)
		);
	}

	public static function verifyOption(&$value, Option $option): bool
	{
		$value = trim((string) $value);
		if ($value === '')
		{
			return true;
		}

		if (!isset(static::getSpritesheetRepository()->getSpritesheetChoices()[$value]))
		{
			$option->error(\XF::phrase('please_enter_valid_value'), $option->option_id);
			return false;
		}

		return true;
	}

	/**
	 * @return list<array{value:string, label:string|\XF\Phrase}>
	 */
	public static function getSpritesheetOptions(Option $option, array &$htmlParams): array
	{
		$options = [];
		foreach (static::getSpritesheetRepository()->getSpritesheetChoices() AS $filename => $title)
		{
			$options[] = [
				'value' => $filename,
				'label' => $title,
			];
		}

		if (!$options)
		{
			$options[] = [
				'value' => '',
				'label' => \XF::phrase('sylphian_userpets_spritesheet_choice_none'),
			];
		}

		return $options;
	}

	protected static function getSpritesheetRepository(): UserPetsSpritesheetRepository
	{
		/** @var UserPetsSpritesheetRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		return $repo;
	}
}

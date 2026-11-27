<?php

namespace Sylphian\UserPets\Option;

use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Entity\Option;
use XF\Option\AbstractOption;

class SpriteSheet extends AbstractOption
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

	public static function verifyOption(&$value, Option $option): bool
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return true;
		}

		/** @var UserPetsRepository $repo */
		$repo = \XF::repository('Sylphian\\UserPets:UserPets');
		$filename = $repo->resolveSpritesheetFilename($value);
		if ($filename === null)
		{
			$option->error(\XF::phrase('please_enter_valid_value'), $option->option_id);
			return false;
		}

		$value = $repo->getSpritesheetChoiceId($filename);
		return true;
	}

	protected static function getSelectData(Option $option, array $htmlParams): array
	{
		$choices = static::getSpritesheetOptions($option, $htmlParams);
		$controlOptions = static::getControlOptions($option, $htmlParams);

		/** @var UserPetsRepository $repository */
		$repository = \XF::repository('Sylphian\UserPets:UserPets');
		$selectedFilename = $repository->resolveSpritesheetFilename((string) ($controlOptions['value'] ?? ''));
		if ($selectedFilename !== null)
		{
			$controlOptions['value'] = $repository->getSpritesheetChoiceId($selectedFilename);
		}

		return [
			'choices' => $choices,
			'controlOptions' => $controlOptions,
			'rowOptions' => static::getRowOptions($option, $htmlParams),
		];
	}

	public static function getSpritesheetOptions(Option $option, array &$htmlParams): array
	{
		/** @var UserPetsRepository $repository */
		$repository = \XF::repository('Sylphian\UserPets:UserPets');
		$spriteSheets = $repository->getAvailableSpriteSheets();

		$options = [];
		foreach ($spriteSheets AS $file => $label)
		{
			$options[] = [
				'value' => $file,
				'label' => $label,
			];
		}

		return $options;
	}
}

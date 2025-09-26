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

	protected static function getSelectData(Option $option, array $htmlParams): array
	{
		$choices = static::getSpritesheetOptions($option, $htmlParams);

		return [
			'choices' => $choices,
			'controlOptions' => static::getControlOptions($option, $htmlParams),
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

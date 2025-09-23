<?php

namespace Sylphian\UserPets\Option;

use Sylphian\Library\Logger\Logger;
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
		$spritesheetDir = \XF::getRootDirectory() . '/data/assets/sylphian/userpets/spritesheets';

		$options = [];

		if (is_dir($spritesheetDir))
		{
			$files = array_diff(scandir($spritesheetDir), ['.', '..']);
			foreach ($files AS $file)
			{
				if (preg_match('/.png$/i', $file))
				{
					$options[] = [
						'value' => $file,
						'label' => $file,
					];
				}
			}
		}
		else
		{
			Logger::error('Spritesheet directory not found', [
				'spritesheetDir' => $spritesheetDir,
			]);
		}

		if (empty($options))
		{
			$options['slime_spritesheet.png'] = 'slime_spritesheet.png';
		}

		return $options;
	}
}

<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use XF\Mvc\Entity\Repository;

class UserPetsRepository extends Repository
{
	/**
	 * Get available sprite sheet options
	 *
	 * @return array List of available sprite sheets as field_choices array
	 */
	public function getAvailableSpriteSheets(): array
	{
		$rootDir = \XF::getRootDirectory();
		$spritesheetDir = $rootDir . '/data/assets/sylphian/userpets/spritesheets';

		$options = [];

		if (is_dir($spritesheetDir))
		{
			$files = array_diff(scandir($spritesheetDir), ['.', '..']);
			foreach ($files AS $file)
			{
				if (preg_match('/.png$/i', $file))
				{
					$identifier = strtolower(preg_replace('/[^a-z0-9_]/i', '_', pathinfo($file, PATHINFO_FILENAME)));

					$displayLabel = ucwords(str_replace('_', ' ', pathinfo($file, PATHINFO_FILENAME)));

					if (!isset($options[$identifier]))
					{
						$options[$identifier] = $displayLabel;
					}
					else
					{
						$count = 1;
						while (isset($options["{$identifier}_{$count}"]))
						{
							$count++;
						}
						$options["{$identifier}_{$count}"] = $displayLabel . " {$count}";
					}
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
			$options['slime'] = 'Slime';
		}

		return $options;
	}
}

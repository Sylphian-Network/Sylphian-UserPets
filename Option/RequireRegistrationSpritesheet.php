<?php

namespace Sylphian\UserPets\Option;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Option\AbstractOption;

class RequireRegistrationSpritesheet extends AbstractOption
{
	public static function verifyOption(&$value): bool
	{
		$value = (bool) $value;

		try
		{
			/** @var UserPetsRepository $repo */
			$repo = \XF::repository('Sylphian\UserPets:UserPets');
			$repo->syncSpritesheetRegistrationRequirement($value);
		}
		catch (\Throwable $e)
		{
			Logger::error('Failed to sync spritesheet registration requirement.', [
				'exception' => $e->getMessage(),
				'value' => $value,
			]);
		}

		return true;
	}
}

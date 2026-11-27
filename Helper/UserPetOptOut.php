<?php

namespace Sylphian\UserPets\Helper;

use XF\Entity\User;

class UserPetOptOut
{
	public const FIELD_ID = 'syl_userpets_disable_pet';

	public const CHOICE = 'disable_option';

	public static function isDisabledForUser(User $user): bool
	{
		if (!$user->user_id || !$user->Profile)
		{
			return false;
		}

		$disabledValue = $user->Profile->custom_fields[self::FIELD_ID] ?? [];

		return in_array(self::CHOICE, (array) $disabledValue, true);
	}

	public static function isDisabledByUserId(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		/** @var User|null $user */
		$user = \XF::app()->em()->find('XF:User', $userId, ['Profile']);
		if (!$user)
		{
			return false;
		}

		return self::isDisabledForUser($user);
	}
}

<?php

namespace Sylphian\UserPets\Helper;

use XF\Entity\User;

class UserPetOptOut
{
	public static function isDisabledForUser(User $user): bool
	{
		if (!$user->user_id || !$user->Profile)
		{
			return false;
		}

		$disabledValue = $user->Profile->custom_fields['syl_userpets_disable_pet'] ?? [];
		$disabledArray = (array) $disabledValue;
		return in_array('disable_option', $disabledArray, true);
	}

	public static function isDisabledByUserId(int $userId): bool
	{
		if (!$userId)
		{
			return false;
		}

		/** @var User $user */
		$user = \XF::app()->em()->find('XF:User', $userId);
		if (!$user)
		{
			return false;
		}

		return self::isDisabledForUser($user);
	}
}

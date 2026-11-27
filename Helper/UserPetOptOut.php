<?php

namespace Sylphian\UserPets\Helper;

use XF\Entity\User;

/**
 * A member's pet is off when they lack the sylUserPets.havePet permission or switched it off
 * on their pet page (xf_user_pets.is_disabled).
 *
 * Both checks read the user's permission cache and the UserPet relation. Code that checks many
 * users must load them with(['UserPet', 'PermissionCombination']), otherwise every user costs
 * a pet query and every permission combination a cache query.
 */
class UserPetOptOut
{
	public const PERMISSION_GROUP = 'sylUserPets';
	public const PERMISSION_HAVE_PET = 'havePet';

	/**
	 * Guests (user_id 0) are not reported as disabled: they have no pet, and callers that act
	 * for the visitor check registration themselves.
	 */
	public static function isDisabledForUser(User $user): bool
	{
		if (!$user->user_id)
		{
			return false;
		}

		if (!$user->hasPermission(self::PERMISSION_GROUP, self::PERMISSION_HAVE_PET))
		{
			return true;
		}

		$pet = $user->UserPet;

		return $pet !== null && $pet->is_disabled;
	}

	/**
	 * A user id without an xf_user row is not reported as disabled, as before: pets of
	 * deleted users are removed with the user, and there is no permission to read.
	 */
	public static function isDisabledByUserId(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		/** @var User|null $user */
		$user = \XF::app()->em()->find('XF:User', $userId, ['UserPet', 'PermissionCombination']);
		if (!$user)
		{
			return false;
		}

		return self::isDisabledForUser($user);
	}
}

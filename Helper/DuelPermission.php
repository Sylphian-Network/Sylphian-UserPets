<?php

namespace Sylphian\UserPets\Helper;

use XF\Entity\User;

class DuelPermission
{
	public const string PERMISSION_GROUP = 'general';
	public const string PERMISSION_ID = 'sylUserPetsDuelCooldown';

	public const string PET_PERMISSION_GROUP = 'sylUserPets';
	public const string DUEL_PERMISSION_ID = 'duel';

	/** Used only when the duel cooldown option is missing; matches the option's default. */
	public const int FALLBACK_COOLDOWN_HOURS = 12;

	/**
	 * Sending and accepting a challenge: the member needs the duel permission and a pet that is
	 * not opted out.
	 */
	public static function canDuel(User $user): bool
	{
		return $user->user_id > 0
			&& $user->hasPermission(self::PET_PERMISSION_GROUP, self::DUEL_PERMISSION_ID)
			&& !UserPetOptOut::isDisabledForUser($user);
	}

	/**
	 * Being challenged needs only a pet: UserPetOptOut covers both the "have a pet" permission
	 * and the member's own switch.
	 */
	public static function canBeChallenged(User $user): bool
	{
		return $user->user_id > 0 && !UserPetOptOut::isDisabledForUser($user);
	}

	public static function canChallenge(User $challenger, User $opponent): bool
	{
		return $challenger->user_id !== $opponent->user_id
			&& self::canDuel($challenger)
			&& self::canBeChallenged($opponent);
	}

	/**
	 * Reads the integer permission general.sylUserPetsDuelCooldown (minutes) from the permission
	 * cache. XenForo combines integer permissions as "-1 (unlimited) wins, otherwise the highest
	 * value"; 0 means no group sets it, so the duel cooldown option (hours) applies. "allowed"
	 * is the duel permission, so callers that only read the policy also hide duel actions.
	 *
	 * @return array{has_custom:bool,allowed:bool,cooldown_minutes:int,cooldown_seconds:int,mode:string}
	 */
	public static function getCooldownPolicy(User $user): array
	{
		$permissionValue = (int) $user->hasPermission(self::PERMISSION_GROUP, self::PERMISSION_ID);

		$optionHours = \XF::options()->sylphian_userpets_duel_cooldown_time ?? self::FALLBACK_COOLDOWN_HOURS;

		$policy = self::resolveCooldownPolicy($permissionValue, (int) $optionHours);
		$policy['allowed'] = self::canDuel($user);

		return $policy;
	}

	/**
	 * @return array{has_custom:bool,allowed:bool,cooldown_minutes:int,cooldown_seconds:int,mode:string}
	 */
	public static function resolveCooldownPolicy(int $permissionValue, int $optionHours): array
	{
		if ($permissionValue < 0)
		{
			return self::policy(true, 0, 'unlimited');
		}

		if ($permissionValue > 0)
		{
			return self::policy(true, $permissionValue, 'minutes');
		}

		$minutes = max(0, $optionHours) * 60;

		return self::policy(false, $minutes, $minutes > 0 ? 'default' : 'none');
	}

	protected static function policy(bool $hasCustom, int $minutes, string $mode): array
	{
		return [
			'has_custom' => $hasCustom,
			'allowed' => true,
			'cooldown_minutes' => $minutes,
			'cooldown_seconds' => $minutes * 60,
			'mode' => $mode,
		];
	}
}

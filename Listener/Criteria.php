<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Entity\UserPets;
use XF\Entity\User;

class Criteria
{
	/**
	 * criteria_user listener. Every rule reads the user's pet row through the UserPet relation
	 * (one lookup on the unique user_id, kept on the user for the rest of the request) and its
	 * counters, so notice and trophy checks never count duels or tournaments.
	 */
	public static function onUserCriteria(string $rule, array $data, User $user, bool &$returnValue): void
	{
		switch ($rule)
		{
			case 'syl_userpets_duel_wins_at_least':
				$returnValue = self::petValueIsAtLeast($user, 'duel_wins', (int) ($data['count'] ?? 0));
				return;

			case 'syl_userpets_duel_losses_at_least':
				$returnValue = self::petValueIsAtLeast($user, 'duel_losses', (int) ($data['count'] ?? 0));
				return;

			case 'syl_userpets_tournament_wins_at_least':
				$returnValue = self::petValueIsAtLeast($user, 'tournament_wins', (int) ($data['count'] ?? 0));
				return;

			case 'syl_userpets_pet_level_at_least':
				$returnValue = self::petValueIsAtLeast($user, 'level', (int) ($data['level'] ?? 0));
				return;

			case 'syl_userpets_pet_age_days_at_least':
				$returnValue = self::petAgeIsAtLeast($user, (int) ($data['days'] ?? 0));
				return;
		}
	}

	/**
	 * A minimum of 0 or less never matches, so an empty field cannot match every member.
	 */
	protected static function petValueIsAtLeast(User $user, string $column, int $min): bool
	{
		if ($min <= 0)
		{
			return false;
		}

		$pet = self::getPet($user);

		return $pet && (int) $pet->get($column) >= $min;
	}

	/**
	 * Whole days since the pet was created, like XenForo's "registered for at least X days";
	 * 0 days matches every member who has a pet.
	 */
	protected static function petAgeIsAtLeast(User $user, int $days): bool
	{
		if ($days < 0)
		{
			return false;
		}

		$pet = self::getPet($user);
		if (!$pet || $pet->created_at <= 0)
		{
			return false;
		}

		return intdiv(max(0, \XF::$time - $pet->created_at), 86400) >= $days;
	}

	protected static function getPet(User $user): ?UserPets
	{
		if (!$user->user_id)
		{
			return null;
		}

		/** @var UserPets|null */
		return $user->UserPet;
	}
}

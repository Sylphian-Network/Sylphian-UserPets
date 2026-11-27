<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Entity\User;

class Criteria
{
	/**
	 * criteria_user listener. Duel counts are two indexed lookups (the user's pet ids, then a
	 * count by winner_pet_id / loser_pet_id), cheap enough for notice and trophy checks.
	 */
	public static function onUserCriteria(string $rule, array $data, User $user, bool &$returnValue): void
	{
		switch ($rule)
		{
			case 'syl_userpets_duel_wins_at_least':
				$min = (int) ($data['count'] ?? 0);
				if ($min <= 0 || !$user->user_id)
				{
					$returnValue = false;
					return;
				}

				$returnValue = self::getDuelRepo()->getUserDuelWins($user->user_id) >= $min;
				return;

			case 'syl_userpets_duel_losses_at_least':
				$min = (int) ($data['count'] ?? 0);
				if ($min <= 0 || !$user->user_id)
				{
					$returnValue = false;
					return;
				}

				$returnValue = self::getDuelRepo()->getUserDuelLosses($user->user_id) >= $min;
				return;

			case 'syl_userpets_pet_level_at_least':
				$minLevel = (int) ($data['level'] ?? 0);
				if ($minLevel <= 0 || !$user->user_id)
				{
					$returnValue = false;
					return;
				}

				/** @var UserPetsRepository $petsRepo */
				$petsRepo = \XF::repository('Sylphian\UserPets:UserPets');
				$pet = $petsRepo->getUserPet($user->user_id);
				$returnValue = ($pet && $pet->level >= $minLevel);
				return;
		}
	}

	protected static function getDuelRepo(): UserPetsDuelRepository
	{
		/** @var UserPetsDuelRepository */
		return \XF::repository('Sylphian\UserPets:UserPetsDuel');
	}
}

<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Entity\User;

class Criteria
{
	/**
	 * criteria_user listener
	 * @param string $rule
	 * @param array $data
	 * @param User $user
	 * @param bool $returnValue
	 */
	public static function onUserCriteria(string $rule, array $data, User $user, bool &$returnValue): void
	{
		switch ($rule)
		{
			case 'syl_userpets_duel_wins_at_least':
				$min = (int) ($data['count'] ?? 0);
				if ($min <= 0)
				{
					$returnValue = false;
					return;
				}

				/** @var UserPetsDuelRepository $repo */
				$repo = \XF::repository('Sylphian\UserPets:UserPetsDuel');
				$wins = $repo->getUserDuelWins($user->user_id);
				$returnValue = ($wins >= $min);
				return;

			case 'syl_userpets_duel_losses_at_least':
				$min = (int) ($data['count'] ?? 0);
				if ($min <= 0)
				{
					$returnValue = false;
					return;
				}

				/** @var UserPetsDuelRepository $repo */
				$repo = \XF::repository('Sylphian\UserPets:UserPetsDuel');
				$losses = $repo->getUserDuelLosses($user->user_id);
				$returnValue = ($losses >= $min);
				return;

			case 'syl_userpets_pet_level_at_least':
				$minLevel = (int) ($data['level'] ?? 0);
				if ($minLevel <= 0)
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
}

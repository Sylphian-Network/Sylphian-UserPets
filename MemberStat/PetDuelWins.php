<?php

namespace Sylphian\UserPets\MemberStat;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\Entity\MemberStat;
use XF\Finder\UserFinder;

class PetDuelWins extends AbstractPetStat
{
	/**
	 * Visibility callback: the stat is hidden while duels are disabled.
	 */
	public static function isVisible(MemberStat $memberStat): bool
	{
		return (bool) \XF::options()->sylphian_userpets_duel_enable;
	}

	/**
	 * @return array<int,int> user_id => duel wins
	 */
	public static function getUsers(MemberStat $memberStat, UserFinder $finder): array
	{
		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = \XF::repository('Sylphian\UserPets:UserPetsDuel');
		$rows = $duelRepo->getTopUsersByWins(self::getCandidateLimit($memberStat));

		return self::filterRankedUsers($memberStat, $finder, self::mapRowsByUser($rows, 'wins'));
	}
}

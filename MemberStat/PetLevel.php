<?php

namespace Sylphian\UserPets\MemberStat;

use XF\Entity\MemberStat;
use XF\Finder\UserFinder;

class PetLevel extends AbstractPetStat
{
	/**
	 * @return array<int,int> user_id => pet level
	 */
	public static function getUsers(MemberStat $memberStat, UserFinder $finder): array
	{
		$rows = \XF::finder('Sylphian\UserPets:UserPets')
			->where('experience', '>', 0)
			->order('level', 'DESC')
			->order('experience', 'DESC')
			->order('pet_id', 'ASC')
			->limit(self::getCandidateLimit($memberStat))
			->fetchColumns(['user_id', 'level']);

		return self::filterRankedUsers($memberStat, $finder, self::mapRowsByUser($rows, 'level'));
	}
}

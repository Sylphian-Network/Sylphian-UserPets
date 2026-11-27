<?php

namespace Sylphian\UserPets\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $entry_id
 * @property int $tournament_id
 * @property int $pet_id
 * @property int $user_id
 * @property int $seed
 * @property int $joined_at
 *
 * RELATIONS
 * @property UserPetsTournament $Tournament
 * @property UserPets $Pet
 * @property \XF\Entity\User $User
 */
class UserPetsTournamentEntry extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_tournament_entries';
		$structure->shortName = 'Sylphian\UserPets:UserPetsTournamentEntry';
		$structure->primaryKey = 'entry_id';

		$structure->columns = [
			'entry_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'tournament_id' => ['type' => self::UINT, 'required' => true],
			'pet_id' => ['type' => self::UINT, 'required' => true],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'seed' => ['type' => self::UINT, 'default' => 0],
			'joined_at' => ['type' => self::UINT, 'default' => \XF::$time],
		];

		$structure->relations = [
			'Tournament' => [
				'entity' => 'Sylphian\UserPets:UserPetsTournament',
				'type' => self::TO_ONE,
				'conditions' => [['tournament_id', '=', '$tournament_id']],
				'primary' => true
			],
			'Pet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$pet_id']],
				'primary' => true
			],
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$user_id']],
				'primary' => true
			]
		];

		return $structure;
	}
}

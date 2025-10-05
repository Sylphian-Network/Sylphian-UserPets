<?php

namespace Sylphian\UserPets\Entity;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $tutorial_id
 * @property int $user_id
 * @property string $tutorial_key
 * @property bool $completed
 * @property int $completed_date
 *
 * RELATIONS
 * @property User $User
 */
class UserPetTutorial extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_tutorials';
		$structure->shortName = 'Sylphian\UserPets:UserPetTutorial';
		$structure->primaryKey = 'tutorial_id';

		$structure->columns = [
			'tutorial_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'tutorial_key' => ['type' => self::STR, 'required' => true, 'maxLength' => 50],
			'completed' => ['type' => self::BOOL, 'default' => false],
			'completed_date' => ['type' => self::UINT, 'default' => null, 'nullable' => true],
		];

		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
		];

		return $structure;
	}
}

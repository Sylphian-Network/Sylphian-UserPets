<?php

namespace Sylphian\UserPets\Entity;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int    $pet_id
 * @property int    $user_id
 * @property int    $hunger
 * @property int    $sleepiness
 * @property int    $happiness
 * @property string $state
 * @property int    $last_update
 * @property int    $last_action_time
 * @property int    $created_at
 *
 * RELATIONS
 * @property User $User
 */
class UserPets extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets';
		$structure->shortName = 'Sylphian\UserPets:UserPets';
		$structure->primaryKey = 'pet_id';
		$structure->columns = [
			'pet_id'     => ['type' => self::UINT, 'autoIncrement' => true],
			'user_id'    => ['type' => self::UINT, 'required' => true],
			'hunger'     => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'sleepiness' => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'happiness'  => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'state'      => ['type' => self::STR, 'default' => 'idle', 'maxLength' => 20],
			'last_update' => ['type' => self::UINT, 'default' => 0],
			'last_action_time' => ['type' => self::UINT, 'default' => 0],
			'created_at' => ['type' => self::UINT, 'default' => \XF::$time],
		];

		$structure->getters = [];

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

	protected function _preSave(): void
	{
		$this->hunger = max(0, min(100, $this->hunger));
		$this->sleepiness = max(0, min(100, $this->sleepiness));
		$this->happiness = max(0, min(100, $this->happiness));
	}
}

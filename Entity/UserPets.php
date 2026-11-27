<?php

namespace Sylphian\UserPets\Entity;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int    $pet_id
 * @property int    $user_id
 * @property int    $level
 * @property int    $experience
 * @property int    $hunger
 * @property int    $sleepiness
 * @property int    $happiness
 * @property string $state
 * @property int    $last_update
 * @property int    $last_action_time
 * @property int    $last_duel_time
 * @property int    $thread_reward_count
 * @property int    $thread_reward_experience
 * @property int    $post_reward_count
 * @property int    $post_reward_experience
 * @property int    $solution_reward_count
 * @property int    $solution_reward_experience
 * @property int    $created_at
 * @property string $pet_name
 * @property string $spritesheet File name of the chosen appearance; '' means the default one
 * @property bool   $is_disabled
 * @property bool   $sound_enabled
 * @property int    $duel_wins
 * @property int    $duel_losses
 * @property int    $tournament_wins
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
			'level'      => ['type' => self::UINT, 'default' => 1],
			'experience' => ['type' => self::UINT, 'default' => 0],
			'hunger'     => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'sleepiness' => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'happiness'  => ['type' => self::UINT, 'default' => 100, 'max' => 100],
			'state'      => ['type' => self::STR, 'default' => 'idle', 'maxLength' => 30],
			'last_update' => ['type' => self::UINT, 'default' => 0],
			'last_action_time' => ['type' => self::UINT, 'default' => 0],
			'last_duel_time' => ['type' => self::UINT, 'default' => 0],
			'thread_reward_count' => ['type' => self::UINT, 'default' => 0],
			'thread_reward_experience' => ['type' => self::UINT, 'default' => 0],
			'post_reward_count' => ['type' => self::UINT, 'default' => 0],
			'post_reward_experience' => ['type' => self::UINT, 'default' => 0],
			'solution_reward_count' => ['type' => self::UINT, 'default' => 0],
			'solution_reward_experience' => ['type' => self::UINT, 'default' => 0],
			'created_at' => ['type' => self::UINT, 'default' => \XF::$time],
			'pet_name' => ['type' => self::STR, 'default' => '', 'maxLength' => 50],
			'spritesheet' => ['type' => self::STR, 'default' => '', 'maxLength' => 255],
			'is_disabled' => ['type' => self::BOOL, 'default' => false],
			'sound_enabled' => ['type' => self::BOOL, 'default' => true],
			'duel_wins' => ['type' => self::UINT, 'default' => 0],
			'duel_losses' => ['type' => self::UINT, 'default' => 0],
			'tournament_wins' => ['type' => self::UINT, 'default' => 0],
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

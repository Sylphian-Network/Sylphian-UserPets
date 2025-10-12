<?php

namespace Sylphian\UserPets\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $duel_id
 * @property int $challenger_pet_id
 * @property int $opponent_pet_id
 * @property string $status
 * @property int $winner_pet_id
 * @property int $loser_pet_id
 * @property int $created_at
 * @property int $completed_at
 *
 * RELATIONS
 * @property UserPets $ChallengerPet
 * @property UserPets $OpponentPet
 * @property UserPets $WinnerPet
 * @property UserPets $LoserPet
 */
class UserPetsDuel extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_duels';
		$structure->shortName = 'Sylphian\UserPets:UserPetsDuel';
		$structure->primaryKey = 'duel_id';

		$structure->columns = [
			'duel_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'challenger_pet_id' => ['type' => self::UINT, 'required' => true],
			'opponent_pet_id' => ['type' => self::UINT, 'required' => true],
			'status' => [
				'type' => self::STR,
				'allowedValues' => ['pending', 'accepted', 'declined', 'completed'],
				'default' => 'pending',
			],
			'winner_pet_id' => ['type' => self::UINT, 'default' => 0],
			'loser_pet_id' => ['type' => self::UINT, 'default' => 0],
			'created_at' => ['type' => self::UINT, 'default' => \XF::$time],
			'completed_at' => ['type' => self::UINT, 'default' => 0],
		];

		$structure->relations = [
			'ChallengerPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$challenger_pet_id']],
				'primary' => true,
			],
			'OpponentPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$opponent_pet_id']],
				'primary' => true,
			],
			'WinnerPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$winner_pet_id']],
				'primary' => true,
			],
			'LoserPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$loser_pet_id']],
				'primary' => true,
			],
		];

		return $structure;
	}
}

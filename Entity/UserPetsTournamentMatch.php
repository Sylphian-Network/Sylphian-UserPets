<?php

namespace Sylphian\UserPets\Entity;

use Sylphian\UserPets\Service\Tournament\Bracket;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $match_id
 * @property int $tournament_id
 * @property int $round_number
 * @property int $match_number
 * @property int $pet_a_id
 * @property int $pet_b_id
 * @property int $winner_pet_id
 * @property int $loser_pet_id
 * @property string $status
 * @property int $resolved_at
 *
 * RELATIONS
 * @property UserPetsTournament $Tournament
 * @property UserPets|null $PetA
 * @property UserPets|null $PetB
 * @property UserPets|null $WinnerPet
 * @property UserPets|null $LoserPet
 */
class UserPetsTournamentMatch extends Entity
{
	public function isResolved(): bool
	{
		return $this->status === Bracket::STATUS_COMPLETED || $this->status === Bracket::STATUS_BYE;
	}

	/**
	 * Neither slot has a pet; such a match is resolved as a bye without a winner.
	 */
	public function isEmptyPair(): bool
	{
		return !$this->pet_a_id && !$this->pet_b_id;
	}

	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_tournament_matches';
		$structure->shortName = 'Sylphian\UserPets:UserPetsTournamentMatch';
		$structure->primaryKey = 'match_id';

		$structure->columns = [
			'match_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'tournament_id' => ['type' => self::UINT, 'required' => true],
			'round_number' => ['type' => self::UINT, 'required' => true],
			'match_number' => ['type' => self::UINT, 'required' => true],
			'pet_a_id' => ['type' => self::UINT, 'default' => 0],
			'pet_b_id' => ['type' => self::UINT, 'default' => 0],
			'winner_pet_id' => ['type' => self::UINT, 'default' => 0],
			'loser_pet_id' => ['type' => self::UINT, 'default' => 0],
			'status' => [
				'type' => self::STR,
				'allowedValues' => [
					Bracket::STATUS_WAITING,
					Bracket::STATUS_READY,
					Bracket::STATUS_BYE,
					Bracket::STATUS_COMPLETED,
				],
				'default' => Bracket::STATUS_WAITING
			],
			'resolved_at' => ['type' => self::UINT, 'default' => 0],
		];

		$structure->relations = [
			'Tournament' => [
				'entity' => 'Sylphian\UserPets:UserPetsTournament',
				'type' => self::TO_ONE,
				'conditions' => [['tournament_id', '=', '$tournament_id']],
				'primary' => true
			],
			'PetA' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$pet_a_id']],
				'primary' => true
			],
			'PetB' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$pet_b_id']],
				'primary' => true
			],
			'WinnerPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$winner_pet_id']],
				'primary' => true
			],
			'LoserPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$loser_pet_id']],
				'primary' => true
			],
		];

		return $structure;
	}
}

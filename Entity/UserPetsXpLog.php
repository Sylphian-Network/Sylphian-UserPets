<?php

namespace Sylphian\UserPets\Entity;

use Sylphian\UserPets\XpSource;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use XF\Phrase;

/**
 * One XP change of one pet. Rows are written by UserPetsXpLogRepository::log() and never edited.
 *
 * COLUMNS
 * @property int $log_id
 * @property int $pet_id
 * @property int $user_id
 * @property int $amount Positive or negative
 * @property string $source One of XpSource
 * @property string $content_type
 * @property int $content_id
 * @property int $actor_user_id The admin who made the change, for XpSource::ADMIN
 * @property int $level_after
 * @property int $log_date
 *
 * RELATIONS
 * @property-read UserPets|null $Pet
 * @property-read User|null $User
 * @property-read User|null $Actor
 */
class UserPetsXpLog extends Entity
{
	public function getSourcePhrase(): Phrase
	{
		$source = in_array($this->source, XpSource::all(), true) ? $this->source : XpSource::OTHER;

		return \XF::phrase('sylphian_userpets_xp_source_' . $source);
	}

	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_xp_log';
		$structure->shortName = 'Sylphian\UserPets:UserPetsXpLog';
		$structure->primaryKey = 'log_id';
		$structure->columns = [
			'log_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'pet_id' => ['type' => self::UINT, 'required' => true],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'amount' => ['type' => self::INT, 'default' => 0],
			'source' => ['type' => self::BINARY, 'maxLength' => 25, 'required' => true],
			'content_type' => ['type' => self::BINARY, 'maxLength' => 25, 'default' => ''],
			'content_id' => ['type' => self::UINT, 'default' => 0],
			'actor_user_id' => ['type' => self::UINT, 'default' => 0],
			'level_after' => ['type' => self::UINT, 'default' => 0],
			'log_date' => ['type' => self::UINT, 'default' => \XF::$time],
		];
		$structure->relations = [
			'Pet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => 'pet_id',
				'primary' => true,
			],
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
			'Actor' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$actor_user_id']],
				'primary' => true,
			],
		];

		return $structure;
	}
}

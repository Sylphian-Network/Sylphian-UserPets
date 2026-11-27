<?php

namespace Sylphian\UserPets\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * RELATIONS
 * @property-read \Sylphian\UserPets\Entity\UserPets|null $UserPet
 */
class User extends XFCP_User
{
	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);

		$structure->relations['UserPet'] = [
			'entity' => 'Sylphian\UserPets:UserPets',
			'type' => self::TO_ONE,
			'conditions' => 'user_id',
		];

		return $structure;
	}
}

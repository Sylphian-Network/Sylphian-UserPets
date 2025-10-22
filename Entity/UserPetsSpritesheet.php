<?php

namespace Sylphian\UserPets\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $spritesheet_id
 * @property string $filename
 * @property string $title
 * @property int $frame_width
 * @property int $frame_height
 * @property int $frames_per_animation
 * @property int $fps
 * @property int $last_modified
 */
class UserPetsSpritesheet extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_spritesheet';
		$structure->shortName = 'Sylphian\\UserPets:UserPetsSpritesheet';
		$structure->primaryKey = 'spritesheet_id';
		$structure->columns = [
			'spritesheet_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'filename' => ['type' => self::STR, 'required' => true, 'maxLength' => 255],
			'title' => ['type' => self::STR, 'default' => ''],
			'frame_width' => ['type' => self::UINT, 'default' => 192],
			'frame_height' => ['type' => self::UINT, 'default' => 192],
			'frames_per_animation' => ['type' => self::UINT, 'default' => 4],
			'fps' => ['type' => self::UINT, 'default' => 4, 'min' => 1, 'max' => 120],
			'last_modified' => ['type' => self::UINT, 'default' => 0],
		];
		return $structure;
	}
}

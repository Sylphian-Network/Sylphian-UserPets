<?php

namespace Sylphian\UserPets\Entity;

use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
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
 * @property int $evolve_level Pets at or above this level use evolves_to instead; 0 = no evolution
 * @property string $evolves_to File name of the next appearance
 */
class UserPetsSpritesheet extends Entity
{
	protected function verifyFilename(&$filename): bool
	{
		/** @var UserPetsSpritesheetRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		$normalized = $repo->normalizeFilename((string) $filename);
		if ($normalized === null)
		{
			$this->error(\XF::phrase('sylphian_userpets_error_invalid_filename'), 'filename');
			return false;
		}

		$filename = $normalized;
		return true;
	}

	protected function verifyEvolvesTo(&$evolvesTo): bool
	{
		$evolvesTo = trim((string) $evolvesTo);
		if ($evolvesTo === '')
		{
			return true;
		}

		/** @var UserPetsSpritesheetRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		$normalized = $repo->normalizeFilename($evolvesTo);
		if ($normalized === null)
		{
			$this->error(\XF::phrase('sylphian_userpets_error_evolution_target_missing'), 'evolves_to');
			return false;
		}

		$evolvesTo = $normalized;
		return true;
	}

	/**
	 * The invariants every save keeps; whether the target exists and the chain stays
	 * free of loops is checked by UserPetsSpritesheetRepository::getEvolutionErrors().
	 */
	protected function _preSave()
	{
		if ($this->evolves_to === '')
		{
			if ($this->evolve_level !== 0)
			{
				$this->evolve_level = 0;
			}

			return;
		}

		if ($this->evolves_to === $this->filename)
		{
			$this->error(\XF::phrase('sylphian_userpets_error_evolution_self'), 'evolves_to');
		}

		if ($this->evolve_level < UserPetsSpritesheetRepository::MIN_EVOLVE_LEVEL)
		{
			$this->error(\XF::phrase('sylphian_userpets_error_evolution_level', [
				'level' => UserPetsSpritesheetRepository::MIN_EVOLVE_LEVEL,
			]), 'evolve_level');
		}
	}

	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_spritesheet';
		$structure->shortName = 'Sylphian\\UserPets:UserPetsSpritesheet';
		$structure->primaryKey = 'spritesheet_id';
		$structure->columns = [
			'spritesheet_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'filename' => [
				'type' => self::STR,
				'required' => true,
				'maxLength' => 255,
				'unique' => 'sylphian_userpets_error_spritesheet_filename_exists',
			],
			'title' => ['type' => self::STR, 'default' => '', 'maxLength' => 100],
			'frame_width' => ['type' => self::UINT, 'default' => 192, 'min' => 1, 'max' => 65535],
			'frame_height' => ['type' => self::UINT, 'default' => 192, 'min' => 1, 'max' => 65535],
			'frames_per_animation' => ['type' => self::UINT, 'default' => 4, 'min' => 1, 'max' => 65535],
			'fps' => ['type' => self::UINT, 'default' => 4, 'min' => 1, 'max' => 120],
			'last_modified' => ['type' => self::UINT, 'default' => 0],
			'evolve_level' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
			'evolves_to' => ['type' => self::STR, 'default' => '', 'maxLength' => 255],
		];
		return $structure;
	}
}

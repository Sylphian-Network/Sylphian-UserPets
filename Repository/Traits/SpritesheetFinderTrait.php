<?php

namespace Sylphian\UserPets\Repository\Traits;

use Sylphian\UserPets\Entity\UserPetsSpritesheet;

trait SpritesheetFinderTrait
{
	/**
	 * Find a spritesheet entity by its numeric ID.
	 *
	 * @param int $id Spritesheet primary key.
	 * @return UserPetsSpritesheet|null The entity or null if not found.
	 */
	public function findById(int $id): ?UserPetsSpritesheet
	{
		if ($id <= 0)
		{
			return null;
		}
		/** @var UserPetsSpritesheet|null $e */
		$e = $this->em->find('Sylphian\UserPets:UserPetsSpritesheet', $id);
		return $e;
	}

	/**
	 * Find a spritesheet entity by its filename.
	 *
	 * @param string $filename The file’s name (including extension).
	 * @return UserPetsSpritesheet|null The entity or null if not found.
	 */
	public function findByFilename(string $filename): ?UserPetsSpritesheet
	{
		$filename = trim($filename);
		if ($filename === '')
		{
			return null;
		}
		/** @var UserPetsSpritesheet|null $e */
		$e = $this->finder('Sylphian\UserPets:UserPetsSpritesheet')
			->where('filename', $filename)
			->fetchOne();
		return $e;
	}
}

<?php

namespace Sylphian\UserPets\Alert;

use XF\Alert\AbstractHandler;
use XF\Mvc\Entity\Entity;

class UserPetHandler extends AbstractHandler
{
	public function getEntityWith(): array
	{
		return ['User'];
	}

	public function getOptOutActions(): array
	{
		return [
			'levelup',  // Pet leveled up
			'tutorial', // Pet tutorial completed
		];
	}

	public function getOptOutDisplayOrder(): int
	{
		return 500;
	}

	public function canViewContent(Entity $entity, &$error = null): bool
	{
		return true;
	}
}

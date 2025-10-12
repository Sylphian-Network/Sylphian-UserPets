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
			'levelup',          // Pet leveled up
			'tutorial',         // Pet tutorial completed
			'duel_challenge',   // Pet duel challenge received
			'duel_declined',    // Pet duel challenge declined
			'duel_win',         // Pet won a duel
			'duel_loss',        // Pet lost a duel
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

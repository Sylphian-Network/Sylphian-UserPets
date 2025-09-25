<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\PetManager;
use XF\Entity\User;
use XF\Entity\Widget;
use XF\Widget\AbstractWidget;
use XF\Widget\WidgetRenderer;

class UserPetWidget extends AbstractWidget
{
	public function render(?Widget $widget = null): ?WidgetRenderer
	{
		$visitor = \XF::visitor();
		if (!$visitor->user_id)
		{
			return null; // Guests can't have pets
		}

		$profileViewing = $this->contextParams['user']['user_id'] ?? null;

		if ($profileViewing === null || $profileViewing === $visitor->user_id)
		{
			// Viewing own pet
			$pet = $this->getOrCreatePet($visitor->user_id);

			$spriteSheetPath = $this->getSpriteSheetPathForUser($visitor);

			$actionUrl = $this->app()->router()->buildLink('userPets/actions');

			$petManager = new PetManager($pet);
			$petManager->updateStats();

			return $this->renderer('sylphian_userpets_own_widget', [
				'widget' => $widget,
				'pet' => $pet,
				'actionUrl' => $actionUrl,
				'spriteSheetPath' => $spriteSheetPath,
			]);
		}
		else
		{
			// Viewing someone else's profile
			$pet = $this->getExistingPet($profileViewing);

			if (!$pet)
			{
				return null;
			}

			$profileUser = $this->em()->find('XF:User', $profileViewing);
			$spriteSheetPath = $this->getSpriteSheetPathForUser($profileUser);

			$petManager = new PetManager($pet);
			$petManager->updateStats();

			return $this->renderer('sylphian_userpets_other_widget', [
				'widget' => $widget,
				'pet' => $pet,
				'spriteSheetPath' => $spriteSheetPath,
			]);
		}
	}

	/**
	 * Gets the sprite sheet path for a specific user
	 * Uses their custom field value if set, falls back to default
	 */
	protected function getSpriteSheetPathForUser(?User $user): string
	{
		$defaultSpritesheet = \XF::options()->sylphian_userpets_default_spritesheet;

		$customSpritesheet = null;

		if ($user->user_id)
		{
			$customFields = $user->Profile->custom_fields;
			$customSpritesheet = $customFields['syl_userpets_spritesheet'] ?? null;
		}

		$selectedSpritesheet = $customSpritesheet ?: $defaultSpritesheet;

		return $this->app()->options()->publicPath . '/data/assets/sylphian/userpets/spritesheets/' . $selectedSpritesheet . '.png';
	}

	/**
	 * Fetch an existing pet or create one if missing.
	 * Used for the visitor's own pet.
	 */
	protected function getOrCreatePet(int $userId): UserPets
	{
		$pet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->fetchOne();

		if (!$pet)
		{
			/** @var UserPets $pet */
			$pet = $this->em()->create('Sylphian\UserPets:UserPets');
			$pet->user_id = $userId;
			$pet->hunger = 100;
			$pet->sleepiness = 100;
			$pet->happiness = 100;
			$pet->state = 'idle';
			$pet->last_update = \XF::$time;
			$pet->last_action_time = 0;
			$pet->created_at = \XF::$time;
			$pet->save();
		}

		return $pet;
	}

	/**
	 * Fetch a pet for another user but NEVER create it.
	 * Returns null if no pet exists.
	 */
	protected function getExistingPet(int $userId): ?UserPets
	{
		return $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->fetchOne();
	}
}

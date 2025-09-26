<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\PetManager;
use XF\Entity\User;
use XF\Entity\Widget;
use XF\PrintableException;
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
			try
			{
				$petManager->updateStats();
			}
			catch (PrintableException $e)
			{
				Logger::error(
					'Failed to update pet stats for user_id ' . $visitor->user_id,
					['error' => $e->getMessage(), 'trace' => $e->getTrace()]
				);
			}

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
				return null; // No pet exists for this user
			}

			$profileUser = $this->em()->find('XF:User', $profileViewing);
			$spriteSheetPath = $this->getSpriteSheetPathForUser($profileUser);

			$petManager = new PetManager($pet);
			try
			{
				$petManager->updateStats();
			}
			catch (PrintableException $e)
			{
				Logger::error(
					'Failed to update pet stats for user_id ' . $profileViewing,
					['error' => $e->getMessage(), 'trace' => $e->getTrace()]
				);
			}

			return $this->renderer('sylphian_userpets_other_widget', [
				'widget' => $widget,
				'pet' => $pet,
				'spriteSheetPath' => $spriteSheetPath,
			]);
		}
	}

	/**
	 * Gets the sprite sheet path for a specific user.
	 *
	 * Uses the user's custom field value if set, otherwise falls back to default.
	 *
	 * @param User|null $user User entity or null
	 * @return string Full path to the sprite sheet PNG
	 */
	protected function getSpriteSheetPathForUser(?User $user): string
	{
		$defaultSpritesheet = \XF::options()->sylphian_userpets_default_spritesheet;
		$customSpritesheet = null;

		if ($user?->user_id)
		{
			$customFields = $user->Profile->custom_fields ?? [];
			$customSpritesheet = $customFields['syl_userpets_spritesheet'] ?? null;
		}

		$selectedSpritesheet = $customSpritesheet ?: $defaultSpritesheet;
		return $this->app()->options()->publicPath
			. '/data/assets/sylphian/userpets/spritesheets/' . $selectedSpritesheet . '.png';
	}

	/**
	 * Fetch an existing pet or create one if missing.
	 *
	 * Ensures the visitor always has a pet.
	 *
	 * @param int $userId User ID to fetch/create pet for
	 * @return UserPets The pet entity
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

			try
			{
				$pet->save();
			}
			catch (\Exception $e)
			{
				Logger::error(
					"Failed to create pet for user_id {$userId}.",
					['error' => $e->getMessage(), 'trace' => $e->getTrace()]
				);
			}
		}

		return $pet;
	}

	/**
	 * Fetch a pet for another user but NEVER create it.
	 *
	 * @param int $userId User ID to fetch pet for
	 * @return UserPets|null The pet entity, or null if none exists
	 */
	protected function getExistingPet(int $userId): ?UserPets
	{
        /** @var UserPets|null $pet */
		$pet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->fetchOne();

        return $pet;
	}
}

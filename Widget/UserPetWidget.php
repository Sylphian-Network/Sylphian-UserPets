<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use Sylphian\UserPets\Service\PetLeveling;
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

			$petLeveling = new PetLeveling();
			$levelProgress = $petLeveling->getLevelProgressPercentage($pet);
			$expNeeded = $petLeveling->getExperienceNeededToLevelUp($pet);

			$tutorials = [];
			$allCompleted = true;
			if (\XF::options()->sylphian_userpets_enable_tutorial)
			{
				/** @var UserPetsTutorialRepository $tutorialRepo */
				$tutorialRepo = $this->repository('Sylphian\UserPets:UserPetsTutorial');
				$tutorials = $tutorialRepo->getUserTutorials($visitor->user_id);

				foreach ($tutorials AS $tutorial)
				{
					if (!$tutorial['completed'])
					{
						$allCompleted = false;
						break;
					}
				}

				if ($allCompleted && !empty($tutorials))
				{
					$tutorials = [];
				}
			}

			return $this->renderer('sylphian_userpets_own_widget', [
				'widget' => $widget,
				'pet' => $pet,
				'custom_pet_name' => $this->getCustomName($visitor),
				'actionUrl' => $actionUrl,
				'spriteSheetPath' => $spriteSheetPath,
				'levelProgress' => $levelProgress,
				'expNeeded' => $expNeeded,
				'tutorial' => $tutorials,
			]);
		}
		else
		{
			// Viewing someone else's profile
			/** @var UserPetsRepository $repo */
			$repo = $this->repository('Sylphian\UserPets:UserPets');
			$pet = $repo->getUserPet($profileViewing);

			if (!$pet)
			{
				return null; // No pet exists for this user
			}

			$profileUser = $this->em()->find('XF:User', $profileViewing);
			$spriteSheetPath = $this->getSpriteSheetPathForUser($profileUser);
			$customName = $this->getCustomName($profileUser);

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

			$petLeveling = new PetLeveling();
			$levelProgress = $petLeveling->getLevelProgressPercentage($pet);
			$expNeeded = $petLeveling->getExperienceNeededToLevelUp($pet);

			return $this->renderer('sylphian_userpets_other_widget', [
				'widget' => $widget,
				'pet' => $pet,
				'custom_pet_name' => $customName,
				'spriteSheetPath' => $spriteSheetPath,
				'levelProgress' => $levelProgress,
				'expNeeded' => $expNeeded,
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
	 * Gets the custom pet name for that user.
	 *
	 * Uses the user's custom field value if set, otherwise falls back to null.
	 *
	 * @param User|null $user User entity or null
	 * @return string|null The custom name or null
	 */
	protected function getCustomName(?User $user): string|null
	{
		$customName = null;

		if ($user?->user_id)
		{
			$customFields = $user->Profile->custom_fields ?? [];
			$customName = $customFields['syl_userpets_custom_name'] ?? null;
		}

		return $customName;
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
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $repo->getUserPet($userId);

		if (!$pet)
		{
			$pet = $repo->createPet($userId);
		}

		return $pet;
	}
}

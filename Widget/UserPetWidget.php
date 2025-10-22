<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
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

			$renderConfig = $this->getSpritesheetRenderConfigForUser($visitor);

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
				$tutorialRepo = $this->repository('Sylphian\\UserPets:UserPetsTutorial');
				$tutorials = $tutorialRepo->getUserTutorials($visitor->user_id);
				foreach ($tutorials AS $t)
				{
					if (!$t['completed'])
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
				'widget'        => $widget,
				'pet'           => $pet,
				'custom_pet_name' => $this->getCustomName($visitor),
				'actionUrl'     => $actionUrl,
				'levelProgress' => $levelProgress,
				'expNeeded'     => $expNeeded,
				'petRender'     => $renderConfig,
				'scaleMin'      => \XF::options()->sylphian_userpets_scale_min ?? 0.5,
				'scaleMax'      => \XF::options()->sylphian_userpets_scale_max ?? 1.0,
			]);
		}
		else
		{
			// Viewing someone else's profile
			/** @var UserPetsRepository $repo */
			$repo = $this->repository('Sylphian\\UserPets:UserPets');
			$pet = $repo->getUserPet($profileViewing);
			if (!$pet)
			{
				return null;
			}

			$profileUser = $this->em()->find('XF:User', $profileViewing);

			$renderConfig = $this->getSpritesheetRenderConfigForUser($profileUser);

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
				'widget'        => $widget,
				'pet'           => $pet,
				'custom_pet_name' => $this->getCustomName($profileUser),
				'levelProgress' => $levelProgress,
				'expNeeded'     => $expNeeded,
				'petRender'     => $renderConfig,
				'scaleMin'      => \XF::options()->sylphian_userpets_scale_min ?? 0.5,
				'scaleMax'      => \XF::options()->sylphian_userpets_scale_max ?? 1.0,
			]);
		}
	}

	/**
	 * Resolve the spritesheet URL and DB-backed render config for a user.
	 *
	 * @param User|null $user
	 * @return array{url:string, frame_width:int, frame_height:int, frames_per_animation:int, fps:int}
	 */
	protected function getSpritesheetRenderConfigForUser(?User $user): array
	{
		$defaultSpritesheet = (string) \XF::options()->sylphian_userpets_default_spritesheet;
		$selected = $defaultSpritesheet;

		if ($user?->user_id)
		{
			$custom = $user->Profile->custom_fields['syl_userpets_spritesheet'] ?? '';
			if ($custom !== '')
			{
				$selected = (string) $custom;
			}
		}

		$filename = $selected;
		if (!preg_match('/\.(png|gif|jpe?g|webp)$/i', $filename))
		{
			$filename .= '.png';
		}

		/** @var UserPetsSpritesheetRepository $ssRepo */
		$ssRepo = $this->repository('Sylphian\\UserPets:UserPetsSpritesheetRepository');

		$entity = method_exists($ssRepo, 'findByFilename')
			? $ssRepo->findByFilename($filename)
			: null;

		$frameW = $entity?->frame_width ?: 192;
		$frameH = $entity?->frame_height ?: 192;
		$fpa    = $entity?->frames_per_animation ?: 4;
		$fps    = $entity?->fps ?: 4;

		$url = rtrim($ssRepo->getBaseUrl(), '/') . '/' . rawurlencode($filename);

		return [
			'url' => $url,
			'frame_width' => $frameW,
			'frame_height' => $frameH,
			'frames_per_animation' => $fpa,
			'fps' => $fps,
		];
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

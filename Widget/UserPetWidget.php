<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\DuelPermission;
use Sylphian\UserPets\Helper\PetState;
use Sylphian\UserPets\Helper\UserPetOptOut;
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

/**
 * Rendering never recounts content XP: the content save listeners and the rebuild job
 * keep it in step. The only write here is the stat decay, at most once per update interval.
 */
class UserPetWidget extends AbstractWidget
{
	public const DEFAULT_SPRITESHEET = 'sylphian_spritesheet.png';

	public function render(?Widget $widget = null): ?WidgetRenderer
	{
		$visitor = \XF::visitor();
		if (!$visitor->user_id)
		{
			return null;
		}

		$profileUser = $this->getProfileUser();

		if (!$profileUser || $profileUser->user_id === $visitor->user_id)
		{
			return $this->renderOwnPet($widget, $visitor);
		}

		return $this->renderOtherPet($widget, $profileUser);
	}

	protected function renderOwnPet(?Widget $widget, User $visitor): ?WidgetRenderer
	{
		if (UserPetOptOut::isDisabledForUser($visitor))
		{
			return null;
		}

		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $repo->getOrCreatePet($visitor->user_id);
		if (!$pet->pet_id)
		{
			return null;
		}

		$this->updatePetStats($pet);

		$petLeveling = new PetLeveling();

		$cooldownTime = max(0, (int) \XF::options()->sylphian_userpets_cooldown_time);
		$cooldownRemaining = max(0, $cooldownTime - (\XF::$time - (int) $pet->last_action_time));

		return $this->renderer('sylphian_userpets_own_widget', [
			'pet' => $pet,
			'customPetName' => $this->getCustomName($visitor),
			'actionUrl' => $this->app()->router()->buildLink('userPets/actions'),
			'levelProgress' => $petLeveling->getLevelProgressPercentage($pet),
			'expNeeded' => $petLeveling->getExperienceNeededToLevelUp($pet),
			'petRender' => $this->getSpritesheetRenderConfigForUser($visitor),
			'ownerDisplayName' => DisplayName::decode($visitor->username),
			'ownerPetTitle' => DisplayName::renderUsernamePhrase(
				'sylphian_userpets_widget_title',
				$visitor->username
			),
			'stateDisplay' => PetState::getDisplay($pet->state),
			'animationState' => PetState::getPrimaryState($pet->state),
			'stateIds' => PetState::getStateIds($pet->state),
			'cooldownRemaining' => $cooldownRemaining,
			'tutorial' => $this->getOpenTutorials($visitor->user_id),
			'scaleMin' => \XF::options()->sylphian_userpets_scale_min ?? 0.5,
			'scaleMax' => \XF::options()->sylphian_userpets_scale_max ?? 1.0,
		]);
	}

	protected function renderOtherPet(?Widget $widget, User $profileUser): ?WidgetRenderer
	{
		if (UserPetOptOut::isDisabledForUser($profileUser))
		{
			return null;
		}

		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $repo->getUserPet($profileUser->user_id);
		if (!$pet)
		{
			return null;
		}

		$this->updatePetStats($pet);

		$petLeveling = new PetLeveling();

		return $this->renderer('sylphian_userpets_other_widget', [
			'pet' => $pet,
			'custom_pet_name' => $this->getCustomName($profileUser),
			'levelProgress' => $petLeveling->getLevelProgressPercentage($pet),
			'expNeeded' => $petLeveling->getExperienceNeededToLevelUp($pet),
			'petRender' => $this->getSpritesheetRenderConfigForUser($profileUser),
			'ownerDisplayName' => DisplayName::decode($profileUser->username),
			'ownerPetTitle' => DisplayName::renderUsernamePhrase(
				'sylphian_userpets_widget_title',
				$profileUser->username
			),
			'canChallengePets' => \XF::options()->sylphian_userpets_duel_enable
				&& DuelPermission::getCooldownPolicy(\XF::visitor())['allowed'],
			'stateDisplay' => PetState::getDisplay($pet->state),
			'animationState' => PetState::getPrimaryState($pet->state),
			'stateIds' => PetState::getStateIds($pet->state),
			'scaleMin' => \XF::options()->sylphian_userpets_scale_min ?? 0.5,
			'scaleMax' => \XF::options()->sylphian_userpets_scale_max ?? 1.0,
		]);
	}

	protected function getProfileUser(): ?User
	{
		$user = $this->contextParams['user'] ?? null;
		if ($user instanceof User)
		{
			return $user->user_id ? $user : null;
		}

		$userId = (int) ($user['user_id'] ?? 0);
		if (!$userId)
		{
			return null;
		}

		/** @var User|null $found */
		$found = $this->em()->find('XF:User', $userId, ['Profile']);

		return $found;
	}

	protected function updatePetStats(UserPets $pet): void
	{
		try
		{
			(new PetManager($pet))->updateStats();
		}
		catch (PrintableException $e)
		{
			Logger::error(
				'Failed to update pet stats for user_id ' . $pet->user_id,
				['error' => $e->getMessage()]
			);
		}
	}

	/**
	 * @return array<int,array{tutorial_id:string,title:string,completed:bool}> Empty when the
	 *     tutorial is disabled or every step is done
	 */
	protected function getOpenTutorials(int $userId): array
	{
		if (!\XF::options()->sylphian_userpets_enable_tutorial)
		{
			return [];
		}

		/** @var UserPetsTutorialRepository $tutorialRepo */
		$tutorialRepo = $this->repository('Sylphian\UserPets:UserPetsTutorial');
		$tutorials = $tutorialRepo->getUserTutorials($userId);

		foreach ($tutorials AS $tutorial)
		{
			if (!$tutorial['completed'])
			{
				return $tutorials;
			}
		}

		return [];
	}

	/**
	 * Resolve the spritesheet URL and render config for a user from the cached spritesheet index.
	 *
	 * @param User|null $user
	 * @return array{url:string, frame_width:int, frame_height:int, frames_per_animation:int, fps:int, usingDefault:bool}
	 */
	protected function getSpritesheetRenderConfigForUser(?User $user): array
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$index = $repo->getSpritesheetRenderIndex();
		$choices = $index['choices'];

		$defaultFilename = $repo->resolveSpritesheetFilenameFromMap(
			(string) \XF::options()->sylphian_userpets_default_spritesheet,
			$choices
		) ?? self::DEFAULT_SPRITESHEET;

		$selectedFilename = $defaultFilename;
		$usingDefault = true;

		if ($user?->user_id)
		{
			$custom = trim((string) ($user->Profile->custom_fields['syl_userpets_spritesheet'] ?? ''));
			$resolvedCustom = $custom !== '' ? $repo->resolveSpritesheetFilenameFromMap($custom, $choices) : null;
			if ($resolvedCustom !== null)
			{
				$selectedFilename = $resolvedCustom;
				$usingDefault = false;
			}
		}

		$sheet = $index['sheets'][$selectedFilename] ?? [];

		/** @var UserPetsSpritesheetRepository $ssRepo */
		$ssRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		return [
			'url' => rtrim($ssRepo->getBaseUrl(), '/') . '/' . rawurlencode($selectedFilename),
			'frame_width' => (int) ($sheet['frame_width'] ?? 192),
			'frame_height' => (int) ($sheet['frame_height'] ?? 192),
			'frames_per_animation' => (int) ($sheet['frames_per_animation'] ?? 4),
			'fps' => (int) ($sheet['fps'] ?? 4),
			'usingDefault' => $usingDefault,
		];
	}

	/**
	 * Gets the custom pet name for that user.
	 *
	 * @param User|null $user User entity or null
	 * @return string|null The custom name or null
	 */
	protected function getCustomName(?User $user): ?string
	{
		if (!$user?->user_id)
		{
			return null;
		}

		$customName = trim((string) ($user->Profile->custom_fields['syl_userpets_custom_name'] ?? ''));

		return $customName !== '' ? $customName : null;
	}
}

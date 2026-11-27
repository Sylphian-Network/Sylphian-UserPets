<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\PetState;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use Sylphian\UserPets\Service\PetLeveling;
use Sylphian\UserPets\Service\PetManager;
use XF\Entity\User;
use XF\Entity\Widget;
use XF\Widget\AbstractWidget;
use XF\Widget\WidgetRenderer;

/**
 * Rendering never writes to an existing pet: the stat decay is applied in memory only and is
 * saved by the next action, duel or tournament. The one write is creating the visitor's pet
 * row the first time they see their own widget.
 */
class UserPetWidget extends AbstractWidget
{
	public function render(?Widget $widget = null): ?WidgetRenderer
	{
		$visitor = \XF::visitor();
		$profileUser = $this->getProfileUser();

		if (!$visitor->user_id)
		{
			if (!$profileUser || !\XF::options()->sylphian_userpets_guest_view)
			{
				return null;
			}

			return $this->renderOtherPet($widget, $profileUser);
		}

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

		$repo = $this->getPetRepo();
		$pet = $visitor->UserPet;
		if (!$pet)
		{
			$pet = $repo->getOrCreatePet((int) $visitor->user_id);
			if (!$pet->pet_id)
			{
				return null;
			}

			$visitor->hydrateRelation('UserPet', $pet);
		}

		$this->applyStatDecay($pet);

		$petLeveling = new PetLeveling();

		$cooldownTime = max(0, (int) \XF::options()->sylphian_userpets_cooldown_time);
		$cooldownRemaining = max(0, $cooldownTime - (\XF::$time - (int) $pet->last_action_time));

		return $this->renderer('sylphian_userpets_own_widget', [
			'pet' => $pet,
			'customPetName' => $this->getDisplayPetName($pet),
			'levelProgress' => $petLeveling->getLevelProgressPercentage($pet),
			'expNeeded' => $petLeveling->getExperienceNeededToLevelUp($pet),
			'petRender' => $repo->getRenderConfigForPet($pet),
			'soundEnabled' => (bool) $pet->sound_enabled,
			'ownerDisplayName' => DisplayName::decode($visitor->username),
			'ownerPetTitle' => DisplayName::renderUsernamePhrase(
				'sylphian_userpets_widget_title',
				$visitor->username
			),
			'stateDisplay' => PetState::getDisplay($pet->state),
			'animationState' => PetState::getPrimaryState($pet->state),
			'stateIds' => PetState::getStateIds($pet->state),
			'cooldownRemaining' => $cooldownRemaining,
			'tutorial' => $this->getOpenTutorials((int) $visitor->user_id),
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

		$pet = $profileUser->UserPet;
		if (!$pet)
		{
			return null;
		}

		$this->applyStatDecay($pet);

		$petLeveling = new PetLeveling();

		return $this->renderer('sylphian_userpets_other_widget', [
			'pet' => $pet,
			'customPetName' => $this->getDisplayPetName($pet),
			'levelProgress' => $petLeveling->getLevelProgressPercentage($pet),
			'expNeeded' => $petLeveling->getExperienceNeededToLevelUp($pet),
			'petRender' => $this->getPetRepo()->getRenderConfigForPet($pet),
			'ownerDisplayName' => DisplayName::decode($profileUser->username),
			'ownerPetTitle' => DisplayName::renderUsernamePhrase(
				'sylphian_userpets_widget_title',
				$profileUser->username
			),
			'canChallengePets' => $this->canVisitorChallenge(\XF::visitor()),
			'stateDisplay' => PetState::getDisplay($pet->state),
			'animationState' => PetState::getPrimaryState($pet->state),
			'stateIds' => PetState::getStateIds($pet->state),
			'scaleMin' => \XF::options()->sylphian_userpets_scale_min ?? 0.5,
			'scaleMax' => \XF::options()->sylphian_userpets_scale_max ?? 1.0,
		]);
	}

	protected function canVisitorChallenge(User $visitor): bool
	{
		return $visitor->user_id
			&& \XF::options()->sylphian_userpets_duel_enable
			&& $visitor->hasPermission(UserPetOptOut::PERMISSION_GROUP, 'duel')
			&& !UserPetOptOut::isDisabledForUser($visitor);
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
		$found = $this->em()->find('XF:User', $userId, ['UserPet', 'PermissionCombination']);

		return $found;
	}

	protected function applyStatDecay(UserPets $pet): void
	{
		(new PetManager($pet))->updateStats(false);
	}

	protected function getDisplayPetName(UserPets $pet): ?string
	{
		$name = trim((string) $pet->pet_name);

		return $name !== '' ? $this->app()->stringFormatter()->censorText($name) : null;
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

	protected function getPetRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');

		return $repo;
	}
}

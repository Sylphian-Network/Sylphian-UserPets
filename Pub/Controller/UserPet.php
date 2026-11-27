<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\PetState;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\PetLeveling;
use Sylphian\UserPets\Service\PetManager;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

class UserPet extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertRegistrationRequired();

		if (!\XF::visitor()->hasPermission(UserPetOptOut::PERMISSION_GROUP, UserPetOptOut::PERMISSION_HAVE_PET))
		{
			throw $this->exception($this->noPermission());
		}
	}

	public function actionUpdate(): View|Error
	{
		$this->assertPostOnly();

		$pet = $this->assertUsablePet();

		$cooldownTime = max(0, (int) \XF::options()->sylphian_userpets_cooldown_time);
		$timeSinceLastAction = \XF::$time - (int) $pet->last_action_time;

		if ($timeSinceLastAction < $cooldownTime)
		{
			$cooldownRemaining = $cooldownTime - $timeSinceLastAction;

			$reply = $this->error(\XF::phrase('sylphian_userpets_cooldown_active', [
				'cooldownRemaining' => \XF::language()->numberFormat($cooldownRemaining),
			]));
			$reply->setJsonParam('cooldownRemaining', $cooldownRemaining);

			return $reply;
		}

		$action = $this->filter('action', 'str');
		if (!PetManager::isValidAction($action))
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_action'));
		}

		if (!(new PetManager($pet))->performAction($action))
		{
			return $this->error(\XF::phrase('sylphian_userpets_action_error'));
		}

		$petLeveling = new PetLeveling();
		$expNeeded = $petLeveling->getExperienceNeededToLevelUp($pet);

		$view = $this->view();
		$view->setJsonParams([
			'success' => true,
			'message' => \XF::phrase("sylphian_userpets_{$action}_success"),
			'hunger' => $pet->hunger,
			'happiness' => $pet->happiness,
			'sleepiness' => $pet->sleepiness,
			'state' => $pet->state,
			'animationState' => PetState::getPrimaryState($pet->state),
			'stateDisplay' => PetState::getDisplay($pet->state),
			'levelText' => \XF::phrase('sylphian_userpets_level', ['level' => $pet->level]),
			'expNeededText' => \XF::phrase('sylphian_userpets_exp_to_level', ['exp' => $expNeeded]),
			'level' => $pet->level,
			'experience' => $pet->experience,
			'levelProgress' => $petLeveling->getLevelProgressPercentage($pet),
			'expNeeded' => $expNeeded,
			'cooldownTime' => $cooldownTime,
			'last_action_time' => $pet->last_action_time,
			'server_time' => \XF::$time,
		]);

		return $view;
	}

	/**
	 * @throws \XF\Mvc\Reply\Exception Without a pet, or when the member disabled it
	 */
	protected function assertUsablePet(): UserPets
	{
		$visitor = \XF::visitor();

		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $visitor->UserPet ?? $repo->getUserPet((int) $visitor->user_id);

		if (!$pet)
		{
			throw $this->exception($this->error(\XF::phrase('sylphian_userpets_pet_not_found')));
		}

		if ($pet->is_disabled)
		{
			throw $this->exception($this->error(\XF::phrase('sylphian_userpets_error_pet_disabled')));
		}

		return $pet;
	}
}

<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\PetLeveling;
use Sylphian\UserPets\Service\PetManager;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

class UserPet extends AbstractController
{
	public function actionUpdate(): View|Error
	{
		$this->assertPostOnly();

		$visitor = \XF::visitor();
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $repo->getUserPet($visitor->user_id);

		if (!$pet)
		{
			return $this->error(\XF::phrase('sylphian_userpets_pet_not_found'));
		}

		$lastActionTime = $pet->last_action_time ?? 0;
		$cooldownTime = \XF::options()->sylphian_userpets_cooldown_time;
		$currentTime = \XF::$time;
		$timeSinceLastAction = $currentTime - $lastActionTime;

		if ($timeSinceLastAction < $cooldownTime)
		{
			$cooldownRemaining = $cooldownTime - $timeSinceLastAction;

			return $this->error(\XF::phrase('sylphian_userpets_cooldown_active'), [
				'cooldownRemaining' => $cooldownRemaining,
			]);
		}

		$action = $this->filter('action', 'str');
		$validActions = ['feed', 'play', 'sleep'];

		if (!in_array($action, $validActions))
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_action'));
		}

		$petManager = new PetManager($pet);
		$petManager->performAction($action);

		$pet->last_action_time = $currentTime;
		$pet->save();

		$petLeveling = new PetLeveling();
		$levelProgress = $petLeveling->getLevelProgressPercentage($pet);
		$expNeeded = $petLeveling->getExperienceNeededToLevelUp($pet);

		$message = \XF::phrase("sylphian_userpets_{$action}_success");

		$view = $this->view();
		$view->setJsonParams([
			'success' => true,
			'message' => $message,
			'hunger' => $pet->hunger,
			'happiness' => $pet->happiness,
			'sleepiness' => $pet->sleepiness,
			'state' => $pet->state,
			// Pre-formatted phrases
			'levelText' => \XF::phrase('sylphian_userpets_level', ['level' => $pet->level]),
			'expNeededText' => \XF::phrase('sylphian_userpets_exp_to_level', ['exp' => $expNeeded]),
			// Experience-related data
			'level' => $pet->level,
			'experience' => $pet->experience,
			'levelProgress' => $levelProgress,
			'expNeeded' => $expNeeded,
			// Timing data
			'cooldownTime' => \XF::options()->sylphian_userpets_cooldown_time,
			'last_action_time' => $currentTime,
			'server_time' => \XF::$time,
		]);

		return $view;
	}
}

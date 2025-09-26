<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\UserPets\Entity\UserPets;
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
		/** @var UserPets $pet */
		$pet = $this->em()->find('Sylphian\UserPets:UserPets', $visitor->user_id);

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

		$message = \XF::phrase("sylphian_userpets_{$action}_success");

		$view = $this->view();
		$view->setJsonParams([
			'success' => true,
			'message' => $message,
			'hunger' => $pet->hunger,
			'happiness' => $pet->happiness,
			'sleepiness' => $pet->sleepiness,
			'state' => $pet->state,
			'cooldownTime' => \XF::options()->sylphian_userpets_cooldown_time,
			'last_action_time' => $currentTime,
			'server_time' => \XF::$time,
		]);

		return $view;
	}
}

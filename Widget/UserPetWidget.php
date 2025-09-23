<?php

namespace Sylphian\UserPets\Widget;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\PetManager;
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
			return null;
		}

		/** @var UserPets $pet */
		$pet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $visitor->user_id)
			->fetchOne();

		if (!$pet)
		{
			/** @var UserPets $pet */
			$pet = $this->em()->create('Sylphian\UserPets:UserPets');

			$pet->user_id = $visitor->user_id;
			$pet->hunger = 100;
			$pet->sleepiness = 100;
			$pet->happiness = 100;
			$pet->state = 'idle';
			$pet->last_update = \XF::$time;
			$pet->last_action_time = 0;
			$pet->created_at = \XF::$time;

			$pet->save();
		}

		$petManager = new PetManager($pet);
		$petManager->updateStats();

		$actionUrl = $this->app()->router()->buildLink('userPets/actions');

		$cooldownTime = 5 * 60;
		$currentTime = \XF::$time;
		$timeRemaining = max(0, ($pet->last_action_time + $cooldownTime) - $currentTime);
		$canPerformAction = ($timeRemaining == 0);

		$spriteSheetPath = \XF::app()->options()->publicPath . '/data/assets/sylphian/userpets/spritesheets/slime_spritesheet.png';

		return $this->renderer('sylphian_userpets_widget', [
			'widget' => $widget,
			'pet' => $pet,
			'actionUrl' => $actionUrl,
			'canPerformAction' => $canPerformAction,
			'cooldownRemaining' => $timeRemaining,
			'spriteSheetPath' => $spriteSheetPath,
		]);
	}
}

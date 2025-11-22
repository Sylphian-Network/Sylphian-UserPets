<?php

namespace Sylphian\UserPets\Job;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Tutorial\TutorialRegistry;
use XF\Entity\User;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use XF\Repository\UserAlertRepository;

class TutorialReward extends AbstractJob
{
	/**
	 * Default data structure for this job
	 */
	protected $defaultData = [
		'user_id' => 0,
		'exp_amount' => 0,
		'tutorial_key' => '',
		'attempts' => 0,
	];

	/**
	 * Run the job
	 *
	 * @param float $maxRunTime Maximum time to run this job
	 * @return JobResult
	 */
	public function run($maxRunTime): JobResult
	{
		if (empty($this->data['user_id']) || empty($this->data['exp_amount']))
		{
			return $this->complete();
		}

		$userId = $this->data['user_id'];
		$expAmount = $this->data['exp_amount'];
		$tutorialKey = $this->data['tutorial_key'];

		try
		{
			/** @var UserPetsRepository $petsRepo */
			$petsRepo = $this->app->repository('Sylphian\UserPets:UserPets');

			$petsRepo->awardPetExperience($userId, $expAmount, false);

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->app->repository('XF:UserAlert');

			/** @var User $user */
			$user = $this->app->em()->find('XF:User', $userId);

			$tutorial = TutorialRegistry::get($tutorialKey);
			$tutorialTitle = $tutorial ? $tutorial->getTitle() : 'Unknown Tutorial';

			if (!UserPetOptOut::isDisabledByUserId($userId))
			{
				$alertRepo->alertFromUser(
					$user,
					$user,
					'syl_userpet',
					$petsRepo->getUserPet($userId)->pet_id,
					'tutorial',
					[
						'exp_amount' => $expAmount,
						'tutorial_key' => $tutorialKey,
						'tutorial_title' => $tutorialTitle,
					],
					['autoRead' => true]
				);
			}

			Logger::notice('Awarded tutorial reward', [
				'user_id' => $userId,
				'exp_amount' => $expAmount,
				'tutorial_key' => $tutorialKey,
				'tutorial_title' => $tutorialTitle,
			]);

			return $this->complete();
		}
		catch (\Exception $e)
		{
			if ($this->data['attempts'] >= 5)
			{
				Logger::error('Failed to award tutorial reward after multiple attempts', [
					'user_id' => $userId,
					'exp_amount' => $expAmount,
					'tutorial_key' => $this->data['tutorial_key'],
					'error' => $e->getMessage(),
				]);
				return $this->complete();
			}

			$this->data['attempts']++;
			return $this->resume();
		}
	}

	/**
	 * Get a descriptive status message for this job
	 *
	 * @return string
	 */
	public function getStatusMessage(): string
	{
		return sprintf(
			'Awarding tutorial reward (%d XP) to user ID %d',
			$this->data['exp_amount'],
			$this->data['user_id']
		);
	}

	/**
	 * Can this job be cancelled by user action?
	 *
	 * @return bool
	 */
	public function canCancel(): bool
	{
		return true;
	}

	/**
	 * Can this job be triggered manually by user choice?
	 *
	 * @return bool
	 */
	public function canTriggerByChoice(): bool
	{
		return false;
	}
}

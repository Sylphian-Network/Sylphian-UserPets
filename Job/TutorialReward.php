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
	public const MAX_ATTEMPTS = 5;

	/**
	 * Default data structure for this job. "awarded" survives a retry so a failure after
	 * the XP was saved (alert) does not pay the reward again.
	 */
	protected $defaultData = [
		'user_id' => 0,
		'exp_amount' => 0,
		'tutorial_key' => '',
		'attempts' => 0,
		'awarded' => false,
	];

	/**
	 * @param float $maxRunTime Maximum time to run this job
	 * @return JobResult
	 */
	public function run($maxRunTime): JobResult
	{
		$userId = (int) $this->data['user_id'];
		$expAmount = (int) $this->data['exp_amount'];
		$tutorialKey = (string) $this->data['tutorial_key'];

		if ($userId <= 0 || $expAmount <= 0)
		{
			return $this->complete();
		}

		try
		{
			/** @var User|null $user */
			$user = $this->app->em()->find('XF:User', $userId);
			if (!$user || UserPetOptOut::isDisabledForUser($user))
			{
				return $this->complete();
			}

			/** @var UserPetsRepository $petsRepo */
			$petsRepo = $this->app->repository('Sylphian\UserPets:UserPets');
			$pet = $petsRepo->getOrCreatePet($userId);
			if (!$pet->pet_id)
			{
				throw new \RuntimeException("Could not resolve a pet for user_id {$userId}");
			}

			if (!$this->data['awarded'])
			{
				if (!$petsRepo->awardExperienceToPet($pet, $expAmount))
				{
					throw new \RuntimeException("Could not save the tutorial reward for user_id {$userId}");
				}

				$this->data['awarded'] = true;
			}

			$tutorial = TutorialRegistry::get($tutorialKey);

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->app->repository('XF:UserAlert');
			$alertRepo->alertFromUser(
				$user,
				$user,
				'syl_userpet',
				$pet->pet_id,
				'tutorial',
				[
					'exp_amount' => $expAmount,
					'tutorial_key' => $tutorialKey,
					'tutorial_title_phrase' => $tutorial ? $tutorial->getTitlePhraseName() : 'sylphian_userpets_unknown_tutorial',
					'tutorial_title' => $tutorial ? $tutorial->getTitle() : (string) \XF::phrase('sylphian_userpets_unknown_tutorial'),
				],
				['autoRead' => true]
			);

			return $this->complete();
		}
		catch (\Throwable $e)
		{
			if ($this->data['attempts'] >= self::MAX_ATTEMPTS)
			{
				Logger::error('Failed to award tutorial reward after multiple attempts', [
					'user_id' => $userId,
					'exp_amount' => $expAmount,
					'tutorial_key' => $tutorialKey,
					'awarded' => (bool) $this->data['awarded'],
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
		return (string) \XF::phrase('sylphian_userpets_job_tutorial_reward_status', [
			'exp' => $this->data['exp_amount'],
			'user_id' => $this->data['user_id'],
		]);
	}

	public function canCancel(): bool
	{
		return true;
	}

	public function canTriggerByChoice(): bool
	{
		return false;
	}
}

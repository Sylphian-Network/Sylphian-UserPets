<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPetTutorial;
use Sylphian\UserPets\Tutorial\TutorialId;
use Sylphian\UserPets\Tutorial\TutorialRegistry;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;

class UserPetsTutorialRepository extends Repository
{
	/**
	 * Get all tutorials for a user with completion status
	 *
	 * @param int $userId The user ID
	 * @return array The tutorials with completion status
	 */
	public function getUserTutorials(int $userId): array
	{
		$allTutorials = TutorialRegistry::getAllAsArray();

		$completedTutorials = $this->finder('Sylphian\UserPets:UserPetTutorial')
			->where('user_id', $userId)
			->where('completed', 1)
			->fetch()
			->pluckNamed('tutorial_key');

		$result = [];

		foreach ($allTutorials AS $tutorialKey => $tutorialData)
		{
			$result[] = [
				'tutorial_id' => $tutorialKey,
				'title' => $tutorialData['title'],
				'completed' => in_array($tutorialKey, $completedTutorials, true),
			];
		}

		return $result;
	}

	/**
	 * Check if a tutorial has been completed by a user
	 *
	 * @param int $userId The user ID
	 * @param string|TutorialId $tutorialKey The tutorial key or enum
	 * @return bool True if completed, false otherwise
	 */
	public function isTutorialCompleted(int $userId, string|TutorialId $tutorialKey): bool
	{
		$tutorialKeyString = ($tutorialKey instanceof TutorialId)
			? $tutorialKey->value
			: $tutorialKey;

		/** @var UserPetTutorial|null $existing */
		$existing = $this->finder('Sylphian\UserPets:UserPetTutorial')
			->where('user_id', $userId)
			->where('tutorial_key', $tutorialKeyString)
			->where('completed', 1)
			->fetchOne();

		return $existing !== null;
	}

	/**
	 * Mark a tutorial as completed for a user and automatically grant the reward
	 *
	 * @param int $userId The user ID
	 * @param string|TutorialId $tutorialKey The tutorial key or enum
	 * @return bool Success
	 */
	public function completeTutorial(int $userId, string|TutorialId $tutorialKey): bool
	{
		$tutorialKeyString = ($tutorialKey instanceof TutorialId)
			? $tutorialKey->value
			: $tutorialKey;

		try
		{
			$tutorialEnum = TutorialId::tryFrom($tutorialKeyString);
			if (!$tutorialEnum)
			{
				return false;
			}

			$tutorialData = TutorialRegistry::get($tutorialEnum);
			if (!$tutorialData)
			{
				return false;
			}
		}
		catch (\Throwable)
		{
			return false;
		}

		/** @var UserPetTutorial $existing */
		$existing = $this->finder('Sylphian\UserPets:UserPetTutorial')
			->where('user_id', $userId)
			->where('tutorial_key', $tutorialKeyString)
			->fetchOne();

		if ($existing)
		{
			if ($existing->completed)
			{
				return true;
			}

			$existing->completed = true;
			$existing->completed_date = \XF::$time;
			try
			{
				$existing->save();
			}
			catch (PrintableException|\Exception $e)
			{
				Logger::error('Error marking as completed', [
					'goal' => $existing,
					'exception' => $e->getMessage(),
					'trace' => $e->getTrace(),
				]);
			}
		}
		else
		{
			/** @var UserPetTutorial $tutorial */
			$tutorial = $this->em->create('Sylphian\UserPets:UserPetTutorial');
			$tutorial->user_id = $userId;
			$tutorial->tutorial_key = $tutorialKeyString;
			$tutorial->completed = true;
			$tutorial->completed_date = \XF::$time;
			try
			{
				$tutorial->save();
			}
			catch (PrintableException|\Exception $e)
			{
				Logger::error('Error creating a new record', [
					'goal' => $existing,
					'exception' => $e->getMessage(),
					'trace' => $e->getTrace(),
				]);
			}
		}

		$this->grantTutorialReward($userId, $tutorialKeyString);

		return true;
	}

	/**
	 * Grant the tutorial reward to a user
	 *
	 * @param int $userId The user ID
	 * @param string $tutorialKey The tutorial key
	 * @return void
	 */
	protected function grantTutorialReward(int $userId, string $tutorialKey): void
	{
		try
		{
			$tutorialEnum = TutorialId::tryFrom($tutorialKey);
			if (!$tutorialEnum)
			{
				return;
			}

			$tutorial = TutorialRegistry::get($tutorialEnum);
			if (!$tutorial)
			{
				return;
			}

			$optionName = $tutorial->getRewardExpOption();
			$expAmount = \XF::options()->$optionName ?? 0;

			if ($expAmount > 0)
			{
				\XF::app()->jobManager()->enqueue('Sylphian\UserPets:TutorialReward', [
					'user_id' => $userId,
					'exp_amount' => $expAmount,
					'tutorial_key' => $tutorialKey,
				]);

				Logger::info('Queued tutorial reward', [
					'user_id' => $userId,
					'exp_amount' => $expAmount,
					'tutorial_key' => $tutorialKey,
				]);
			}
		}
		catch (\Throwable $e)
		{
			Logger::error('Error granting tutorial reward: ' . $e->getMessage());
		}
	}
}

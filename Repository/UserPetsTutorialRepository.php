<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPetTutorial;
use Sylphian\UserPets\Tutorial\TutorialId;
use Sylphian\UserPets\Tutorial\TutorialRegistry;
use XF\Db\DuplicateKeyException;
use XF\Mvc\Entity\Repository;

class UserPetsTutorialRepository extends Repository
{
	/**
	 * Get all tutorials for a user with completion status
	 *
	 * @param int $userId The user ID
	 * @return array<int,array{tutorial_id:string,title:string,completed:bool}>
	 */
	public function getUserTutorials(int $userId): array
	{
		$tutorials = TutorialRegistry::getAll();

		$phraseNames = [];
		foreach ($tutorials AS $tutorial)
		{
			$phraseNames[] = $tutorial->getTitlePhraseName();
		}
		\XF::language()->requirePhrases($phraseNames);

		$completedTutorials = $this->finder('Sylphian\UserPets:UserPetTutorial')
			->where('user_id', $userId)
			->where('completed', 1)
			->fetch()
			->pluckNamed('tutorial_key');

		$result = [];

		foreach ($tutorials AS $tutorialKey => $tutorial)
		{
			$result[] = [
				'tutorial_id' => $tutorialKey,
				'title' => $tutorial->getTitle(),
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
	 * Mark a tutorial as completed for a user and queue its reward. The reward is queued
	 * only by the request that actually completed it, so concurrent events cannot pay twice.
	 *
	 * @param int $userId The user ID
	 * @param string|TutorialId $tutorialKey The tutorial key or enum
	 * @return bool True if the tutorial is completed (now or before)
	 */
	public function completeTutorial(int $userId, string|TutorialId $tutorialKey): bool
	{
		$tutorialKeyString = ($tutorialKey instanceof TutorialId)
			? $tutorialKey->value
			: $tutorialKey;

		if ($userId <= 0 || !TutorialId::tryFrom($tutorialKeyString) || !TutorialRegistry::get($tutorialKeyString))
		{
			return false;
		}

		/** @var UserPetTutorial|null $existing */
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

			$claimed = $this->db()->update(
				'xf_user_pets_tutorials',
				['completed' => 1, 'completed_date' => \XF::$time],
				'tutorial_id = ? AND completed = 0',
				$existing->tutorial_id
			);

			$existing->setAsSaved('completed', true);
			$existing->setAsSaved('completed_date', \XF::$time);

			if (!$claimed)
			{
				return true;
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
			catch (DuplicateKeyException $e)
			{
				return true;
			}
			catch (\Exception $e)
			{
				Logger::error('Error creating a new tutorial record', [
					'user_id' => $userId,
					'tutorial_key' => $tutorialKeyString,
					'exception' => $e->getMessage(),
				]);

				return false;
			}
		}

		$this->grantTutorialReward($userId, $tutorialKeyString);

		return true;
	}

	public function deleteTutorialsForUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return $this->db()->delete('xf_user_pets_tutorials', 'user_id = ?', $userId);
	}

	public function reassignTutorials(int $fromUserId, int $toUserId): int
	{
		if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId)
		{
			return 0;
		}

		return $this->db()->update('xf_user_pets_tutorials', ['user_id' => $toUserId], 'user_id = ?', $fromUserId, 'IGNORE');
	}

	/**
	 * Grant the tutorial reward to a user
	 *
	 * @param int $userId The user ID
	 * @param string $tutorialKey The tutorial key
	 */
	protected function grantTutorialReward(int $userId, string $tutorialKey): void
	{
		try
		{
			$tutorial = TutorialRegistry::get($tutorialKey);
			if (!$tutorial)
			{
				return;
			}

			$expAmount = $tutorial->getRewardAmount();
			if ($expAmount <= 0)
			{
				return;
			}

			\XF::app()->jobManager()->enqueue('Sylphian\UserPets:TutorialReward', [
				'user_id' => $userId,
				'exp_amount' => $expAmount,
				'tutorial_key' => $tutorialKey,
			]);
		}
		catch (\Throwable $e)
		{
			Logger::error('Error granting tutorial reward: ' . $e->getMessage(), [
				'user_id' => $userId,
				'tutorial_key' => $tutorialKey,
			]);
		}
	}
}

<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\PetLeveling;
use XF\Entity\Post;
use XF\Entity\Thread;
use XF\Mvc\Entity\Entity;
use XF\PrintableException;

class EntityEvents
{
	/**
	 * Handle post save events to award XP for new posts
	 *
	 * @param Entity $entity Post entity
	 */
	public static function postSave(Entity $entity): void
	{
		if (!($entity instanceof Post))
		{
			return;
		}

		if (!$entity->isInsert())
		{
			return;
		}

		$userId = $entity->user_id;
		if (!$userId)
		{
			return;
		}

		if ($entity->position == 0)
		{
			return;
		}

		try
		{
			self::awardPetExperience(
				$userId,
				\XF::options()->sylphian_userpets_experience_per_post,
				false
			);
		}
		catch (\Exception $e)
		{
			Logger::error('Error awarding pet experience for post creation', [
				'userId' => $userId,
				'postId' => $entity->post_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Handle thread save events to award XP for new threads
	 *
	 * @param Entity $entity Thread entity
	 */
	public static function threadSave(Entity $entity): void
	{
		if (!($entity instanceof Thread))
		{
			return;
		}

		if (!$entity->isInsert())
		{
			return;
		}

		$userId = $entity->user_id;
		if (!$userId)
		{
			return;
		}

		try
		{
			self::awardPetExperience(
				$userId,
				\XF::options()->sylphian_userpets_experience_per_thread,
				false
			);
		}
		catch (\Exception $e)
		{
			Logger::error('Error awarding pet experience for thread creation', [
				'userId' => $userId,
				'threadId' => $entity->thread_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Award experience to a user's pet
	 *
	 * @param int $userId The user ID to award experience to
	 * @param bool $updateActionTime Whether to update the last_action_time field
	 */
	protected static function awardPetExperience(int $userId, int $amountOfExp, bool $updateActionTime = true): void
	{
		$app = \XF::app();

		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $app->repository('Sylphian\UserPets:UserPets');
		$pet = $petsRepo->getUserPet($userId);

		if (!$pet)
		{
			return; // User doesn't have a pet
		}

		$petLevelingService = new PetLeveling();
		$petLevelingService->addExperience($pet, $amountOfExp);

		if ($updateActionTime)
		{
			$pet->last_action_time = \XF::$time;
		}

		try
		{
			$pet->save();
		}
		catch (PrintableException|\Exception $e)
		{
			Logger::error('Could not award pet exp: ' . $e->getMessage(), [
				'exception' => $e->getMessage(),
				'trace' => $e->getTrace(),
			]);
		}
	}
}

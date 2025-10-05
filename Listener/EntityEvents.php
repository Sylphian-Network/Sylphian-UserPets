<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Entity\Post;
use XF\Entity\Thread;
use XF\Mvc\Entity\Entity;

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

		$xpPerPost = (int) \XF::options()->sylphian_userpets_experience_per_post;
		if ($xpPerPost <= 0)
		{
			return;
		}

		try
		{
			/** @var UserPetsRepository $repo */
			$repo->awardPetExperience($userId, $xpPerPost, false);
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

		$xpPerThread = (int) \XF::options()->sylphian_userpets_experience_per_thread;
		if ($xpPerThread <= 0)
		{
			return;
		}

		try
		{
			/** @var UserPetsRepository $repo */
			$repo->awardPetExperience($userId, $xpPerThread, false);
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
}

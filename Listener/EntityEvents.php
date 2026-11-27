<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\ContentXpFilter;
use Sylphian\UserPets\Service\PetDataCleanup;
use XF\Entity\Post;
use XF\Entity\Thread;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\User\MergeService;

/**
 * Keeps content XP in step with the forum:
 * - a new visible thread or reply is credited at once (UserPetsRepository::awardContentExperience);
 * - any later change that can alter what counts (visibility, move, author, solution) recounts the
 *   affected users (UserPetsRepository::syncContentExperience), never the whole board.
 */
class EntityEvents
{
	public const POST_RECOUNT_FIELDS = ['message_state', 'thread_id', 'position', 'user_id'];

	public const RUN_ONCE_PREFIX = 'sylphianUserPetsSync-';

	public const THREAD_JOB_PREFIX = 'sylphianUserPetsThread';

	public static function postSave(Entity $entity): void
	{
		if (!($entity instanceof Post))
		{
			return;
		}

		try
		{
			if ($entity->isInsert())
			{
				self::awardNewPost($entity);
				return;
			}

			if (!$entity->isChanged(self::POST_RECOUNT_FIELDS))
			{
				return;
			}

			self::queueUserRecount((int) $entity->user_id);
			if ($entity->isChanged('user_id'))
			{
				self::queueUserRecount((int) $entity->getExistingValue('user_id'));
			}
		}
		catch (\Throwable $e)
		{
			Logger::error('Error updating pet experience for a post', [
				'postId' => $entity->post_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	public static function postDelete(Entity $entity): void
	{
		if (!($entity instanceof Post) || $entity->message_state !== 'visible')
		{
			return;
		}

		self::queueUserRecount((int) $entity->user_id);
	}

	public static function threadSave(Entity $entity): void
	{
		if (!($entity instanceof Thread))
		{
			return;
		}

		try
		{
			if ($entity->isInsert())
			{
				self::awardNewThread($entity);
				return;
			}

			if ($entity->isChanged('user_id'))
			{
				self::queueUserRecount((int) $entity->user_id);
				self::queueUserRecount((int) $entity->getExistingValue('user_id'));
			}

			if (self::threadChangeAffectsCounts($entity))
			{
				self::queueThreadRecount($entity);
			}
		}
		catch (\Throwable $e)
		{
			Logger::error('Error updating pet experience for a thread', [
				'threadId' => $entity->thread_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Posts of a hard-deleted thread are removed without entity events, so the users are
	 * collected before the delete; the queued recount runs after it.
	 */
	public static function threadPreDelete(Entity $entity): void
	{
		if (!($entity instanceof Thread) || $entity->discussion_state !== 'visible')
		{
			return;
		}

		try
		{
			self::queueThreadRecount($entity);
		}
		catch (\Throwable $e)
		{
			Logger::error('Error queueing pet experience recount for a deleted thread', [
				'threadId' => $entity->thread_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Every solution change (mark, change, unmark, solution post removed) saves this entity,
	 * so both the previous and the new solution author are recounted here.
	 */
	public static function questionSave(Entity $entity): void
	{
		if (!self::isThreadQuestionEntity($entity))
		{
			return;
		}

		$userIds = [(int) $entity->solution_user_id];
		if ($entity->isUpdate())
		{
			if (!$entity->isChanged(['solution_post_id', 'solution_user_id']))
			{
				return;
			}

			$userIds[] = (int) $entity->getExistingValue('solution_user_id');
		}

		/** @var UserPetsRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPets');

		foreach (array_unique(array_filter($userIds)) AS $userId)
		{
			try
			{
				$repo->syncContentExperience($userId, [UserPetsRepository::CONTENT_SOLUTION]);
			}
			catch (\Throwable $e)
			{
				Logger::error('Error syncing accepted solution experience', [
					'threadId' => $entity->thread_id,
					'userId' => $userId,
					'exception' => $e->getMessage(),
				]);
			}
		}
	}

	public static function userDelete(Entity $entity): void
	{
		if (!($entity instanceof User))
		{
			return;
		}

		try
		{
			/** @var PetDataCleanup $cleanup */
			$cleanup = \XF::service('Sylphian\UserPets:PetDataCleanup');
			$cleanup->deleteForUser((int) $entity->user_id);
		}
		catch (\Throwable $e)
		{
			Logger::error('Error deleting pet data of a deleted user', [
				'userId' => $entity->user_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	public static function userMergeCombine(User $target, User $source, MergeService $mergeService): void
	{
		try
		{
			/** @var PetDataCleanup $cleanup */
			$cleanup = \XF::service('Sylphian\UserPets:PetDataCleanup');
			$cleanup->mergeUsers((int) $target->user_id, (int) $source->user_id);
		}
		catch (\Throwable $e)
		{
			Logger::error('Error handing pet data over in a user merge', [
				'targetUserId' => $target->user_id,
				'sourceUserId' => $source->user_id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Recounts a user's content XP once, at the end of the request; many changes to the same
	 * user's content in one request (spam clean-up, inline moderation) cost one recount.
	 */
	public static function queueUserRecount(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		\XF::runOnce(self::RUN_ONCE_PREFIX . $userId, function () use ($userId)
		{
			try
			{
				/** @var UserPetsRepository $repo */
				$repo = \XF::repository('Sylphian\UserPets:UserPets');
				$repo->syncContentExperience($userId);
			}
			catch (\Throwable $e)
			{
				Logger::error('Error recounting pet content experience', [
					'userId' => $userId,
					'exception' => $e->getMessage(),
				]);
			}
		});
	}

	protected static function awardNewPost(Post $post): void
	{
		$userId = (int) $post->user_id;
		if (!$userId || $post->position == 0 || $post->message_state !== 'visible')
		{
			return;
		}

		/** @var Thread|null $thread */
		$thread = $post->Thread;
		if (!$thread || $thread->discussion_state !== 'visible' || !ContentXpFilter::matchesPost($post))
		{
			return;
		}

		/** @var UserPetsRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPets');
		$repo->awardContentExperience($userId, UserPetsRepository::CONTENT_POST);
	}

	protected static function awardNewThread(Thread $thread): void
	{
		$userId = (int) $thread->user_id;
		if (!$userId || $thread->discussion_state !== 'visible' || !ContentXpFilter::matchesThread($thread))
		{
			return;
		}

		/** @var UserPetsRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPets');
		$repo->awardContentExperience($userId, UserPetsRepository::CONTENT_THREAD);
	}

	protected static function threadChangeAffectsCounts(Thread $thread): bool
	{
		if ($thread->isChanged('discussion_state'))
		{
			return true;
		}

		$threadCriteria = ContentXpFilter::getCriteriaFromOptions(false);
		$postCriteria = ContentXpFilter::getCriteriaFromOptions(true);

		if ($thread->isChanged('node_id') && ($threadCriteria['nodeIds'] || $postCriteria['nodeIds']))
		{
			return true;
		}

		if ($thread->isChanged('prefix_id') && ($threadCriteria['prefixIds'] || $postCriteria['prefixIds']))
		{
			return true;
		}

		if ($thread->isChanged('discussion_type'))
		{
			return $threadCriteria['threadTypes']
				|| $postCriteria['threadTypes']
				|| $thread->discussion_type === 'question'
				|| $thread->getExistingValue('discussion_type') === 'question';
		}

		return false;
	}

	protected static function queueThreadRecount(Thread $thread): void
	{
		/** @var UserPetsRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPets');

		$userIds = $repo->getPetUserIdsForThread((int) $thread->thread_id);
		if (!$userIds)
		{
			return;
		}

		\XF::app()->jobManager()->enqueueUnique(
			self::THREAD_JOB_PREFIX . $thread->thread_id,
			'Sylphian\UserPets:RebuildSolutionExperience',
			['user_ids' => $userIds],
			false
		);
	}

	protected static function isThreadQuestionEntity(Entity $entity): bool
	{
		return $entity->structure()->shortName === 'XF:ThreadQuestion';
	}
}

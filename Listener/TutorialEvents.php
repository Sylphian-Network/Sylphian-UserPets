<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use Sylphian\UserPets\Tutorial\TutorialId;
use XF\Entity\Post;
use XF\Entity\ReactionContent;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;

class TutorialEvents
{
	/**
	 * Generic tutorial handler that processes event according to provided parameters
	 *
	 * @param Entity $entity The entity being processed
	 * @param string $expectedClass The expected entity class
	 * @param TutorialId $tutorialId The tutorial to complete
	 * @param callable $validationCallback Additional validation callback
	 * @param callable|null $getUserIdCallback Custom function to extract user ID (if not standard)
	 * @param array $extraLogData Additional data for error logs
	 */
	protected static function handleTutorialEvent(
		Entity $entity,
		string $expectedClass,
		TutorialId $tutorialId,
		callable $validationCallback,
		?callable $getUserIdCallback = null,
		array $extraLogData = []
	): void
	{
		if (!($entity instanceof $expectedClass))
		{
			return;
		}

		if (!\XF::options()->sylphian_userpets_enable_tutorial)
		{
			return;
		}

		if (!$validationCallback($entity))
		{
			return;
		}

		$userId = $getUserIdCallback ? $getUserIdCallback($entity) : $entity->user_id;
		if (!$userId)
		{
			return;
		}

		try
		{
			/** @var UserPetsTutorialRepository $repo */
			$repo = \XF::repository('Sylphian\UserPets:UserPetsTutorial');

			if (!$repo->isTutorialCompleted($userId, $tutorialId))
			{
				$repo->completeTutorial($userId, $tutorialId);
			}
		}
		catch (\Exception $e)
		{
			$logData = array_merge([
				'userId' => $userId,
				'exception' => $e->getMessage(),
				'tutorialId' => $tutorialId->value,
			], $extraLogData);

			Logger::error('Error completing tutorial: ' . $tutorialId->value, $logData);
		}
	}

	/**
	 * Handle post save events for tutorial goals
	 *
	 * @param Entity $entity Post entity
	 */
	public static function postSave(Entity $entity): void
	{
		self::handleTutorialEvent(
			$entity,
			Post::class,
			TutorialId::POST_FIRST_MESSAGE,
			function (Post $post)
			{
				return $post->isInsert() && $post->position > 0;
			},
			null,
			['postId' => $entity instanceof Post ? $entity->post_id : null]
		);
	}

	/**
	 * Handle user save events for avatar uploads
	 *
	 * @param Entity $entity User entity
	 */
	public static function userSave(Entity $entity): void
	{
		self::handleTutorialEvent(
			$entity,
			User::class,
			TutorialId::UPLOAD_PFP,
			function (User $user)
			{
				return $user->isChanged('avatar_date') && $user->avatar_date;
			}
		);
	}

	/**
	 * Handle reaction content save events
	 *
	 * @param Entity $entity ReactionContent entity
	 */
	public static function reactionSave(Entity $entity): void
	{
		self::handleTutorialEvent(
			$entity,
			ReactionContent::class,
			TutorialId::REACT_TO_POST,
			function (ReactionContent $reaction)
			{
				return $reaction->isInsert();
			},
			function (ReactionContent $reaction)
			{
				return $reaction->reaction_user_id;
			},
			[
				'contentType' => $entity instanceof ReactionContent ? $entity->content_type : null,
				'contentId' => $entity instanceof ReactionContent ? $entity->content_id : null,
			]
		);
	}

	/**
	 * Handle UserPets save events to track pet actions
	 *
	 * @param Entity $entity UserPets entity
	 */
	public static function userPetsAction(Entity $entity): void
	{
		self::handleTutorialEvent(
			$entity,
			UserPets::class,
			TutorialId::COMPLETE_ACTION,
			function (UserPets $pet)
			{
				return $pet->isChanged('last_action_time');
			},
			null,
			['petId' => $entity instanceof UserPets ? $entity->pet_id : null]
		);
	}
}

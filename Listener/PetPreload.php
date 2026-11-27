<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Template\Templater;

/**
 * Loads pets for pages that show many users at once, and decides who may see a member's pet.
 */
class PetPreload
{
	/**
	 * Default template parameter read by the message user info: user id => pet and render config,
	 * only for the pets that are shown.
	 */
	public const POSTBIT_PARAM = 'sylUserPetsPostbit';

	public const PROFILE_TAB_PARAM = 'sylUserPetsProfileTab';

	public const POSTBIT_MODES = ['badge', 'sprite'];

	/**
	 * Template parameters that hold the messages of thread and conversation pages.
	 */
	public const MESSAGE_PARAMS = ['posts', 'firstPost', 'pinnedPost', 'highlightedPosts', 'post', 'messages', 'message'];

	/**
	 * templater_template_pre_render for the templates that show messages with their author:
	 * public:thread_view (every thread type extends it), public:thread_new_posts, public:post,
	 * public:post_edit_new_post, public:conversation_view, public:conversation_reply_new_messages
	 * and public:conversation_message_edit_new_message.
	 */
	public static function messagesPreRender(Templater $templater, &$type, &$template, array &$params): void
	{
		$mode = (string) \XF::options()->sylphian_userpets_postbit;
		if (!in_array($mode, self::POSTBIT_MODES, true))
		{
			return;
		}

		$users = self::collectMessageUsers($params);
		if (!$users)
		{
			return;
		}

		$shown = self::preloadPetsForUsers($users, $mode === 'sprite') + (array) ($params[self::POSTBIT_PARAM] ?? []);

		$templater->addDefaultParam(self::POSTBIT_PARAM, $shown);
		$params[self::POSTBIT_PARAM] = $shown;
	}

	/**
	 * templater_template_pre_render for public:member_view: whether the profile shows the Pet tab.
	 */
	public static function memberViewPreRender(Templater $templater, &$type, &$template, array &$params): void
	{
		$user = $params['user'] ?? null;

		$params[self::PROFILE_TAB_PARAM] = $user instanceof User && self::getProfilePet($user, \XF::visitor()) !== null;
	}

	/**
	 * The authors of the messages in the template parameters, by user id. Only authors that are
	 * already loaded count, so collecting them never queries.
	 *
	 * @return array<int, User>
	 */
	public static function collectMessageUsers(array $params): array
	{
		$users = [];

		foreach (self::MESSAGE_PARAMS AS $key)
		{
			$value = $params[$key] ?? null;
			$items = $value instanceof Entity ? [$value] : (is_iterable($value) ? $value : []);

			foreach ($items AS $item)
			{
				if (!($item instanceof Entity)
					|| !$item->isValidRelation('User')
					|| !$item->isRelationHydrated('User')
				)
				{
					continue;
				}

				$user = $item->User;
				if ($user instanceof User && $user->user_id)
				{
					$users[(int) $user->user_id] = $user;
				}
			}
		}

		return $users;
	}

	/**
	 * Loads the pets of all given users with one query and hydrates User::UserPet on each of
	 * them, null for users without a pet, so templates and the opt-out check do not query.
	 *
	 * @param array<int, User> $users by user id
	 * @param bool $withRender also resolve the sprite of each shown pet
	 * @return array<int, array{pet: UserPets, render: array}> the pets to show, by user id
	 */
	public static function preloadPetsForUsers(array $users, bool $withRender = false): array
	{
		$users = array_filter($users, fn ($user) => $user instanceof User && $user->user_id);
		if (!$users)
		{
			return [];
		}

		// The permission combination of each owner is loaded in the same query: the opt-out
		// check reads the havePet permission, and conversation pages do not load it.
		$pets = \XF::finder('Sylphian\UserPets:UserPets')
			->where('user_id', array_keys($users))
			->with('User.PermissionCombination')
			->order('pet_id')
			->fetch();

		$petsByUser = [];
		/** @var UserPets $pet */
		foreach ($pets AS $pet)
		{
			$petsByUser[(int) $pet->user_id] ??= $pet;
		}

		/** @var UserPetsRepository $repo */
		$repo = \XF::repository(UserPetsRepository::class);

		$shown = [];
		foreach ($users AS $userId => $user)
		{
			$pet = $petsByUser[$userId] ?? null;
			$user->hydrateRelation('UserPet', $pet);

			if (!$pet || UserPetOptOut::isDisabledForUser($user))
			{
				continue;
			}

			$shown[$userId] = [
				'pet' => $pet,
				'render' => $withRender ? $repo->getRenderConfigForPet($pet) : [],
			];
		}

		return $shown;
	}

	/**
	 * Guests see pets only when the guest view option is on.
	 */
	public static function canViewPets(User $viewer): bool
	{
		return $viewer->user_id > 0 || (bool) \XF::options()->sylphian_userpets_guest_view;
	}

	/**
	 * The owner's pet as the profile shows it, or null when the viewer may not see pets, the
	 * owner has no pet or the owner has opted out.
	 */
	public static function getProfilePet(User $owner, User $viewer): ?UserPets
	{
		if (!$owner->user_id || !self::canViewPets($viewer))
		{
			return null;
		}

		$pet = $owner->UserPet;
		if (!$pet || UserPetOptOut::isDisabledForUser($owner))
		{
			return null;
		}

		return $pet;
	}
}

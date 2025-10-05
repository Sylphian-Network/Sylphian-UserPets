<?php

namespace Sylphian\UserPets\Tutorial;

/**
 * Registry of all tutorials in the system
 */
class TutorialRegistry
{
	/**
	 * @var array<string, Tutorial>
	 */
	private static array $tutorials = [];

	/**
	 * Initialize the registry with all tutorials
	 */
	public static function init(): void
	{
		if (!empty(self::$tutorials))
		{
			return;
		}

		self::register(
			TutorialId::COMPLETE_ACTION,
			'Complete your first pet action',
			'sylphian_userpets_tutorial_exp_complete_action'
		);

		self::register(
			TutorialId::UPLOAD_PFP,
			'Upload a profile picture',
			'sylphian_userpets_tutorial_exp_upload_pfp'
		);

		self::register(
			TutorialId::POST_FIRST_MESSAGE,
			'Post your first message',
			'sylphian_userpets_tutorial_exp_post_first_message'
		);

		self::register(
			TutorialId::REACT_TO_POST,
			'React to a post',
			'sylphian_userpets_tutorial_exp_react_to_post'
		);
	}

	/**
	 * Register a new tutorial
	 */
	private static function register(TutorialId $id, string $title, string $rewardExpOption): void
	{
		self::$tutorials[$id->value] = Tutorial::create($id->value, $title, $rewardExpOption);
	}

	/**
	 * Get a tutorial by ID
	 */
	public static function get(TutorialId|string $idOrKey): ?Tutorial
	{
		self::init();

		$key = $idOrKey instanceof TutorialId ? $idOrKey->value : $idOrKey;
		return self::$tutorials[$key] ?? null;
	}

	/**
	 * Get all tutorials in array format for template rendering
	 */
	public static function getAllAsArray(): array
	{
		self::init();

		return array_map(function ($tutorial)
		{
			return [
				'title' => $tutorial->getTitle(),
				'reward_exp_option' => $tutorial->getRewardExpOption(),
			];
		}, self::$tutorials);
	}

	/**
	 * Check if a tutorial exists
	 */
	public static function exists(TutorialId|string $idOrKey): bool
	{
		self::init();

		$key = $idOrKey instanceof TutorialId ? $idOrKey->value : $idOrKey;
		return isset(self::$tutorials[$key]);
	}
}

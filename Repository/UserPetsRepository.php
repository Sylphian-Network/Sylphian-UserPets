<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Service\ContentXpFilter;
use Sylphian\UserPets\Service\PetLeveling;
use XF\Db\DuplicateKeyException;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;
use XF\Repository\UserAlertRepository;
use XF\Repository\UserFieldRepository;

class UserPetsRepository extends Repository
{
	public const CONTENT_THREAD = 'thread';
	public const CONTENT_POST = 'post';
	public const CONTENT_SOLUTION = 'solution';

	/**
	 * Content types whose XP is tracked on the pet: [count column, experience column, XP option].
	 */
	public const CONTENT_TRACKING = [
		self::CONTENT_THREAD => ['thread_reward_count', 'thread_reward_experience', 'sylphian_userpets_experience_per_thread'],
		self::CONTENT_POST => ['post_reward_count', 'post_reward_experience', 'sylphian_userpets_experience_per_post'],
		self::CONTENT_SOLUTION => ['solution_reward_count', 'solution_reward_experience', 'sylphian_userpets_experience_per_solution'],
	];

	public const SPRITESHEET_INDEX_CACHE_KEY = 'sylphianUserPetsSpritesheetIndex';

	public const SPRITESHEET_INDEX_CACHE_TTL = 300;

	/**
	 * @var array<string,string>|null
	 */
	protected ?array $spritesheetChoiceFileMap = null;

	/**
	 * @var array{choices:array<string,string>,sheets:array<string,array>}|null
	 */
	protected ?array $spritesheetRenderIndex = null;

	/**
	 * Get a user's pet
	 *
	 * @param int $userId The user ID
	 * @return UserPets|null The pet entity or null if not found
	 */
	public function getUserPet(int $userId): ?UserPets
	{
		if ($userId <= 0)
		{
			return null;
		}

		/** @var UserPets|null $pet */
		$pet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->order('pet_id')
			->fetchOne();

		return $pet;
	}

	/**
	 * Get a user's pet, creating one if it does not exist yet.
	 *
	 * @param int $userId The user ID
	 * @return UserPets The existing or newly created pet; pet_id is empty if it could not be saved
	 */
	public function getOrCreatePet(int $userId): UserPets
	{
		return $this->getUserPet($userId) ?? $this->createPet($userId);
	}

	/**
	 * Insert-or-fetch: returns the user's pet, creating it when missing. A concurrent
	 * request that inserted first wins (unique key on user_id) and its pet is returned.
	 *
	 * A new pet is credited once for the content the user already has.
	 *
	 * @param int $userId The user ID
	 * @return UserPets The pet; pet_id is empty if it could not be saved
	 */
	public function createPet(int $userId): UserPets
	{
		$existing = $this->getUserPet($userId);
		if ($existing)
		{
			return $existing;
		}

		/** @var UserPets $pet */
		$pet = $this->em->create('Sylphian\UserPets:UserPets');
		$pet->user_id = max(0, $userId);
		$pet->level = 1;
		$pet->experience = 0;
		$pet->hunger = 100;
		$pet->sleepiness = 100;
		$pet->happiness = 100;
		$pet->state = 'idle';
		$pet->last_update = \XF::$time;
		$pet->last_action_time = 0;
		$pet->last_duel_time = 0;
		$pet->thread_reward_count = 0;
		$pet->thread_reward_experience = 0;
		$pet->post_reward_count = 0;
		$pet->post_reward_experience = 0;
		$pet->solution_reward_count = 0;
		$pet->solution_reward_experience = 0;
		$pet->created_at = \XF::$time;

		if ($userId <= 0)
		{
			return $pet;
		}

		try
		{
			$pet->save();
		}
		catch (DuplicateKeyException $e)
		{
			return $this->getUserPet($userId) ?? $pet;
		}
		catch (\Exception $e)
		{
			Logger::error(
				"Failed to create pet for user_id {$userId}.",
				['error' => $e->getMessage()]
			);

			return $this->getUserPet($userId) ?? $pet;
		}

		if (!UserPetOptOut::isDisabledByUserId($userId))
		{
			$this->syncPetContentExperience($pet, array_keys(self::CONTENT_TRACKING), false);
		}

		return $pet;
	}

	/**
	 * Get available sprite sheet options
	 *
	 * @return array List of available sprite sheets as field_choices array
	 */
	public function getAvailableSpriteSheets(): array
	{
		$options = [];

		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');
		$files = $spritesheetRepo->buildIndexList();

		foreach ($files AS $file)
		{
			$filename = (string) ($file['name'] ?? '');
			if ($filename === '')
			{
				continue;
			}

			$entity = $file['entity'] ?? null;
			$displayLabel = '';
			if ($entity && !empty($entity->title))
			{
				$displayLabel = (string) $entity->title;
			}

			if ($displayLabel === '')
			{
				$displayLabel = ucwords(str_replace(['_', '-'], ' ', pathinfo($filename, PATHINFO_FILENAME)));
			}

			$options[$this->getSpritesheetChoiceId($filename)] = $displayLabel;
		}

		if (empty($options))
		{
			$options['empty'] = (string) \XF::phrase('sylphian_userpets_spritesheet_choice_none');
		}

		return $options;
	}

	public function getSpritesheetChoiceId(string $filename): string
	{
		$base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
		$safe = (string) preg_replace('/[^a-z0-9_]/i', '_', $base);
		$safe = (string) preg_replace('/_+/', '_', $safe);
		$safe = trim($safe, '_');

		if ($safe === '')
		{
			$safe = 'sheet';
		}

		$safe = substr($safe, 0, 12);

		return 'ss_' . $safe . '_' . substr(md5(strtolower($filename)), 0, 8);
	}

	/**
	 * Built from the live spritesheet index once per request. Admin pages and option
	 * validation use this; the public widget uses getSpritesheetRenderIndex().
	 *
	 * @return array<string,string> Map of safe custom field option IDs to real spritesheet filenames.
	 */
	public function getSpritesheetChoiceFileMap(): array
	{
		if ($this->spritesheetChoiceFileMap !== null)
		{
			return $this->spritesheetChoiceFileMap;
		}

		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');
		$files = $spritesheetRepo->buildIndexList();

		$map = [];
		foreach ($files AS $file)
		{
			$filename = (string) ($file['name'] ?? '');
			if ($filename === '')
			{
				continue;
			}

			$map[$this->getSpritesheetChoiceId($filename)] = $filename;
		}

		$this->spritesheetChoiceFileMap = $map;

		return $map;
	}

	public function resolveSpritesheetFilename(string $value): ?string
	{
		return $this->resolveSpritesheetFilenameFromMap($value, $this->getSpritesheetChoiceFileMap());
	}

	/**
	 * @param array<string,string> $choiceMap choice ID => filename
	 */
	public function resolveSpritesheetFilenameFromMap(string $value, array $choiceMap): ?string
	{
		$value = trim($value);
		if ($value === '' || $value === 'empty')
		{
			return null;
		}

		if (isset($choiceMap[$value]))
		{
			return $choiceMap[$value];
		}

		$needle = strtolower($value);
		$aliases = [];

		foreach ($choiceMap AS $choiceId => $filename)
		{
			$aliases[strtolower($choiceId)] = $filename;

			$filenameLower = strtolower($filename);
			$base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
			$safe = strtolower(trim((string) preg_replace('/[^a-z0-9_]/i', '_', $base), '_'));

			$aliases[$filenameLower] = $filename;
			if (!isset($aliases[$base]))
			{
				$aliases[$base] = $filename;
			}
			if ($safe !== '' && !isset($aliases[$safe]))
			{
				$aliases[$safe] = $filename;
			}
		}

		return $aliases[$needle] ?? null;
	}

	/**
	 * Spritesheet data the public pet widget needs, without a directory scan on cache hits:
	 * kept in the global cache (Redis) for a short time and once per request.
	 * Falls back to building it from the live index when no cache is configured.
	 *
	 * @return array{choices:array<string,string>,sheets:array<string,array{frame_width:int,frame_height:int,frames_per_animation:int,fps:int,has_entity:bool,exists:bool}>}
	 */
	public function getSpritesheetRenderIndex(): array
	{
		if ($this->spritesheetRenderIndex !== null)
		{
			return $this->spritesheetRenderIndex;
		}

		$cache = $this->getSharedCache();
		$item = null;

		if ($cache)
		{
			try
			{
				$item = $cache->getItem(self::SPRITESHEET_INDEX_CACHE_KEY);
				if ($item->isHit())
				{
					$value = $item->get();
					if (is_array($value) && isset($value['choices'], $value['sheets']))
					{
						$this->spritesheetRenderIndex = $value;
						return $value;
					}
				}
			}
			catch (\Throwable $e)
			{
				$item = null;
			}
		}

		$index = $this->buildSpritesheetRenderIndex();
		$this->spritesheetRenderIndex = $index;

		if ($cache && $item)
		{
			try
			{
				$item->set($index);
				$item->expiresAfter(self::SPRITESHEET_INDEX_CACHE_TTL);
				$cache->save($item);
			}
			catch (\Throwable $e)
			{
			}
		}

		return $index;
	}

	/**
	 * Call after any spritesheet file or row changes (upload, edit, rename, delete).
	 */
	public function clearSpritesheetCaches(): void
	{
		$this->spritesheetChoiceFileMap = null;
		$this->spritesheetRenderIndex = null;

		$cache = $this->getSharedCache();
		if ($cache)
		{
			try
			{
				$cache->deleteItem(self::SPRITESHEET_INDEX_CACHE_KEY);
			}
			catch (\Throwable $e)
			{
			}
		}
	}

	public function normalizeSpritesheetUserFieldValues(): int
	{
		$choiceMap = $this->getSpritesheetChoiceFileMap();
		if (!$choiceMap)
		{
			return 0;
		}

		$aliases = [];
		foreach ($choiceMap AS $choiceId => $filename)
		{
			$filenameLower = strtolower($filename);
			$base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
			$safe = strtolower(trim((string) preg_replace('/[^a-z0-9_]/i', '_', $base), '_'));

			$aliases[strtolower($choiceId)] = $choiceId;
			$aliases[$filenameLower] = $choiceId;
			$aliases[$base] = $choiceId;
			if ($safe !== '')
			{
				$aliases[$safe] = $choiceId;
			}
		}

		$db = $this->db();
		$values = $db->fetchAllColumn(
			'
				SELECT DISTINCT field_value
				FROM xf_user_field_value
				WHERE field_id = ?
					AND field_value <> ?
			',
			['syl_userpets_spritesheet', '']
		);

		$updated = 0;
		$changedUserIds = [];

		foreach ($values AS $current)
		{
			$current = (string) $current;
			$choiceId = $aliases[strtolower($current)] ?? null;

			if (!$choiceId || $choiceId === $current)
			{
				continue;
			}

			$userIds = $db->fetchAllColumn(
				'SELECT user_id FROM xf_user_field_value WHERE field_id = ? AND field_value = ?',
				['syl_userpets_spritesheet', $current]
			);

			$updated += $db->update(
				'xf_user_field_value',
				['field_value' => $choiceId],
				'field_id = ? AND field_value = ?',
				['syl_userpets_spritesheet', $current]
			);

			foreach ($userIds AS $userId)
			{
				$changedUserIds[(int) $userId] = true;
			}
		}

		if ($changedUserIds)
		{
			/** @var UserFieldRepository $userFieldRepo */
			$userFieldRepo = $this->repository('XF:UserField');
			foreach (array_keys($changedUserIds) AS $userId)
			{
				$userFieldRepo->rebuildUserFieldValuesCache($userId);
			}
		}

		return $updated;
	}

	public function getPetCount(): int
	{
		return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM xf_user_pets');
	}

	/**
	 * @return array{rewarded_solutions:int,rewarded_experience:int}
	 */
	public function getSolutionRewardSummary(): array
	{
		$row = $this->db()->fetchRow(
			'
				SELECT
					COALESCE(SUM(solution_reward_count), 0) AS rewarded_solutions,
					COALESCE(SUM(solution_reward_experience), 0) AS rewarded_experience
				FROM xf_user_pets
			'
		);

		return [
			'rewarded_solutions' => (int) ($row['rewarded_solutions'] ?? 0),
			'rewarded_experience' => (int) ($row['rewarded_experience'] ?? 0),
		];
	}

	/**
	 * @return array{rewarded_threads:int,rewarded_thread_experience:int,rewarded_posts:int,rewarded_post_experience:int,rewarded_solutions:int,rewarded_solution_experience:int}
	 */
	public function getContentRewardSummary(): array
	{
		$row = $this->db()->fetchRow(
			'
				SELECT
					COALESCE(SUM(thread_reward_count), 0) AS rewarded_threads,
					COALESCE(SUM(thread_reward_experience), 0) AS rewarded_thread_experience,
					COALESCE(SUM(post_reward_count), 0) AS rewarded_posts,
					COALESCE(SUM(post_reward_experience), 0) AS rewarded_post_experience,
					COALESCE(SUM(solution_reward_count), 0) AS rewarded_solutions,
					COALESCE(SUM(solution_reward_experience), 0) AS rewarded_solution_experience
				FROM xf_user_pets
			'
		);

		return [
			'rewarded_threads' => (int) ($row['rewarded_threads'] ?? 0),
			'rewarded_thread_experience' => (int) ($row['rewarded_thread_experience'] ?? 0),
			'rewarded_posts' => (int) ($row['rewarded_posts'] ?? 0),
			'rewarded_post_experience' => (int) ($row['rewarded_post_experience'] ?? 0),
			'rewarded_solutions' => (int) ($row['rewarded_solutions'] ?? 0),
			'rewarded_solution_experience' => (int) ($row['rewarded_solution_experience'] ?? 0),
		];
	}

	public function syncSpritesheetRegistrationRequirement(bool $enabled): void
	{
		/** @var \XF\Entity\UserField|null $field */
		$field = $this->finder('XF:UserField')
			->where('field_id', 'syl_userpets_spritesheet')
			->fetchOne();

		if (!$field)
		{
			return;
		}

		$field->required = $enabled;
		$field->show_registration = $enabled;
		$field->save();
	}

	/**
	 * Adds experience to a loaded pet, recalculates its level, saves it and sends the
	 * level-up alert. Any other pending changes on the entity are saved with it.
	 *
	 * Does not check the opt-out; callers acting for a user must.
	 *
	 * @return bool False if the pet could not be saved
	 */
	public function savePetWithExperience(UserPets $pet, int $amount): bool
	{
		$oldLevel = (int) $pet->level;
		$leveledUp = (new PetLeveling())->addExperience($pet, $amount);

		try
		{
			$pet->save();
		}
		catch (\Exception $e)
		{
			Logger::error('Could not save pet experience: ' . $e->getMessage(), [
				'pet_id' => $pet->pet_id,
				'user_id' => $pet->user_id,
				'amount' => $amount,
			]);

			return false;
		}

		if ($leveledUp)
		{
			$this->sendLevelUpAlert($pet, $oldLevel, $amount);
		}

		return true;
	}

	/**
	 * Awards a fixed amount of experience to a loaded pet (duel wins, tournament prizes,
	 * tutorials). Opted-out users get nothing.
	 *
	 * @param bool $updateActionTime Also restarts the pet action cooldown
	 * @return bool True if experience was added and saved
	 */
	public function awardExperienceToPet(UserPets $pet, int $amount, bool $updateActionTime = false): bool
	{
		if ($amount <= 0 || !$pet->pet_id || UserPetOptOut::isDisabledByUserId((int) $pet->user_id))
		{
			return false;
		}

		if ($updateActionTime)
		{
			$pet->last_action_time = \XF::$time;
		}

		return $this->savePetWithExperience($pet, $amount);
	}

	/**
	 * Award experience to a user's pet. Users without a pet or with the pet disabled get nothing.
	 *
	 * @param int $userId The user ID to award experience to
	 * @param int $amountOfExp The amount of experience to award
	 * @param bool $updateActionTime Whether to update the last_action_time field
	 * @return bool True if experience was added and saved
	 */
	public function awardPetExperience(int $userId, int $amountOfExp, bool $updateActionTime = true): bool
	{
		if ($amountOfExp <= 0)
		{
			return false;
		}

		$pet = $this->getUserPet($userId);
		if (!$pet)
		{
			return false;
		}

		return $this->awardExperienceToPet($pet, $amountOfExp, $updateActionTime);
	}

	/**
	 * The single path for new visible threads and posts: adds the configured XP and the
	 * matching tracking columns in one save. Opted-out users get neither XP nor tracking;
	 * users without a pet are credited by the backfill when their pet is created.
	 *
	 * @param string $contentType self::CONTENT_THREAD or self::CONTENT_POST
	 * @return bool True if experience was added and saved
	 */
	public function awardContentExperience(int $userId, string $contentType): bool
	{
		if ($contentType !== self::CONTENT_THREAD && $contentType !== self::CONTENT_POST)
		{
			throw new \InvalidArgumentException("Unsupported content type '{$contentType}'");
		}

		$amount = $this->getContentExperiencePerUnit($contentType);
		if ($userId <= 0 || $amount <= 0 || UserPetOptOut::isDisabledByUserId($userId))
		{
			return false;
		}

		$pet = $this->getUserPet($userId);
		if (!$pet)
		{
			return false;
		}

		[$countField, $experienceField] = self::CONTENT_TRACKING[$contentType];
		$pet->$countField = (int) $pet->$countField + 1;
		$pet->$experienceField = (int) $pet->$experienceField + $amount;

		return $this->savePetWithExperience($pet, $amount);
	}

	public function getContentExperiencePerUnit(string $contentType): int
	{
		$optionId = self::CONTENT_TRACKING[$contentType][2] ?? null;
		if (!$optionId)
		{
			return 0;
		}

		return max(0, (int) \XF::options()->$optionId);
	}

	/**
	 * Recounts the user's threads, posts and accepted solutions and moves the pet's XP by
	 * the difference to what is tracked. Idempotent; opted-out users are left untouched.
	 *
	 * @param string[]|null $contentTypes Limit to these CONTENT_* types
	 * @return bool True if the pet changed
	 */
	public function syncContentExperience(int $userId, ?array $contentTypes = null): bool
	{
		if ($userId <= 0 || UserPetOptOut::isDisabledByUserId($userId))
		{
			return false;
		}

		$pet = $this->getUserPet($userId);
		if (!$pet)
		{
			return false;
		}

		return $this->syncPetContentExperience($pet, $contentTypes ?? array_keys(self::CONTENT_TRACKING));
	}

	public function syncThreadExperience(int $userId): void
	{
		$this->syncContentExperience($userId, [self::CONTENT_THREAD]);
	}

	public function syncPostExperience(int $userId): void
	{
		$this->syncContentExperience($userId, [self::CONTENT_POST]);
	}

	public function syncAcceptedSolutionExperience(int $userId): void
	{
		$this->syncContentExperience($userId, [self::CONTENT_SOLUTION]);
	}

	/**
	 * @param int $createdAfter Only content posted after this timestamp (0 = no lower bound)
	 * @param int $createdUntil Only content posted at or before this timestamp (0 = no upper bound)
	 */
	public function getContentCountForUser(string $contentType, int $userId, int $createdAfter = 0, int $createdUntil = 0): int
	{
		return match ($contentType)
		{
			self::CONTENT_THREAD => $this->getFilteredThreadCountForUser($userId, $createdAfter, $createdUntil),
			self::CONTENT_POST => $this->getFilteredPostCountForUser($userId, $createdAfter, $createdUntil),
			self::CONTENT_SOLUTION => $this->getAcceptedSolutionCountForUser($userId),
			default => throw new \InvalidArgumentException("Unsupported content type '{$contentType}'"),
		};
	}

	/**
	 * Accepted solutions of the user in visible question threads that match the post XP
	 * filter. Uses xf_thread_question.solution_user_id (indexed) instead of scanning posts.
	 */
	public function getAcceptedSolutionCountForUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		$criteria = ContentXpFilter::getCriteriaFromOptions(true);
		$conditions = [
			'thread_question.solution_user_id = ?',
			'post.message_state = ?',
			'thread.discussion_state = ?',
		];
		$params = [$userId, 'visible', 'visible'];

		$this->applyThreadFilterCriteria($criteria, $conditions, $params, 'thread');

		return (int) $this->db()->fetchOne(
			'
				SELECT COUNT(*)
				FROM xf_thread_question AS thread_question
				INNER JOIN xf_post AS post ON (post.post_id = thread_question.solution_post_id)
				INNER JOIN xf_thread AS thread ON (thread.thread_id = thread_question.thread_id)
				WHERE ' . implode(' AND ', $conditions),
			$params
		);
	}

	/**
	 * @param int $createdAfter Only threads started after this timestamp (0 = no lower bound)
	 * @param int $createdUntil Only threads started at or before this timestamp (0 = no upper bound)
	 */
	public function getFilteredThreadCountForUser(int $userId, int $createdAfter = 0, int $createdUntil = 0): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		$criteria = ContentXpFilter::getCriteriaFromOptions(false);
		$conditions = ['thread.user_id = ?', 'thread.discussion_state = ?'];
		$params = [$userId, 'visible'];

		if ($createdAfter > 0)
		{
			$conditions[] = 'thread.post_date > ?';
			$params[] = $createdAfter;
		}

		if ($createdUntil > 0)
		{
			$conditions[] = 'thread.post_date <= ?';
			$params[] = $createdUntil;
		}

		$this->applyThreadFilterCriteria($criteria, $conditions, $params);

		return (int) $this->db()->fetchOne(
			'
				SELECT COUNT(*)
				FROM xf_thread AS thread
				WHERE ' . implode(' AND ', $conditions),
			$params
		);
	}

	/**
	 * @param int $createdAfter Only replies posted after this timestamp (0 = no lower bound)
	 * @param int $createdUntil Only replies posted at or before this timestamp (0 = no upper bound)
	 */
	public function getFilteredPostCountForUser(int $userId, int $createdAfter = 0, int $createdUntil = 0): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		$criteria = ContentXpFilter::getCriteriaFromOptions(true);
		$conditions = [
			'post.user_id = ?',
			'post.position > 0',
			'post.message_state = ?',
			'thread.discussion_state = ?',
		];
		$params = [$userId, 'visible', 'visible'];

		if ($createdAfter > 0)
		{
			$conditions[] = 'post.post_date > ?';
			$params[] = $createdAfter;
		}

		if ($createdUntil > 0)
		{
			$conditions[] = 'post.post_date <= ?';
			$params[] = $createdUntil;
		}

		$this->applyThreadFilterCriteria($criteria, $conditions, $params, 'thread');

		return (int) $this->db()->fetchOne(
			'
				SELECT COUNT(*)
				FROM xf_post AS post
				INNER JOIN xf_thread AS thread ON (thread.thread_id = post.thread_id)
				WHERE ' . implode(' AND ', $conditions),
			$params
		);
	}

	/**
	 * Users with a pet who have any post (in any state) in the thread; the starter is
	 * included through the first post.
	 *
	 * @return int[]
	 */
	public function getPetUserIdsForThread(int $threadId): array
	{
		if ($threadId <= 0)
		{
			return [];
		}

		return array_map('intval', $this->db()->fetchAllColumn(
			'
				SELECT DISTINCT pet.user_id
				FROM xf_post AS post
				INNER JOIN xf_user_pets AS pet ON (pet.user_id = post.user_id)
				WHERE post.thread_id = ?
			',
			$threadId
		));
	}

	/**
	 * @return int[]
	 */
	public function getPetIdsForUser(int $userId): array
	{
		return array_map('intval', $this->db()->fetchAllColumn(
			'SELECT pet_id FROM xf_user_pets WHERE user_id = ? ORDER BY pet_id',
			$userId
		));
	}

	/**
	 * Hands every pet of the source user to the target user. Only used when the target
	 * has no pet of its own (user merge).
	 */
	public function reassignPets(int $fromUserId, int $toUserId): int
	{
		if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId)
		{
			return 0;
		}

		return $this->db()->update('xf_user_pets', ['user_id' => $toUserId], 'user_id = ?', $fromUserId);
	}

	public function deletePetsForUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return $this->db()->delete('xf_user_pets', 'user_id = ?', $userId);
	}

	/**
	 * @param string[] $contentTypes
	 * @param bool $sendAlert Alert the owner on a level-up
	 */
	protected function syncPetContentExperience(UserPets $pet, array $contentTypes, bool $sendAlert = true): bool
	{
		$delta = 0;
		$changed = false;

		foreach ($contentTypes AS $contentType)
		{
			if (!isset(self::CONTENT_TRACKING[$contentType]))
			{
				continue;
			}

			[$countField, $experienceField] = self::CONTENT_TRACKING[$contentType];

			$count = $this->getContentCountForUser($contentType, (int) $pet->user_id);
			$target = $count * $this->getContentExperiencePerUnit($contentType);
			$tracked = (int) $pet->$experienceField;

			if ($count === (int) $pet->$countField && $target === $tracked)
			{
				continue;
			}

			$delta += $target - $tracked;
			$pet->$countField = $count;
			$pet->$experienceField = $target;
			$changed = true;
		}

		if (!$changed)
		{
			return false;
		}

		if ($sendAlert)
		{
			return $this->savePetWithExperience($pet, $delta);
		}

		(new PetLeveling())->addExperience($pet, $delta);

		try
		{
			$pet->save();
		}
		catch (\Exception $e)
		{
			Logger::error('Could not sync pet content experience: ' . $e->getMessage(), [
				'pet_id' => $pet->pet_id,
				'user_id' => $pet->user_id,
			]);

			return false;
		}

		return true;
	}

	protected function sendLevelUpAlert(UserPets $pet, int $oldLevel, int $amountOfExp): void
	{
		/** @var User|null $user */
		$user = $this->em->find('XF:User', $pet->user_id);
		if (!$user)
		{
			return;
		}

		/** @var UserAlertRepository $alertRepo */
		$alertRepo = $this->repository('XF:UserAlert');
		$alertRepo->alertFromUser(
			$user,
			$user,
			'syl_userpet',
			$pet->pet_id,
			'levelup',
			[
				'old_level' => $oldLevel,
				'new_level' => $pet->level,
				'exp_amount' => $amountOfExp,
			],
			['autoRead' => true]
		);
	}

	/**
	 * @return array{choices:array<string,string>,sheets:array<string,array>}
	 */
	protected function buildSpritesheetRenderIndex(): array
	{
		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		$choices = [];
		$sheets = [];

		foreach ($spritesheetRepo->buildIndexList() AS $file)
		{
			$filename = (string) ($file['name'] ?? '');
			if ($filename === '')
			{
				continue;
			}

			$entity = $file['entity'] ?? null;

			$choices[$this->getSpritesheetChoiceId($filename)] = $filename;
			$sheets[$filename] = [
				'frame_width' => (int) ($entity?->frame_width ?: 192),
				'frame_height' => (int) ($entity?->frame_height ?: 192),
				'frames_per_animation' => (int) ($entity?->frames_per_animation ?: 4),
				'fps' => (int) ($entity?->fps ?: 4),
				'has_entity' => $entity !== null,
				'exists' => (bool) ($file['exists'] ?? false),
			];
		}

		return ['choices' => $choices, 'sheets' => $sheets];
	}

	/**
	 * @return \Symfony\Component\Cache\Adapter\AdapterInterface|null
	 */
	protected function getSharedCache()
	{
		try
		{
			return $this->app()->cache('', true, false);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * @param array{nodeIds:array<int,int>,prefixIds:array<int,int>,threadTypes:array<int,string>} $criteria
	 * @param array<int,string> $conditions
	 * @param array<int,mixed> $params
	 */
	protected function applyThreadFilterCriteria(array $criteria, array &$conditions, array &$params, string $threadAlias = 'thread'): void
	{
		if ($criteria['nodeIds'])
		{
			$expandedNodeIds = ContentXpFilter::expandSelectedNodeIds($criteria['nodeIds']);
			if (!$expandedNodeIds)
			{
				$conditions[] = '1 = 0';
			}
			else
			{
				$conditions[] = sprintf(
					'%s.node_id IN (%s)',
					$threadAlias,
					implode(', ', array_fill(0, count($expandedNodeIds), '?'))
				);
				$params = array_merge($params, $expandedNodeIds);
			}
		}

		if ($criteria['prefixIds'])
		{
			$conditions[] = sprintf(
				'%s.prefix_id IN (%s)',
				$threadAlias,
				implode(', ', array_fill(0, count($criteria['prefixIds']), '?'))
			);
			$params = array_merge($params, $criteria['prefixIds']);
		}

		if ($criteria['threadTypes'])
		{
			$conditions[] = sprintf(
				'%s.discussion_type IN (%s)',
				$threadAlias,
				implode(', ', array_fill(0, count($criteria['threadTypes']), '?'))
			);
			$params = array_merge($params, $criteria['threadTypes']);
		}
	}
}

<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Service\ContentXpFilter;
use Sylphian\UserPets\Service\PetLeveling;
use Sylphian\UserPets\XpSource;
use XF\Db\DuplicateKeyException;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;
use XF\Repository\UserAlertRepository;

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

	/**
	 * Versioned: a cached index of an older shape must never be read.
	 */
	public const SPRITESHEET_INDEX_CACHE_KEY = 'sylphianUserPetsSheetIndexV2';

	public const SPRITESHEET_INDEX_CACHE_TTL = 300;

	/**
	 * The add-on's own sheet, used when no sheet is available at all.
	 */
	public const DEFAULT_SPRITESHEET = 'sylphian_spritesheet.png';

	public const DEFAULT_FRAME_SIZE = 192;

	public const DEFAULT_FRAMES_PER_ANIMATION = 4;

	public const DEFAULT_FPS = 4;

	/**
	 * @var array{sheets:array<string,array>}|null
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
			$this->syncPetContentExperience($pet, array_keys(self::CONTENT_TRACKING), false, XpSource::RECOUNT);
		}

		return $pet;
	}

	/**
	 * Spritesheet data the pet renderers need, without a directory scan on cache hits:
	 * kept in the global cache (Redis) for a short time and once per request.
	 * Falls back to building it from the live index when no cache is configured.
	 *
	 * @return array{sheets:array<string,array{title:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int,has_entity:bool,exists:bool,evolve_level:int,evolves_to:string}>}
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
					if (is_array($value) && isset($value['sheets']) && is_array($value['sheets']))
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

	/**
	 * How a pet is drawn: its own sheet if that file is available, otherwise the default
	 * sheet option, otherwise the first available sheet; then the evolution for its level.
	 * Reads only the cached render index, so it is cheap for many pets on one page.
	 *
	 * @param UserPets|null $pet null draws the default sheet at level 1
	 * @return array{url:string,filename:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int,usingDefault:bool}
	 */
	public function getRenderConfigForPet(?UserPets $pet): array
	{
		$sheets = $this->getAvailableRenderSheets();

		$ownChoice = $pet ? trim((string) $pet->spritesheet) : '';
		$usingDefault = $ownChoice === '' || !isset($sheets[$ownChoice]);
		$filename = $usingDefault ? $this->getDefaultRenderFilename($sheets) : $ownChoice;

		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		if (isset($sheets[$filename]))
		{
			$level = $pet ? max(1, (int) $pet->level) : 1;
			$evolved = $spritesheetRepo->resolveEvolvedFilename($sheets, $filename, $level);
			if (isset($sheets[$evolved]))
			{
				$filename = $evolved;
			}
		}

		$sheet = $sheets[$filename] ?? [];

		return [
			'url' => $spritesheetRepo->getFileUrl($filename),
			'filename' => $filename,
			'frame_width' => (int) ($sheet['frame_width'] ?? self::DEFAULT_FRAME_SIZE),
			'frame_height' => (int) ($sheet['frame_height'] ?? self::DEFAULT_FRAME_SIZE),
			'frames_per_animation' => (int) ($sheet['frames_per_animation'] ?? self::DEFAULT_FRAMES_PER_ANIMATION),
			'fps' => (int) ($sheet['fps'] ?? self::DEFAULT_FPS),
			'usingDefault' => $usingDefault,
		];
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

	/**
	 * Adds experience to a loaded pet, recalculates its level, saves it, writes the change to
	 * the XP log and sends the level-up alert. Any other pending changes on the entity are
	 * saved with it; with amount 0 the pet is still saved but nothing is logged.
	 *
	 * Does not check the opt-out; callers acting for a user must.
	 *
	 * @param string $source One of XpSource
	 * @param int $actorUserId The member who made the change, for XpSource::ADMIN
	 * @return bool False if the pet could not be saved
	 */
	public function savePetWithExperience(
		UserPets $pet,
		int $amount,
		string $source = XpSource::OTHER,
		string $contentType = '',
		int $contentId = 0,
		int $actorUserId = 0
	): bool
	{
		return $this->applyExperienceChange($pet, $amount, true, $source, $contentType, $contentId, $actorUserId) !== null;
	}

	/**
	 * The one path that changes a pet's experience. The pet and its XP log row are written
	 * in one transaction, so experience never changes without a log row. The row holds the
	 * change that was applied: experience cannot go below 0, so a removal larger than the
	 * pet's XP logs only what was removed.
	 *
	 * For a saved pet the row is written first: if it fails, the entity was never saved and
	 * still matches the database, so the caller may retry with it.
	 *
	 * @param bool $sendAlert Alert the owner on a level-up
	 * @param string $source One of XpSource
	 * @param int $actorUserId The member who made the change, for XpSource::ADMIN
	 * @return int|null The experience actually added (negative when removed), null if the save failed
	 */
	public function applyExperienceChange(
		UserPets $pet,
		int $amount,
		bool $sendAlert,
		string $source,
		string $contentType = '',
		int $contentId = 0,
		int $actorUserId = 0
	): ?int
	{
		$oldExperience = (int) $pet->experience;
		$oldLevel = (int) $pet->level;
		$leveledUp = (new PetLeveling())->addExperience($pet, $amount);
		$applied = (int) $pet->experience - $oldExperience;

		$db = $this->db();
		$xpLogRepo = $this->getXpLogRepo();
		$db->beginTransaction();

		try
		{
			if ($pet->exists())
			{
				$xpLogRepo->log($pet, $applied, $source, $contentType, $contentId, $actorUserId);
				$pet->save(true, false);
			}
			else
			{
				$pet->save(true, false);
				$xpLogRepo->log($pet, $applied, $source, $contentType, $contentId, $actorUserId);
			}

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();

			// a retry with the same entity must not add the amount twice; a failed save
			// leaves the entity locked for writes, hence forceSet
			$pet->set('experience', $oldExperience, ['forceSet' => true]);
			$pet->set('level', $oldLevel, ['forceSet' => true]);

			if (!($e instanceof \Exception))
			{
				throw $e;
			}

			Logger::error('Could not save pet experience: ' . $e->getMessage(), [
				'pet_id' => $pet->pet_id,
				'user_id' => $pet->user_id,
				'amount' => $amount,
				'source' => $source,
			]);

			return null;
		}

		if ($leveledUp && $sendAlert)
		{
			$this->sendLevelUpAlert($pet, $oldLevel, $applied);
		}

		return $applied;
	}

	/**
	 * Awards a fixed amount of experience to a loaded pet (duel wins, tournament prizes,
	 * tutorials). Opted-out users get nothing.
	 *
	 * @param bool $updateActionTime Also restarts the pet action cooldown
	 * @param string $source One of XpSource
	 * @return bool True if experience was added and saved
	 */
	public function awardExperienceToPet(
		UserPets $pet,
		int $amount,
		bool $updateActionTime = false,
		string $source = XpSource::OTHER,
		string $contentType = '',
		int $contentId = 0
	): bool
	{
		if ($amount <= 0 || !$pet->pet_id || UserPetOptOut::isDisabledByUserId((int) $pet->user_id))
		{
			return false;
		}

		if ($updateActionTime)
		{
			$pet->last_action_time = \XF::$time;
		}

		return $this->savePetWithExperience($pet, $amount, $source, $contentType, $contentId);
	}

	/**
	 * Award experience to a user's pet. Users without a pet or with the pet disabled get nothing.
	 *
	 * @param int $userId The user ID to award experience to
	 * @param int $amountOfExp The amount of experience to award
	 * @param bool $updateActionTime Whether to update the last_action_time field
	 * @param string $source One of XpSource
	 * @return bool True if experience was added and saved
	 */
	public function awardPetExperience(
		int $userId,
		int $amountOfExp,
		bool $updateActionTime = true,
		string $source = XpSource::OTHER,
		string $contentType = '',
		int $contentId = 0
	): bool
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

		return $this->awardExperienceToPet($pet, $amountOfExp, $updateActionTime, $source, $contentType, $contentId);
	}

	/**
	 * The single path for new visible threads and posts: adds the configured XP and the
	 * matching tracking columns in one save. Opted-out users get neither XP nor tracking;
	 * users without a pet are credited by the backfill when their pet is created.
	 *
	 * @param string $contentType self::CONTENT_THREAD or self::CONTENT_POST
	 * @param int $contentId The thread or post ID, kept in the XP log
	 * @return bool True if experience was added and saved
	 */
	public function awardContentExperience(int $userId, string $contentType, int $contentId = 0): bool
	{
		$source = match ($contentType)
		{
			self::CONTENT_THREAD => XpSource::THREAD,
			self::CONTENT_POST => XpSource::POST,
			default => throw new \InvalidArgumentException("Unsupported content type '{$contentType}'"),
		};

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

		return $this->savePetWithExperience($pet, $amount, $source, $contentType, $contentId);
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
	 * @param string $source Logged with the difference: XpSource::RECOUNT, or XpSource::SOLUTION
	 *     when an accepted solution changed
	 * @param string $logContentType The content the change is logged against, if any
	 * @return bool True if the pet changed
	 */
	public function syncContentExperience(
		int $userId,
		?array $contentTypes = null,
		string $source = XpSource::RECOUNT,
		string $logContentType = '',
		int $logContentId = 0
	): bool
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

		return $this->syncPetContentExperience(
			$pet,
			$contentTypes ?? array_keys(self::CONTENT_TRACKING),
			true,
			$source,
			$logContentType,
			$logContentId
		);
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
	 * Adds the tournament wins of the given pets to another pet and clears them on the
	 * given pets, so a repeated merge cannot count them twice.
	 *
	 * @param int[] $fromPetIds
	 * @return int The number of wins moved
	 */
	public function moveTournamentWins(array $fromPetIds, int $toPetId): int
	{
		$fromPetIds = array_values(array_diff(
			array_unique(array_filter(array_map('intval', $fromPetIds))),
			[$toPetId]
		));
		if (!$fromPetIds || $toPetId <= 0)
		{
			return 0;
		}

		$db = $this->db();

		return (int) $db->executeTransaction(function () use ($db, $fromPetIds, $toPetId): int
		{
			$wins = (int) $db->fetchOne(
				'SELECT COALESCE(SUM(tournament_wins), 0) FROM xf_user_pets WHERE pet_id IN (' . $db->quote($fromPetIds) . ') FOR UPDATE'
			);
			if ($wins <= 0)
			{
				return 0;
			}

			$db->query('UPDATE xf_user_pets SET tournament_wins = tournament_wins + ? WHERE pet_id = ?', [$wins, $toPetId]);
			$db->update('xf_user_pets', ['tournament_wins' => 0], 'pet_id IN (' . $db->quote($fromPetIds) . ')');

			return $wins;
		});
	}

	/**
	 * @param string[] $contentTypes
	 * @param bool $sendAlert Alert the owner on a level-up
	 * @param string $source One of XpSource, logged with the difference
	 */
	protected function syncPetContentExperience(
		UserPets $pet,
		array $contentTypes,
		bool $sendAlert = true,
		string $source = XpSource::RECOUNT,
		string $logContentType = '',
		int $logContentId = 0
	): bool
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

		return $this->applyExperienceChange($pet, $delta, $sendAlert, $source, $logContentType, $logContentId) !== null;
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
	 * Sheets a pet can be drawn with: the file exists. Keyed by file name, in index order.
	 *
	 * @return array<string,array{title:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int,has_entity:bool,exists:bool,evolve_level:int,evolves_to:string}>
	 */
	protected function getAvailableRenderSheets(): array
	{
		$sheets = [];
		foreach ($this->getSpritesheetRenderIndex()['sheets'] AS $filename => $sheet)
		{
			if (!empty($sheet['exists']))
			{
				$sheets[(string) $filename] = $sheet;
			}
		}

		return $sheets;
	}

	/**
	 * @param array<string,array> $sheets Available sheets, see getAvailableRenderSheets()
	 */
	protected function getDefaultRenderFilename(array $sheets): string
	{
		$option = trim((string) \XF::options()->sylphian_userpets_default_spritesheet);
		if ($option !== '' && isset($sheets[$option]))
		{
			return $option;
		}

		if ($sheets)
		{
			return (string) array_key_first($sheets);
		}

		return $option !== '' ? $option : self::DEFAULT_SPRITESHEET;
	}

	/**
	 * @return array{sheets:array<string,array{title:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int,has_entity:bool,exists:bool,evolve_level:int,evolves_to:string}>}
	 */
	protected function buildSpritesheetRenderIndex(): array
	{
		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		$sheets = [];

		foreach ($spritesheetRepo->buildIndexList() AS $file)
		{
			$filename = (string) ($file['name'] ?? '');
			if ($filename === '')
			{
				continue;
			}

			$entity = $file['entity'] ?? null;

			$title = trim((string) ($file['title'] ?? ($entity?->title ?? '')));

			$sheets[$filename] = [
				'title' => $title !== '' ? $title : $filename,
				'frame_width' => (int) ($entity?->frame_width ?: self::DEFAULT_FRAME_SIZE),
				'frame_height' => (int) ($entity?->frame_height ?: self::DEFAULT_FRAME_SIZE),
				'frames_per_animation' => (int) ($entity?->frames_per_animation ?: self::DEFAULT_FRAMES_PER_ANIMATION),
				'fps' => (int) ($entity?->fps ?: self::DEFAULT_FPS),
				'has_entity' => $entity !== null,
				'exists' => (bool) ($file['exists'] ?? false),
				'evolve_level' => max(0, (int) ($file['evolve_level'] ?? $entity?->evolve_level ?? 0)),
				'evolves_to' => (string) ($file['evolves_to'] ?? $entity?->evolves_to ?? ''),
			];
		}

		return ['sheets' => $sheets];
	}

	protected function getXpLogRepo(): UserPetsXpLogRepository
	{
		/** @var UserPetsXpLogRepository $repo */
		$repo = $this->repository(UserPetsXpLogRepository::class);

		return $repo;
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

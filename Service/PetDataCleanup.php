<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use Sylphian\UserPets\Repository\UserPetsXpLogRepository;
use XF\Repository\UserAlertRepository;
use XF\Service\AbstractService;

/**
 * Removes or hands over the pet data of a user (pet rows, XP history, tutorial progress, open
 * duels, places in tournaments that have not started and the alerts about the pet) when the
 * user is deleted or merged into another account. Finished duels and brackets are kept as history.
 */
class PetDataCleanup extends AbstractService
{
	/**
	 * Delay before the merged account's content XP is recounted, so a merge that runs
	 * in several job steps has reassigned the content first. The recount is idempotent.
	 */
	public const MERGE_SYNC_DELAY = 300;

	/**
	 * @return int[] IDs of the pets that were deleted
	 */
	public function deleteForUser(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$petsRepo = $this->getPetsRepo();
		$petIds = $petsRepo->getPetIdsForUser($userId);

		$this->getDuelRepo()->deleteOpenDuelsForPets($petIds);
		$this->getTournamentRepo()->removeUserFromPendingTournaments($userId);
		$this->getTutorialRepo()->deleteTutorialsForUser($userId);
		$this->getXpLogRepo()->deleteLogsForPets($petIds);
		$petsRepo->deletePetsForUser($userId);

		if ($petIds)
		{
			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->repository('XF:UserAlert');
			$alertRepo->fastDeleteAlertsForContent('syl_userpet', $petIds);
		}

		return $petIds;
	}

	/**
	 * Keeps the target's own pet when it has one; otherwise the source's pet, its XP history
	 * and the tutorial progress move over. When both have a pet, the target's pet takes the
	 * source pet's XP history, duels and tournament wins. Whatever stays on the source is
	 * removed when the merge deletes the source user.
	 *
	 * @return bool True if the source pet was handed to the target
	 */
	public function mergeUsers(int $targetUserId, int $sourceUserId): bool
	{
		if ($targetUserId <= 0 || $sourceUserId <= 0 || $targetUserId === $sourceUserId)
		{
			return false;
		}

		$petsRepo = $this->getPetsRepo();
		$targetPetIds = $petsRepo->getPetIdsForUser($targetUserId);
		$sourcePetIds = $petsRepo->getPetIdsForUser($sourceUserId);
		$transferred = false;

		if (!$targetPetIds && $sourcePetIds)
		{
			$petsRepo->reassignPets($sourceUserId, $targetUserId);
			$this->getXpLogRepo()->reassignLogsToUser($sourcePetIds, $targetUserId);
			$this->getTutorialRepo()->reassignTutorials($sourceUserId, $targetUserId);
			$this->getTournamentRepo()->reassignEntriesToUser($sourceUserId, $targetUserId);
			$transferred = true;
		}
		else if ($targetPetIds && $sourcePetIds)
		{
			// The target keeps its own pet, so the source pet's record counts for it.
			$targetPetId = $targetPetIds[0];
			$xpLogRepo = $this->getXpLogRepo();
			$duelRepo = $this->getDuelRepo();

			foreach ($sourcePetIds AS $sourcePetId)
			{
				$xpLogRepo->reassignLogs($sourcePetId, $targetPetId, $targetUserId);
			}
			$petsRepo->moveTournamentWins($sourcePetIds, $targetPetId);

			// The tournament rows move too, so a later counter recount keeps the merged titles.
			foreach ($sourcePetIds AS $sourcePetId)
			{
				$this->getTournamentRepo()->mergePetIntoPet($sourcePetId, $targetPetId, $targetUserId);
				$duelRepo->mergeDuelsIntoPet($sourcePetId, $targetPetId);
			}
		}

		$this->app->jobManager()->enqueueLater(
			'sylphianUserPetsMergeSync' . $targetUserId,
			\XF::$time + self::MERGE_SYNC_DELAY,
			'Sylphian\UserPets:RebuildSolutionExperience',
			['user_ids' => [$targetUserId]]
		);

		return $transferred;
	}

	protected function getPetsRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');

		return $repo;
	}

	protected function getDuelRepo(): UserPetsDuelRepository
	{
		/** @var UserPetsDuelRepository $repo */
		$repo = $this->repository(UserPetsDuelRepository::class);

		return $repo;
	}

	protected function getTournamentRepo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository(UserPetsTournamentRepository::class);

		return $repo;
	}

	protected function getXpLogRepo(): UserPetsXpLogRepository
	{
		/** @var UserPetsXpLogRepository $repo */
		$repo = $this->repository(UserPetsXpLogRepository::class);

		return $repo;
	}

	protected function getTutorialRepo(): UserPetsTutorialRepository
	{
		/** @var UserPetsTutorialRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTutorial');

		return $repo;
	}
}

<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use XF\Repository\UserAlertRepository;
use XF\Service\AbstractService;

/**
 * Removes or hands over the pet data of a user (pet rows, tutorial progress, open duels,
 * places in tournaments that have not started and the alerts about the pet) when the user
 * is deleted or merged into another account. Finished duels and brackets are kept as history.
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
	 * Keeps the target's own pet when it has one; otherwise the source's pet and tutorial
	 * progress move over. Whatever stays on the source is removed when the merge deletes
	 * the source user.
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
			$this->getTutorialRepo()->reassignTutorials($sourceUserId, $targetUserId);
			$this->db()->update(
				'xf_user_pets_tournament_entries',
				['user_id' => $targetUserId],
				'user_id = ?',
				$sourceUserId,
				'IGNORE'
			);
			$transferred = true;
		}
		else if ($targetPetIds && $sourcePetIds)
		{
			// The target keeps its own pet, so the source pet's duel record counts for it.
			foreach ($sourcePetIds AS $sourcePetId)
			{
				$this->getDuelRepo()->mergeDuelsIntoPet($sourcePetId, $targetPetIds[0]);
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

	protected function getTutorialRepo(): UserPetsTutorialRepository
	{
		/** @var UserPetsTutorialRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTutorial');

		return $repo;
	}
}

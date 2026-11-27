<?php

namespace Sylphian\UserPets\Job;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Job\AbstractRebuildJob;
use XF\Phrase;

/**
 * Recounts thread, post and accepted-solution XP for every pet, or only for the pets of
 * the users given in "user_ids". Opted-out users are skipped.
 */
class RebuildSolutionExperience extends AbstractRebuildJob
{
	protected $defaultData = [
		'user_ids' => [],
	];

	protected function getNextIds($start, $batch): array
	{
		$db = $this->app->db();
		$userIds = $this->getUserIdFilter();

		if ($this->data['user_ids'] && !$userIds)
		{
			return [];
		}

		$userCondition = $userIds ? ' AND user_id IN (' . $db->quote($userIds) . ')' : '';

		return $db->fetchAllColumn(
			$db->limit(
				'
					SELECT pet_id
					FROM xf_user_pets
					WHERE pet_id > ?' . $userCondition . '
					ORDER BY pet_id
				',
				$batch
			),
			[$start]
		);
	}

	protected function rebuildById($id): void
	{
		/** @var UserPets|null $pet */
		$pet = $this->app->em()->find('Sylphian\UserPets:UserPets', $id);
		if (!$pet)
		{
			return;
		}

		/** @var UserPetsRepository $repo */
		$repo = $this->app->repository('Sylphian\UserPets:UserPets');
		$repo->syncContentExperience((int) $pet->user_id);
	}

	protected function getStatusType(): Phrase
	{
		return \XF::phrase('sylphian_userpets_rebuild_content_xp_job');
	}

	/**
	 * @return int[]
	 */
	protected function getUserIdFilter(): array
	{
		$userIds = array_filter(array_map('intval', (array) $this->data['user_ids']));

		return array_values(array_unique($userIds));
	}
}

<?php

namespace Sylphian\UserPets\Job;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\Job\AbstractJob;
use XF\Job\JobResult;

/**
 * Rebuilds duel_wins, duel_losses and tournament_wins of every pet from the completed duels and
 * tournaments, a batch of pets per step. Each batch is set to the counted values, so a resumed
 * or repeated run gives the same result.
 */
class RebuildPetCounters extends AbstractJob
{
	public const int MAX_BATCH = 5000;

	protected $defaultData = [
		'start' => 0,
		'batch' => 500,
	];

	public function run($maxRunTime): JobResult
	{
		$startTime = microtime(true);
		$db = $this->app->db();

		$batch = max(1, min(self::MAX_BATCH, (int) $this->data['batch']));

		$petIds = array_map('intval', $db->fetchAllColumn(
			$db->limit(
				'
					SELECT pet_id
					FROM ' . UserPetsDuelRepository::PETS_TABLE . '
					WHERE pet_id > ?
					ORDER BY pet_id
				',
				$batch
			),
			(int) $this->data['start']
		));
		if (!$petIds)
		{
			return $this->complete();
		}

		$this->getDuelRepo()->recountDuelCounters($petIds);

		$db->query(
			'
				UPDATE ' . UserPetsDuelRepository::PETS_TABLE . ' AS pet
				SET pet.tournament_wins = (
					SELECT COUNT(*)
					FROM xf_user_pets_tournaments AS tournament
					WHERE tournament.winner_pet_id = pet.pet_id
						AND tournament.status = ?
				)
				WHERE pet.pet_id IN (' . $db->quote($petIds) . ')
			',
			'completed'
		);

		$this->data['start'] = end($petIds);
		$this->data['batch'] = $this->calculateOptimalBatch($batch, count($petIds), $startTime, $maxRunTime, self::MAX_BATCH);

		return $this->resume();
	}

	protected function getDuelRepo(): UserPetsDuelRepository
	{
		/** @var UserPetsDuelRepository */
		return $this->app->repository('Sylphian\UserPets:UserPetsDuel');
	}

	public function getStatusMessage(): string
	{
		return sprintf(
			'%s... %s (%s)',
			\XF::phrase('rebuilding'),
			\XF::phrase('sylphian_userpets_pet_counters'),
			$this->data['start']
		);
	}

	public function canCancel(): bool
	{
		return true;
	}

	public function canTriggerByChoice(): bool
	{
		return true;
	}
}

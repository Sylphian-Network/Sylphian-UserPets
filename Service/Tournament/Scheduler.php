<?php

namespace Sylphian\UserPets\Service\Tournament;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Service\UserPetTournament;
use XF\Service\AbstractService;

/**
 * Carries out the tournament schedule: opens and closes registration, starts tournaments
 * and plays interval rounds whose dates are due. Every step goes through the tournament
 * service's guarded lifecycle methods, so a step an admin already took is skipped.
 *
 * Work per run is bounded: cheap status changes and the heavier starts and rounds have
 * separate limits, and no new start or round begins after the time budget. What is left
 * is still due on the next run.
 */
class Scheduler extends AbstractService
{
	public const DEFAULT_STATUS_CHANGE_LIMIT = 50;

	public const DEFAULT_HEAVY_STEP_LIMIT = 5;

	public const DEFAULT_TIME_LIMIT = 10.0;

	protected int $statusChangeLimit = self::DEFAULT_STATUS_CHANGE_LIMIT;

	protected int $heavyStepLimit = self::DEFAULT_HEAVY_STEP_LIMIT;

	protected float $timeLimit = self::DEFAULT_TIME_LIMIT;

	/**
	 * @param float $timeLimit seconds after which no new start or round begins; 0 = no limit
	 */
	public function setLimits(int $statusChangeLimit, int $heavyStepLimit, float $timeLimit): void
	{
		$this->statusChangeLimit = max(1, $statusChangeLimit);
		$this->heavyStepLimit = max(1, $heavyStepLimit);
		$this->timeLimit = max(0.0, $timeLimit);
	}

	/**
	 * @return array{opened:int, closed:int, started:int, cancelled:int, rounds:int}
	 */
	public function run(?int $now = null): array
	{
		$now = $now ?? \XF::$time;
		$startedAt = microtime(true);
		$summary = ['opened' => 0, 'closed' => 0, 'started' => 0, 'cancelled' => 0, 'rounds' => 0];

		$repo = $this->getTournamentRepo();
		$service = $this->getTournamentService();

		foreach ($repo->findDueRegistrationOpenings($now, $this->statusChangeLimit) AS $tournament)
		{
			$result = $this->runStep($tournament, fn () => $service->openScheduledRegistration($tournament, $now));
			if ($result['success'])
			{
				$summary['opened']++;
			}
		}

		foreach ($repo->findDueRegistrationClosings($now, $this->statusChangeLimit) AS $tournament)
		{
			$result = $this->runStep($tournament, fn () => $service->closeScheduledRegistration($tournament, $now));
			if ($result['success'])
			{
				$summary['closed']++;
			}
		}

		foreach ($repo->findDueStarts($now, $this->heavyStepLimit) AS $tournament)
		{
			if ($this->isOutOfTime($startedAt))
			{
				return $summary;
			}

			$result = $this->runStep($tournament, fn () => $service->startScheduledTournament($tournament, $now));
			if ($result['success'])
			{
				$summary[empty($result['cancelled']) ? 'started' : 'cancelled']++;
			}
		}

		foreach ($repo->findDueRounds($now, $this->heavyStepLimit) AS $tournament)
		{
			if ($this->isOutOfTime($startedAt))
			{
				return $summary;
			}

			$result = $this->runStep($tournament, fn () => $service->playScheduledRound($tournament, $now));
			if ($result['success'])
			{
				$summary['rounds']++;
			}
		}

		return $summary;
	}

	/**
	 * One broken tournament must not hold up the others, so its failure is logged and the
	 * run goes on; it is retried on the next run.
	 *
	 * @param callable(): array $step
	 *
	 * @return array{success:bool, cancelled?:bool}
	 */
	protected function runStep(UserPetsTournament $tournament, callable $step): array
	{
		try
		{
			return $step();
		}
		catch (\Exception $e)
		{
			Logger::error('A scheduled pet tournament step failed', [
				'tournament_id' => $tournament->tournament_id,
				'exception' => $e,
			]);

			return ['success' => false];
		}
	}

	protected function isOutOfTime(float $startedAt): bool
	{
		return $this->timeLimit > 0 && (microtime(true) - $startedAt) >= $this->timeLimit;
	}

	protected function getTournamentRepo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTournament');
		return $repo;
	}

	protected function getTournamentService(): UserPetTournament
	{
		/** @var UserPetTournament $service */
		$service = $this->service('Sylphian\UserPets:UserPetTournament');
		return $service;
	}
}

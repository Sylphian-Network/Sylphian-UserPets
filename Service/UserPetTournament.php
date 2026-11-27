<?php

namespace Sylphian\UserPets\Service;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Alert\TournamentHandler;
use Sylphian\UserPets\Duel\WinnerPicker;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Entity\UserPetsTournamentEntry;
use Sylphian\UserPets\Entity\UserPetsTournamentMatch;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Service\Tournament\Bracket;
use Sylphian\UserPets\XpSource;
use XF\Db\DuplicateKeyException;
use XF\Entity\User;
use XF\Phrase;
use XF\Repository\UserAlertRepository;
use XF\Service\AbstractService;

/**
 * Tournament lifecycle. Every state change locks the tournament row and checks the
 * stored status inside the transaction, so double submits, parallel requests and a cron
 * run racing an admin click cannot start a tournament twice, regenerate its bracket,
 * play a round twice or pay the prize twice.
 *
 * The scheduled variants (used by the tournament cron) also check, under the same lock
 * or in the same conditional update, that their schedule date is still due.
 *
 * Alerts are collected while the transaction runs and sent after it commits, so a rolled
 * back change never alerts anyone and the tournament lock is not held while they go out.
 */
class UserPetTournament extends AbstractService
{
	/**
	 * More than enough for the largest bracket (512 entrants play 9 rounds).
	 */
	protected const MAX_AUTOMATIC_ROUNDS = 16;

	protected const SECONDS_PER_HOUR = 3600;

	protected const SECONDS_PER_DAY = 86400;

	/**
	 * @var list<array{user_id:int, action:string, extra:array<string, mixed>}>
	 */
	protected array $pendingAlerts = [];

	/**
	 * @return array{success:bool, error?:Phrase, entry?:UserPetsTournamentEntry}
	 */
	public function joinTournament(UserPetsTournament $tournament, User $user): array
	{
		if (!$tournament->canJoin($user, $error))
		{
			return $this->failure($error ?: \XF::phrase('sylphian_userpets_tournament_registration_closed'));
		}

		$pet = $this->getPetRepo()->getOrCreatePet($user->user_id);
		if (!$pet->exists())
		{
			return $this->failure(\XF::phrase('sylphian_userpets_unknown_error'));
		}

		$repo = $this->getTournamentRepo();
		$db = $this->db();
		$db->beginTransaction();

		try
		{
			$row = $repo->lockTournament($tournament->tournament_id);
			if (!$row || $row['status'] !== UserPetsTournament::STATUS_REGISTRATION_OPEN)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_registration_closed'));
			}

			if ($repo->isUserRegistered($tournament->tournament_id, $user->user_id)
				|| $repo->isPetRegistered($tournament->tournament_id, $pet->pet_id)
			)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_already_joined'));
			}

			if ($repo->getEntryCount($tournament->tournament_id) >= (int) $row['max_participants'])
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_full'));
			}

			/** @var UserPetsTournamentEntry $entry */
			$entry = $this->em()->create('Sylphian\UserPets:UserPetsTournamentEntry');
			$entry->tournament_id = $tournament->tournament_id;
			$entry->pet_id = $pet->pet_id;
			$entry->user_id = $user->user_id;
			$entry->seed = $repo->getNextSeed($tournament->tournament_id);
			$entry->joined_at = \XF::$time;
			$entry->save(true, false);

			$db->commit();
		}
		catch (DuplicateKeyException $e)
		{
			$db->rollback();
			return $this->failure(\XF::phrase('sylphian_userpets_tournament_already_joined'));
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			throw $e;
		}

		return ['success' => true, 'entry' => $entry];
	}

	/**
	 * Opens registration by hand. A manual open takes over from schedule dates that have
	 * already passed, so the cron does not close or start the tournament straight away.
	 *
	 * @return array{success:bool, error?:Phrase}
	 */
	public function openRegistration(UserPetsTournament $tournament): array
	{
		$extra = ['registration_opened_at' => \XF::$time];
		foreach (['registration_close_date', 'start_date'] AS $column)
		{
			$date = (int) $tournament->get($column);
			if ($date > 0 && $date <= \XF::$time)
			{
				$extra[$column] = 0;
			}
		}

		return $this->changeStatus(
			$tournament,
			[UserPetsTournament::STATUS_DRAFT, UserPetsTournament::STATUS_REGISTRATION_CLOSED],
			UserPetsTournament::STATUS_REGISTRATION_OPEN,
			$extra
		);
	}

	/**
	 * @return array{success:bool, error?:Phrase}
	 */
	public function openScheduledRegistration(UserPetsTournament $tournament, int $now): array
	{
		return $this->changeStatus(
			$tournament,
			[UserPetsTournament::STATUS_DRAFT],
			UserPetsTournament::STATUS_REGISTRATION_OPEN,
			['registration_opened_at' => \XF::$time],
			'registration_open_date',
			$now
		);
	}

	/**
	 * @return array{success:bool, error?:Phrase}
	 */
	public function closeRegistration(UserPetsTournament $tournament): array
	{
		return $this->changeStatus(
			$tournament,
			[UserPetsTournament::STATUS_REGISTRATION_OPEN],
			UserPetsTournament::STATUS_REGISTRATION_CLOSED,
			['registration_closed_at' => \XF::$time]
		);
	}

	/**
	 * @return array{success:bool, error?:Phrase}
	 */
	public function closeScheduledRegistration(UserPetsTournament $tournament, int $now): array
	{
		return $this->changeStatus(
			$tournament,
			[UserPetsTournament::STATUS_REGISTRATION_OPEN],
			UserPetsTournament::STATUS_REGISTRATION_CLOSED,
			['registration_closed_at' => \XF::$time],
			'registration_close_date',
			$now
		);
	}

	/**
	 * Cancels by hand and alerts the entrants. A tournament cancelled this way is not
	 * repeated; only one that completes or that the schedule cancels is.
	 *
	 * @return array{success:bool, error?:Phrase}
	 */
	public function cancelTournament(UserPetsTournament $tournament): array
	{
		$result = $this->changeStatus(
			$tournament,
			UserPetsTournament::getCancellableStatuses(),
			UserPetsTournament::STATUS_CANCELLED,
			['next_round_date' => 0]
		);

		if ($result['success'])
		{
			$this->pendingAlerts = [];
			foreach ($this->getTournamentRepo()->getEntrantUserIds($tournament->tournament_id) AS $userId)
			{
				$this->queueAlert($userId, TournamentHandler::ACTION_CANCELLED);
			}
			$this->flushAlerts($tournament);
		}

		return $result;
	}

	/**
	 * Draws the seeds, writes the bracket and moves the tournament to in progress.
	 * Only a tournament with open or closed registration and at least two entries can
	 * start; a second submit finds it in progress and changes nothing.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool, cancelled?:bool}
	 */
	public function startTournament(UserPetsTournament $tournament): array
	{
		return $this->start($tournament, 0);
	}

	/**
	 * The cron's start: runs only while the start date is due, and a tournament with fewer
	 * than two entrants is cancelled (and repeated, if it repeats) instead of waiting.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool, cancelled?:bool}
	 */
	public function startScheduledTournament(UserPetsTournament $tournament, int $now): array
	{
		return $this->start($tournament, max(1, $now));
	}

	/**
	 * Plays the lowest round that has ready matches. In automatic mode without a round
	 * interval it keeps going until the tournament is complete; with an interval, or in
	 * manual mode, it plays one round.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	public function advanceTournament(UserPetsTournament $tournament): array
	{
		$roundLimit = ($tournament->resolution_mode === UserPetsTournament::MODE_AUTOMATIC && !$tournament->hasRoundInterval())
			? static::MAX_AUTOMATIC_ROUNDS
			: 1;

		$advanced = false;
		for ($i = 0; $i < $roundLimit; $i++)
		{
			$result = $this->advanceOneRound($tournament);
			if (!$result['success'])
			{
				return $advanced ? ['success' => true, 'completed' => false, 'advanced' => true] : $result;
			}

			$advanced = $advanced || $result['advanced'];
			if ($result['completed'])
			{
				return ['success' => true, 'completed' => true, 'advanced' => $advanced];
			}
		}

		return ['success' => true, 'completed' => false, 'advanced' => $advanced];
	}

	/**
	 * The cron's round: plays one round only while the tournament runs automatically with a
	 * round interval and its next round date is due. An admin who played the round first
	 * has moved that date on, so the cron then finds nothing to do.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	public function playScheduledRound(UserPetsTournament $tournament, int $now): array
	{
		return $this->advanceOneRound($tournament, max(1, $now));
	}

	/**
	 * @param int $dueBy 0 for an admin start; otherwise the start date must be due by then
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool, cancelled?:bool}
	 */
	protected function start(UserPetsTournament $tournament, int $dueBy): array
	{
		$repo = $this->getTournamentRepo();
		$db = $this->db();
		$this->pendingAlerts = [];
		$db->beginTransaction();

		try
		{
			$row = $repo->lockTournament($tournament->tournament_id);
			if (!$row || !in_array($row['status'], UserPetsTournament::getStartableStatuses(), true))
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			if ($dueBy && !$this->isDue((int) $row['start_date'], $dueBy))
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			$petIds = [];
			$entrantUserIds = [];
			foreach ($repo->getEntriesForTournament($tournament->tournament_id) AS $entry)
			{
				$petIds[] = (int) $entry->pet_id;
				$entrantUserIds[] = (int) $entry->user_id;
			}
			$petIds = array_values(array_unique(array_filter($petIds)));
			$entrantUserIds = array_values(array_unique(array_filter($entrantUserIds)));

			if (count($petIds) < UserPetsTournament::MIN_PARTICIPANTS)
			{
				if (!$dueBy)
				{
					$db->rollback();
					return $this->failure(\XF::phrase('sylphian_userpets_tournament_not_enough_entries'));
				}

				return $this->cancelForLackOfEntrants($tournament, $row, $entrantUserIds);
			}

			$petIds = $this->drawSeedOrder($petIds);
			$rounds = Bracket::build($petIds);

			$seedByPetId = [];
			foreach ($petIds AS $position => $petId)
			{
				$seedByPetId[$petId] = $position + 1;
			}

			$repo->clearMatchesForTournament($tournament->tournament_id);
			$repo->insertBracket($tournament->tournament_id, $rounds, \XF::$time);
			$repo->updateEntrySeeds($tournament->tournament_id, $seedByPetId);

			$usesInterval = $this->rowUsesRoundInterval($row);
			$extra = [
				'started_at' => \XF::$time,
				'registration_closed_at' => (int) $row['registration_closed_at'] ?: \XF::$time,
				// due at once: if the first round fails below, the cron plays it on its next run
				'next_round_date' => $usesInterval ? \XF::$time : 0,
			];
			if (!$repo->changeStatusIf(
				$tournament->tournament_id,
				UserPetsTournament::getStartableStatuses(),
				UserPetsTournament::STATUS_IN_PROGRESS,
				$extra
			))
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			foreach ($entrantUserIds AS $userId)
			{
				$this->queueAlert($userId, TournamentHandler::ACTION_STARTED);
			}

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			$this->pendingAlerts = [];
			throw $e;
		}

		$this->syncEntity($tournament, ['status' => UserPetsTournament::STATUS_IN_PROGRESS] + $extra);
		$this->flushAlerts($tournament);

		if ($row['resolution_mode'] !== UserPetsTournament::MODE_AUTOMATIC)
		{
			return ['success' => true, 'completed' => false, 'advanced' => false];
		}

		if ($usesInterval)
		{
			$result = $this->advanceOneRound($tournament);
			return $result['success'] ? $result : ['success' => true, 'completed' => false, 'advanced' => false];
		}

		return $this->advanceTournament($tournament);
	}

	/**
	 * Runs inside start()'s transaction with the row locked; commits it.
	 *
	 * @param list<int> $entrantUserIds
	 *
	 * @return array{success:bool, error?:Phrase, completed:bool, advanced:bool, cancelled:bool}
	 */
	protected function cancelForLackOfEntrants(UserPetsTournament $tournament, array $row, array $entrantUserIds): array
	{
		$db = $this->db();

		$extra = ['next_round_date' => 0];
		if (!$this->getTournamentRepo()->changeStatusIf(
			$tournament->tournament_id,
			UserPetsTournament::getStartableStatuses(),
			UserPetsTournament::STATUS_CANCELLED,
			$extra
		))
		{
			$db->rollback();
			return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
		}

		foreach ($entrantUserIds AS $userId)
		{
			$this->queueAlert($userId, TournamentHandler::ACTION_CANCELLED);
		}

		$this->createRepeatCopy($row);

		$db->commit();

		$this->syncEntity($tournament, ['status' => UserPetsTournament::STATUS_CANCELLED] + $extra);
		$this->flushAlerts($tournament);

		return ['success' => true, 'completed' => false, 'advanced' => false, 'cancelled' => true];
	}

	/**
	 * @param int $dueBy 0 for an admin advance; otherwise the next round date must be due by then
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	protected function advanceOneRound(UserPetsTournament $tournament, int $dueBy = 0): array
	{
		$repo = $this->getTournamentRepo();
		$db = $this->db();
		$this->pendingAlerts = [];
		$db->beginTransaction();

		try
		{
			$row = $repo->lockTournament($tournament->tournament_id);
			if (!$row || $row['status'] !== UserPetsTournament::STATUS_IN_PROGRESS)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_not_in_progress'));
			}

			if ($dueBy && (!$this->rowUsesRoundInterval($row) || !$this->isDue((int) $row['next_round_date'], $dueBy)))
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			[$rounds, $matchEntities] = $this->loadBracket($tournament);
			if (!$rounds)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_no_ready_matches'));
			}

			Bracket::resolve($rounds);

			$advanced = false;
			$round = Bracket::isComplete($rounds) ? null : Bracket::getNextPlayableRound($rounds);
			if ($round !== null)
			{
				foreach (Bracket::getReadyMatchIndexes($rounds, $round) AS $index)
				{
					$match = $matchEntities[$round][$index];
					$winnerPetId = $this->decideMatch($match);
					Bracket::applyResult($rounds, $round, $index, $winnerPetId);
					$this->queueMatchAlerts($match, $winnerPetId, $round);
				}

				Bracket::resolve($rounds);
				$advanced = true;
			}

			$this->saveBracket($rounds, $matchEntities);

			$completed = false;
			if (Bracket::isComplete($rounds))
			{
				$this->finalizeTournament($tournament, Bracket::getChampion($rounds), $row);
				$completed = true;
			}
			elseif (!$advanced)
			{
				$db->commit();
				$this->pendingAlerts = [];
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_no_ready_matches'));
			}
			elseif ($this->rowUsesRoundInterval($row))
			{
				$nextRoundDate = \XF::$time + (int) $row['round_interval_hours'] * static::SECONDS_PER_HOUR;
				$repo->setNextRoundDate($tournament->tournament_id, $nextRoundDate);
				$this->syncEntity($tournament, ['next_round_date' => $nextRoundDate]);
			}

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			$this->pendingAlerts = [];
			throw $e;
		}

		$this->flushAlerts($tournament);

		return ['success' => true, 'completed' => $completed, 'advanced' => $advanced];
	}

	/**
	 * Returns the winning pet id. A pet whose owner was deleted since the draw has no
	 * row any more and loses by walkover; if neither pet exists nobody advances.
	 */
	protected function decideMatch(UserPetsTournamentMatch $match): int
	{
		$petA = $match->PetA;
		$petB = $match->PetB;

		if ($petA && $petB)
		{
			return (int) $this->pickWinner($petA, $petB)->pet_id;
		}

		if ($petA)
		{
			return (int) $petA->pet_id;
		}

		return $petB ? (int) $petB->pet_id : 0;
	}

	protected function pickWinner(UserPets $petA, UserPets $petB): UserPets
	{
		return WinnerPicker::pick($petA, $petB);
	}

	/**
	 * @param list<int> $petIds
	 *
	 * @return list<int> pet ids in seed order
	 */
	protected function drawSeedOrder(array $petIds): array
	{
		shuffle($petIds);
		return $petIds;
	}

	/**
	 * Runs inside the transaction that plays the last match: completes the tournament,
	 * counts the win on the champion's pet, pays the prize and schedules the repeat, so
	 * none of it happens without the others.
	 *
	 * @param array<string, mixed> $row the locked tournament row
	 */
	protected function finalizeTournament(UserPetsTournament $tournament, int $championPetId, array $row = []): void
	{
		$values = [
			'winner_pet_id' => $championPetId,
			'completed_at' => \XF::$time,
			'next_round_date' => 0,
		];

		$repo = $this->getTournamentRepo();
		$changed = $repo->changeStatusIf(
			$tournament->tournament_id,
			[UserPetsTournament::STATUS_IN_PROGRESS],
			UserPetsTournament::STATUS_COMPLETED,
			$values
		);
		if (!$changed)
		{
			return;
		}

		$this->syncEntity($tournament, ['status' => UserPetsTournament::STATUS_COMPLETED] + $values);

		/** @var UserPets|null $champion */
		$champion = $championPetId ? $this->em()->find('Sylphian\UserPets:UserPets', $championPetId) : null;
		if ($champion)
		{
			(new PetManager($champion))->updateStats();

			if ($repo->incrementTournamentWins($championPetId))
			{
				$champion->setAsSaved('tournament_wins', (int) $champion->tournament_wins + 1);
			}

			if ($tournament->prize_experience > 0)
			{
				$this->awardPrize($champion, (int) $tournament->prize_experience, (int) $tournament->tournament_id);
			}

			$this->queueAlert((int) $champion->user_id, TournamentHandler::ACTION_CHAMPION, [
				'prize' => (int) $tournament->prize_experience,
			]);
		}

		$this->createRepeatCopy($row ?: $tournament->toArray(false));
	}

	protected function awardPrize(UserPets $champion, int $experience, int $tournamentId): void
	{
		$this->getPetRepo()->awardExperienceToPet(
			$champion,
			$experience,
			false,
			XpSource::TOURNAMENT,
			TournamentHandler::CONTENT_TYPE,
			$tournamentId
		);
	}

	/**
	 * Schedules the next edition of a repeating tournament as a draft with the same
	 * settings and every date moved by repeat_days. If the moved dates would already be
	 * past, whole repeat periods are skipped until they lie ahead, so a late run does not
	 * create editions that open, start and cancel at once. Runs inside the transaction
	 * that ends the source tournament, at most once per source.
	 *
	 * @param array<string, mixed> $row the source tournament's columns
	 */
	protected function createRepeatCopy(array $row): ?UserPetsTournament
	{
		$sourceId = (int) ($row['tournament_id'] ?? 0);
		$repeatDays = (int) ($row['repeat_days'] ?? 0);
		if (!$sourceId || $repeatDays <= 0)
		{
			return null;
		}

		$repo = $this->getTournamentRepo();
		if ($repo->hasRepeatCopy($sourceId))
		{
			return null;
		}

		$period = $repeatDays * static::SECONDS_PER_DAY;
		$dates = [];
		foreach (UserPetsTournament::SCHEDULE_DATE_COLUMNS AS $column)
		{
			$dates[$column] = (int) ($row[$column] ?? 0);
		}

		$setDates = array_filter($dates);
		$periods = $setDates ? max(1, intdiv(\XF::$time - min($setDates), $period) + 1) : 1;
		foreach ($setDates AS $column => $date)
		{
			$dates[$column] = $date + $periods * $period;
		}

		/** @var UserPetsTournament $copy */
		$copy = $this->em()->create('Sylphian\UserPets:UserPetsTournament');
		$copy->bulkSet([
			'title' => (string) $row['title'],
			'description' => (string) ($row['description'] ?? ''),
			'max_participants' => (int) $row['max_participants'],
			'prize_experience' => (int) ($row['prize_experience'] ?? 0),
			'resolution_mode' => (string) $row['resolution_mode'],
			'round_interval_hours' => (int) ($row['round_interval_hours'] ?? 0),
			'repeat_days' => $repeatDays,
			'repeat_of_id' => $sourceId,
			'created_user_id' => (int) ($row['created_user_id'] ?? 0),
			'created_at' => \XF::$time,
			'status' => UserPetsTournament::STATUS_DRAFT,
		] + $dates);

		if (!$copy->preSave())
		{
			Logger::error('Could not schedule the next edition of a repeating pet tournament', [
				'tournament_id' => $sourceId,
				'errors' => array_map('strval', $copy->getErrors()),
			]);

			return null;
		}

		$copy->save(true, false);

		return $copy;
	}

	/**
	 * Alerts both owners of a played match. Byes are never passed here, and a pet whose
	 * owner was deleted has nobody to alert.
	 */
	protected function queueMatchAlerts(UserPetsTournamentMatch $match, int $winnerPetId, int $round): void
	{
		if (!$winnerPetId)
		{
			return;
		}

		$winner = (int) $match->pet_a_id === $winnerPetId ? $match->PetA : $match->PetB;
		$loser = (int) $match->pet_a_id === $winnerPetId ? $match->PetB : $match->PetA;

		if ($winner)
		{
			$this->queueAlert((int) $winner->user_id, TournamentHandler::ACTION_MATCH_WON, [
				'round' => $round,
				'opponent_user_id' => $loser ? (int) $loser->user_id : 0,
			]);
		}

		if ($loser)
		{
			$this->queueAlert((int) $loser->user_id, TournamentHandler::ACTION_MATCH_LOST, [
				'round' => $round,
				'opponent_user_id' => $winner ? (int) $winner->user_id : 0,
			]);
		}
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	protected function queueAlert(int $userId, string $action, array $extra = []): void
	{
		if ($userId > 0)
		{
			$this->pendingAlerts[] = ['user_id' => $userId, 'action' => $action, 'extra' => $extra];
		}
	}

	/**
	 * Sends the queued alerts. Owners whose pet is opted out or who may not have a pet get
	 * none; XenForo's own alert preferences decide the rest. A failure here is logged and
	 * does not undo the tournament change, which is already committed.
	 */
	protected function flushAlerts(UserPetsTournament $tournament): void
	{
		$pending = $this->pendingAlerts;
		$this->pendingAlerts = [];
		if (!$pending)
		{
			return;
		}

		try
		{
			$users = $this->loadAlertRecipients(array_values(array_unique(array_column($pending, 'user_id'))));

			/** @var UserAlertRepository $alertRepo */
			$alertRepo = $this->repository('XF:UserAlert');

			foreach ($pending AS $alert)
			{
				$user = $users[$alert['user_id']] ?? null;
				if (!$user || UserPetOptOut::isDisabledForUser($user))
				{
					continue;
				}

				$extra = $alert['extra'];
				if (isset($extra['opponent_user_id']))
				{
					// the opponent always has an alert of their own queued, so their user is loaded
					$opponent = $users[$extra['opponent_user_id']] ?? null;
					$extra['opponent_name'] = $opponent ? $opponent->username : '';
				}

				$alertRepo->alert(
					$user,
					0,
					'',
					TournamentHandler::CONTENT_TYPE,
					$tournament->tournament_id,
					$alert['action'],
					$extra,
					['dependsOnAddOnId' => 'Sylphian/UserPets']
				);
			}
		}
		catch (\Exception $e)
		{
			Logger::error('Could not send pet tournament alerts', [
				'tournament_id' => $tournament->tournament_id,
				'exception' => $e,
			]);
		}
	}

	/**
	 * @param list<int> $userIds
	 *
	 * @return array<int, User>
	 */
	protected function loadAlertRecipients(array $userIds): array
	{
		$users = [];
		foreach (array_chunk($userIds, 500) AS $chunk)
		{
			$found = $this->finder('XF:User')
				->with(['Option', 'UserPet', 'PermissionCombination'])
				->where('user_id', $chunk)
				->fetch();

			foreach ($found AS $user)
			{
				$users[(int) $user->user_id] = $user;
			}
		}

		return $users;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	protected function rowUsesRoundInterval(array $row): bool
	{
		return ($row['resolution_mode'] ?? '') === UserPetsTournament::MODE_AUTOMATIC
			&& (int) ($row['round_interval_hours'] ?? 0) > 0;
	}

	protected function isDue(int $date, int $dueBy): bool
	{
		return $date > 0 && $date <= $dueBy;
	}

	/**
	 * @return array{0: array<int, list<array>>, 1: array<int, array<int, UserPetsTournamentMatch>>}
	 */
	protected function loadBracket(UserPetsTournament $tournament): array
	{
		$rounds = [];
		$entities = [];

		foreach ($this->getTournamentRepo()->getMatchesForTournament($tournament->tournament_id) AS $match)
		{
			$round = (int) $match->round_number;
			$index = (int) $match->match_number - 1;

			$rounds[$round][$index] = [
				'pet_a_id' => (int) $match->pet_a_id,
				'pet_b_id' => (int) $match->pet_b_id,
				'winner_pet_id' => (int) $match->winner_pet_id,
				'loser_pet_id' => (int) $match->loser_pet_id,
				'status' => (string) $match->status,
			];
			$entities[$round][$index] = $match;
		}

		foreach ($rounds AS $round => $matches)
		{
			ksort($matches);
			$rounds[$round] = $matches;
		}
		ksort($rounds);

		return [$rounds, $entities];
	}

	/**
	 * @param array<int, list<array>> $rounds
	 * @param array<int, array<int, UserPetsTournamentMatch>> $entities
	 */
	protected function saveBracket(array $rounds, array $entities): void
	{
		foreach ($rounds AS $round => $matches)
		{
			foreach ($matches AS $index => $values)
			{
				$match = $entities[$round][$index] ?? null;
				if (!$match)
				{
					continue;
				}

				$wasResolved = in_array($match->status, [Bracket::STATUS_BYE, Bracket::STATUS_COMPLETED], true);

				$match->pet_a_id = $values['pet_a_id'];
				$match->pet_b_id = $values['pet_b_id'];
				$match->winner_pet_id = $values['winner_pet_id'];
				$match->loser_pet_id = $values['loser_pet_id'];
				$match->status = $values['status'];

				$isResolved = Bracket::isResolved($values);
				if ($isResolved && !$wasResolved)
				{
					$match->resolved_at = \XF::$time;
				}
				elseif (!$isResolved)
				{
					$match->resolved_at = 0;
				}

				if ($match->hasChanges())
				{
					$match->save(true, false);
				}
			}
		}
	}

	/**
	 * @param list<string> $fromStatuses
	 * @param array<string, int> $extraColumns
	 *
	 * @return array{success:bool, error?:Phrase}
	 */
	protected function changeStatus(
		UserPetsTournament $tournament,
		array $fromStatuses,
		string $toStatus,
		array $extraColumns = [],
		string $dueColumn = '',
		int $dueBy = 0
	): array
	{
		$changed = $this->getTournamentRepo()->changeStatusIf(
			$tournament->tournament_id,
			$fromStatuses,
			$toStatus,
			$extraColumns,
			$dueColumn,
			$dueBy
		);

		if (!$changed)
		{
			return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
		}

		$this->syncEntity($tournament, ['status' => $toStatus] + $extraColumns);

		return ['success' => true];
	}

	/**
	 * Mirrors a change written with a conditional update onto the loaded entity.
	 *
	 * @param array<string, int|string> $values
	 */
	protected function syncEntity(UserPetsTournament $tournament, array $values): void
	{
		foreach ($values AS $column => $value)
		{
			$tournament->setAsSaved($column, $value);
		}
	}

	/**
	 * @return array{success:false, error:Phrase}
	 */
	protected function failure(Phrase $error): array
	{
		return ['success' => false, 'error' => $error];
	}

	protected function getTournamentRepo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTournament');
		return $repo;
	}

	protected function getPetRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		return $repo;
	}
}

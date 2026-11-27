<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Duel\WinnerPicker;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Entity\UserPetsTournamentEntry;
use Sylphian\UserPets\Entity\UserPetsTournamentMatch;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Service\Tournament\Bracket;
use XF\Db\DuplicateKeyException;
use XF\Entity\User;
use XF\Phrase;
use XF\Service\AbstractService;

/**
 * Tournament lifecycle. Every state change locks the tournament row and checks the
 * stored status inside the transaction, so double submits and parallel requests
 * cannot start a tournament twice, regenerate its bracket or pay the prize twice.
 */
class UserPetTournament extends AbstractService
{
	/**
	 * More than enough for the largest bracket (512 entrants play 9 rounds).
	 */
	protected const MAX_AUTOMATIC_ROUNDS = 16;

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
	 * @return array{success:bool, error?:Phrase}
	 */
	public function openRegistration(UserPetsTournament $tournament): array
	{
		return $this->changeStatus(
			$tournament,
			[UserPetsTournament::STATUS_DRAFT, UserPetsTournament::STATUS_REGISTRATION_CLOSED],
			UserPetsTournament::STATUS_REGISTRATION_OPEN,
			['registration_opened_at' => \XF::$time]
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
	public function cancelTournament(UserPetsTournament $tournament): array
	{
		return $this->changeStatus(
			$tournament,
			UserPetsTournament::getCancellableStatuses(),
			UserPetsTournament::STATUS_CANCELLED
		);
	}

	/**
	 * Draws the seeds, writes the bracket and moves the tournament to in progress.
	 * Only a tournament with open or closed registration and at least two entries can
	 * start; a second submit finds it in progress and changes nothing.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	public function startTournament(UserPetsTournament $tournament): array
	{
		$repo = $this->getTournamentRepo();
		$db = $this->db();
		$db->beginTransaction();

		try
		{
			$row = $repo->lockTournament($tournament->tournament_id);
			if (!$row || !in_array($row['status'], UserPetsTournament::getStartableStatuses(), true))
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			$petIds = [];
			foreach ($repo->getEntriesForTournament($tournament->tournament_id) AS $entry)
			{
				$petIds[] = (int) $entry->pet_id;
			}
			$petIds = array_values(array_unique(array_filter($petIds)));

			if (count($petIds) < UserPetsTournament::MIN_PARTICIPANTS)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_not_enough_entries'));
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

			$extra = [
				'started_at' => \XF::$time,
				'registration_closed_at' => (int) $row['registration_closed_at'] ?: \XF::$time,
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

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			throw $e;
		}

		$this->syncEntity($tournament, ['status' => UserPetsTournament::STATUS_IN_PROGRESS] + $extra);

		if ($tournament->resolution_mode === UserPetsTournament::MODE_AUTOMATIC)
		{
			return $this->advanceTournament($tournament);
		}

		return ['success' => true, 'completed' => false, 'advanced' => false];
	}

	/**
	 * Plays the lowest round that has ready matches; in automatic mode keeps going
	 * until the tournament is complete.
	 *
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	public function advanceTournament(UserPetsTournament $tournament): array
	{
		$roundLimit = $tournament->resolution_mode === UserPetsTournament::MODE_AUTOMATIC
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
	 * @return array{success:bool, error?:Phrase, completed?:bool, advanced?:bool}
	 */
	protected function advanceOneRound(UserPetsTournament $tournament): array
	{
		$repo = $this->getTournamentRepo();
		$db = $this->db();
		$db->beginTransaction();

		try
		{
			$row = $repo->lockTournament($tournament->tournament_id);
			if (!$row || $row['status'] !== UserPetsTournament::STATUS_IN_PROGRESS)
			{
				$db->rollback();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_not_in_progress'));
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
					Bracket::applyResult($rounds, $round, $index, $this->decideMatch($match));
				}

				Bracket::resolve($rounds);
				$advanced = true;
			}

			$this->saveBracket($rounds, $matchEntities);

			$completed = false;
			if (Bracket::isComplete($rounds))
			{
				$this->finalizeTournament($tournament, Bracket::getChampion($rounds));
				$completed = true;
			}
			elseif (!$advanced)
			{
				$db->commit();
				return $this->failure(\XF::phrase('sylphian_userpets_tournament_no_ready_matches'));
			}

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			throw $e;
		}

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

	protected function finalizeTournament(UserPetsTournament $tournament, int $championPetId): void
	{
		$values = [
			'winner_pet_id' => $championPetId,
			'completed_at' => \XF::$time,
		];

		$changed = $this->getTournamentRepo()->changeStatusIf(
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

		if ($championPetId && $tournament->prize_experience > 0)
		{
			/** @var UserPets|null $champion */
			$champion = $this->em()->find('Sylphian\UserPets:UserPets', $championPetId);
			if ($champion)
			{
				$this->awardPrize($champion, (int) $tournament->prize_experience);
			}
		}
	}

	protected function awardPrize(UserPets $champion, int $experience): void
	{
		$this->getPetRepo()->awardPetExperience((int) $champion->user_id, $experience, false);
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
	protected function changeStatus(UserPetsTournament $tournament, array $fromStatuses, string $toStatus, array $extraColumns = []): array
	{
		$changed = $this->getTournamentRepo()->changeStatusIf(
			$tournament->tournament_id,
			$fromStatuses,
			$toStatus,
			$extraColumns
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

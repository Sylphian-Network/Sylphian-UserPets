<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Entity\UserPetsTournamentEntry;
use Sylphian\UserPets\Entity\UserPetsTournamentMatch;
use Sylphian\UserPets\Service\Tournament\Bracket;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class UserPetsTournamentRepository extends Repository
{
	public function findTournamentById(int $tournamentId): ?UserPetsTournament
	{
		/** @var UserPetsTournament|null $tournament */
		$tournament = $this->em->find('Sylphian\UserPets:UserPetsTournament', $tournamentId);
		return $tournament;
	}

	public function getAdminTournamentFinder(): Finder
	{
		return $this->finder('Sylphian\UserPets:UserPetsTournament')
			->with('WinnerPet.User')
			->order('created_at', 'DESC');
	}

	public function getPublicTournamentFinder(array $statuses = []): Finder
	{
		$finder = $this->finder('Sylphian\UserPets:UserPetsTournament')
			->with('WinnerPet.User')
			->order('created_at', 'DESC');

		if ($statuses)
		{
			$finder->where('status', $statuses);
		}

		return $finder;
	}

	/**
	 * @return ArrayCollection<int, UserPetsTournamentEntry>
	 */
	public function getEntriesForTournament(int $tournamentId): ArrayCollection
	{
		return $this->finder('Sylphian\UserPets:UserPetsTournamentEntry')
			->with(['User', 'Pet.User'])
			->where('tournament_id', $tournamentId)
			->order('seed')
			->order('joined_at')
			->fetch();
	}

	/**
	 * @return ArrayCollection<int, UserPetsTournamentMatch>
	 */
	public function getMatchesForTournament(int $tournamentId): ArrayCollection
	{
		return $this->finder('Sylphian\UserPets:UserPetsTournamentMatch')
			->with('PetA.User')
			->with('PetB.User')
			->with('WinnerPet.User')
			->with('LoserPet.User')
			->where('tournament_id', $tournamentId)
			->order('round_number')
			->order('match_number')
			->fetch();
	}

	public function getEntryCount(int $tournamentId): int
	{
		return $this->finder('Sylphian\UserPets:UserPetsTournamentEntry')
			->where('tournament_id', $tournamentId)
			->total();
	}

	public function isPetRegistered(int $tournamentId, int $petId): bool
	{
		return $this->finder('Sylphian\UserPets:UserPetsTournamentEntry')
			->where('tournament_id', $tournamentId)
			->where('pet_id', $petId)
			->total() > 0;
	}

	public function isUserRegistered(int $tournamentId, int $userId): bool
	{
		if (!$userId)
		{
			return false;
		}

		return $this->finder('Sylphian\UserPets:UserPetsTournamentEntry')
			->where('tournament_id', $tournamentId)
			->where('user_id', $userId)
			->total() > 0;
	}

	public function getEntryForPet(int $tournamentId, int $petId): ?UserPetsTournamentEntry
	{
		/** @var UserPetsTournamentEntry|null $entry */
		$entry = $this->finder('Sylphian\UserPets:UserPetsTournamentEntry')
			->where('tournament_id', $tournamentId)
			->where('pet_id', $petId)
			->fetchOne();

		return $entry;
	}

	public function getGroupedBracketData(int $tournamentId): array
	{
		$grouped = [];
		foreach ($this->getMatchesForTournament($tournamentId) AS $match)
		{
			$grouped[$match->round_number][] = $match;
		}

		ksort($grouped);
		return $grouped;
	}

	public function getDisplayBracketData(UserPetsTournament $tournament): array
	{
		$grouped = $this->getGroupedBracketData($tournament->tournament_id);
		if (!$grouped)
		{
			return [];
		}

		$totalRounds = max(array_keys($grouped));
		$display = [];

		foreach ($grouped AS $roundNumber => $matches)
		{
			$display[] = [
				'round_number' => (int) $roundNumber,
				'label' => $this->getRoundLabel((int) $roundNumber, (int) $totalRounds),
				'matches' => $matches
			];
		}

		return $display;
	}

	protected function getRoundLabel(int $roundNumber, int $totalRounds): string
	{
		$distanceFromFinal = $totalRounds - $roundNumber;

		switch ($distanceFromFinal)
		{
			case 0:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_final');

			case 1:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_semifinal');

			case 2:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_quarterfinal');

			case 3:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_16');

			case 4:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_32');

			case 5:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_64');

			case 6:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_128');

			case 7:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_256');

			case 8:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_of_512');

			default:
				return (string) \XF::phrase('sylphian_userpets_tournament_round_x', ['round' => $roundNumber]);
		}
	}

	public function getNextSeed(int $tournamentId): int
	{
		$seed = $this->db()->fetchOne(
			'SELECT MAX(seed) FROM xf_user_pets_tournament_entries WHERE tournament_id = ?',
			$tournamentId
		);

		return ((int) $seed) + 1;
	}

	/**
	 * Locks the tournament row until the surrounding transaction ends, so joins,
	 * starts and round advances on the same tournament run one after another.
	 */
	public function lockTournament(int $tournamentId): ?array
	{
		if (!$this->db()->inTransaction())
		{
			throw new \LogicException('The tournament row can only be locked inside a transaction.');
		}

		$row = $this->db()->fetchRow(
			'SELECT * FROM xf_user_pets_tournaments WHERE tournament_id = ? FOR UPDATE',
			$tournamentId
		);

		return $row ?: null;
	}

	/**
	 * Moves the tournament to $toStatus only while it is still in one of $fromStatuses.
	 *
	 * @param list<string> $fromStatuses
	 * @param array<string, int|string> $extraColumns
	 *
	 * @return bool whether this call made the change
	 */
	public function changeStatusIf(int $tournamentId, array $fromStatuses, string $toStatus, array $extraColumns = []): bool
	{
		if (!$fromStatuses)
		{
			return false;
		}

		$db = $this->db();
		$affected = $db->update(
			'xf_user_pets_tournaments',
			['status' => $toStatus] + $extraColumns,
			'tournament_id = ? AND status IN (' . $db->quote(array_values($fromStatuses)) . ')',
			$tournamentId
		);

		return $affected > 0;
	}

	/**
	 * @param array<int, list<array{pet_a_id:int, pet_b_id:int, winner_pet_id:int, loser_pet_id:int, status:string}>> $rounds
	 */
	public function insertBracket(int $tournamentId, array $rounds, int $resolvedAt): void
	{
		$rows = [];
		foreach ($rounds AS $round => $matches)
		{
			foreach ($matches AS $index => $match)
			{
				$resolved = Bracket::isResolved($match);
				$rows[] = [
					'tournament_id' => $tournamentId,
					'round_number' => (int) $round,
					'match_number' => (int) $index + 1,
					'pet_a_id' => (int) $match['pet_a_id'],
					'pet_b_id' => (int) $match['pet_b_id'],
					'winner_pet_id' => (int) $match['winner_pet_id'],
					'loser_pet_id' => (int) $match['loser_pet_id'],
					'status' => (string) $match['status'],
					'resolved_at' => $resolved ? $resolvedAt : 0,
				];
			}
		}

		foreach (array_chunk($rows, 200) AS $chunk)
		{
			$this->db()->insertBulk('xf_user_pets_tournament_matches', $chunk);
		}
	}

	/**
	 * @param array<int, int> $seedByPetId
	 */
	public function updateEntrySeeds(int $tournamentId, array $seedByPetId): void
	{
		if (!$seedByPetId)
		{
			return;
		}

		$db = $this->db();
		foreach (array_chunk($seedByPetId, 200, true) AS $chunk)
		{
			$cases = [];
			foreach ($chunk AS $petId => $seed)
			{
				$cases[] = 'WHEN ' . $db->quote((int) $petId) . ' THEN ' . $db->quote((int) $seed);
			}

			$db->query(
				'UPDATE xf_user_pets_tournament_entries
				SET seed = CASE pet_id ' . implode(' ', $cases) . ' ELSE seed END
				WHERE tournament_id = ?
					AND pet_id IN (' . $db->quote(array_map('intval', array_keys($chunk))) . ')',
				$tournamentId
			);
		}
	}

	public function clearMatchesForTournament(int $tournamentId): void
	{
		$this->db()->delete('xf_user_pets_tournament_matches', 'tournament_id = ?', $tournamentId);
	}

	/**
	 * Removes a user's entries from tournaments that have not started yet, freeing
	 * their places. Entries of started tournaments stay with the bracket; a pet that
	 * no longer exists loses its next match by walkover.
	 */
	public function removeUserFromPendingTournaments(int $userId): int
	{
		if (!$userId)
		{
			return 0;
		}

		return (int) $this->db()->query(
			'DELETE entry
			FROM xf_user_pets_tournament_entries AS entry
			INNER JOIN xf_user_pets_tournaments AS tournament ON (tournament.tournament_id = entry.tournament_id)
			WHERE entry.user_id = ?
				AND tournament.status IN (?, ?, ?)',
			[
				$userId,
				UserPetsTournament::STATUS_DRAFT,
				UserPetsTournament::STATUS_REGISTRATION_OPEN,
				UserPetsTournament::STATUS_REGISTRATION_CLOSED,
			]
		)->rowsAffected();
	}

	/**
	 * Completed tournaments whose champion is still a pet, with the prize each was
	 * meant to receive. Before 1.5.0 every prize paid the 10 XP default instead.
	 *
	 * @return list<array{tournament_id:int, pet_id:int, user_id:int, prize_experience:int, completed_at:int}>
	 */
	public function getPaidPrizeRows(): array
	{
		$rows = $this->db()->fetchAll(
			'SELECT tournament.tournament_id, pet.pet_id, pet.user_id, tournament.prize_experience, tournament.completed_at
			FROM xf_user_pets_tournaments AS tournament
			INNER JOIN xf_user_pets AS pet ON (pet.pet_id = tournament.winner_pet_id)
			WHERE tournament.status = ?
				AND tournament.prize_experience > 0
			ORDER BY tournament.tournament_id',
			UserPetsTournament::STATUS_COMPLETED
		);

		return array_map(static function (array $row): array
		{
			return [
				'tournament_id' => (int) $row['tournament_id'],
				'pet_id' => (int) $row['pet_id'],
				'user_id' => (int) $row['user_id'],
				'prize_experience' => (int) $row['prize_experience'],
				'completed_at' => (int) $row['completed_at'],
			];
		}, $rows);
	}
}

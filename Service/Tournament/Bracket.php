<?php

namespace Sylphian\UserPets\Service\Tournament;

/**
 * Single-elimination bracket arithmetic on plain arrays, so the rules can be
 * checked without a database.
 *
 * A bracket is array<int round, list<match>>; round 1 is first, matches are
 * listed in match_number order and match i of round r is fed by matches 2i and
 * 2i + 1 of round r - 1. A match is
 * array{pet_a_id:int, pet_b_id:int, winner_pet_id:int, loser_pet_id:int, status:string}.
 */
class Bracket
{
	public const STATUS_WAITING = 'waiting';
	public const STATUS_READY = 'ready';
	public const STATUS_BYE = 'bye';
	public const STATUS_COMPLETED = 'completed';

	public static function getBracketSize(int $entrantCount): int
	{
		$size = 2;
		while ($size < $entrantCount)
		{
			$size *= 2;
		}

		return $size;
	}

	public static function getRoundCount(int $bracketSize): int
	{
		$rounds = 1;
		while ((2 ** $rounds) < $bracketSize)
		{
			$rounds++;
		}

		return $rounds;
	}

	/**
	 * Seed numbers in slot order (1, 8, 4, 5, 2, 7, 3, 6 for eight slots). Seed s
	 * meets seed size + 1 - s in round 1, so the empty seeds above the entrant count
	 * all face real entrants and the byes go to the top seeds.
	 *
	 * @return list<int>
	 */
	public static function getSeedSlots(int $bracketSize): array
	{
		$slots = [1];
		while (count($slots) < $bracketSize)
		{
			$opposite = count($slots) * 2 + 1;
			$next = [];
			foreach ($slots AS $seed)
			{
				$next[] = $seed;
				$next[] = $opposite - $seed;
			}
			$slots = $next;
		}

		return $slots;
	}

	/**
	 * @param list<int> $petIdsBySeed pet ids, first seed first
	 *
	 * @return array<int, list<array{pet_a_id:int, pet_b_id:int, winner_pet_id:int, loser_pet_id:int, status:string}>>
	 */
	public static function build(array $petIdsBySeed): array
	{
		$petIds = [];
		foreach ($petIdsBySeed AS $petId)
		{
			$petId = (int) $petId;
			if ($petId > 0 && !in_array($petId, $petIds, true))
			{
				$petIds[] = $petId;
			}
		}

		if (count($petIds) < 2)
		{
			throw new \InvalidArgumentException('A bracket needs at least two different pets.');
		}

		$size = self::getBracketSize(count($petIds));
		$slots = self::getSeedSlots($size);

		$rounds = [];
		for ($slot = 0; $slot < $size; $slot += 2)
		{
			$rounds[1][] = self::newMatch(
				$petIds[$slots[$slot] - 1] ?? 0,
				$petIds[$slots[$slot + 1] - 1] ?? 0
			);
		}

		$roundCount = self::getRoundCount($size);
		$matchCount = intdiv($size, 4);
		for ($round = 2; $round <= $roundCount; $round++)
		{
			for ($i = 0; $i < $matchCount; $i++)
			{
				$rounds[$round][] = self::newMatch(0, 0);
			}
			$matchCount = intdiv($matchCount, 2);
		}

		self::resolve($rounds);

		return $rounds;
	}

	/**
	 * Carries winners into later rounds and settles every match whose feeders are
	 * resolved: two pets make it ready, one pet advances on a bye and an empty pair is
	 * a bye without a winner. Completed matches and byes are never reopened.
	 *
	 * @return list<array{0:int, 1:int}> round and index of every match that changed
	 */
	public static function resolve(array &$rounds): array
	{
		ksort($rounds);
		$changed = [];

		foreach (array_keys($rounds) AS $round)
		{
			foreach (array_keys($rounds[$round]) AS $index)
			{
				$match = $rounds[$round][$index];
				$before = $match;

				if (!self::isResolved($match))
				{
					$feedersResolved = true;
					if (isset($rounds[$round - 1]))
					{
						$sourceA = $rounds[$round - 1][$index * 2] ?? null;
						$sourceB = $rounds[$round - 1][$index * 2 + 1] ?? null;
						$resolvedA = $sourceA === null || self::isResolved($sourceA);
						$resolvedB = $sourceB === null || self::isResolved($sourceB);

						$match['pet_a_id'] = ($sourceA && $resolvedA) ? (int) $sourceA['winner_pet_id'] : 0;
						$match['pet_b_id'] = ($sourceB && $resolvedB) ? (int) $sourceB['winner_pet_id'] : 0;
						$feedersResolved = $resolvedA && $resolvedB;
					}

					$match = $feedersResolved ? self::settle($match) : self::wait($match);
				}

				if ($match != $before)
				{
					$rounds[$round][$index] = $match;
					$changed[] = [$round, $index];
				}
			}
		}

		return $changed;
	}

	/**
	 * Records the duel result of a ready match. A winner of 0 means neither pet could
	 * play (both were deleted), so nobody advances from this match.
	 */
	public static function applyResult(array &$rounds, int $round, int $index, int $winnerPetId): void
	{
		$match = $rounds[$round][$index] ?? null;
		if (!$match || $match['status'] !== self::STATUS_READY)
		{
			throw new \LogicException("Match $index of round $round is not ready to be decided.");
		}

		$petA = (int) $match['pet_a_id'];
		$petB = (int) $match['pet_b_id'];
		if ($winnerPetId !== 0 && $winnerPetId !== $petA && $winnerPetId !== $petB)
		{
			throw new \InvalidArgumentException("Pet $winnerPetId is not playing match $index of round $round.");
		}

		$match['winner_pet_id'] = $winnerPetId;
		$match['loser_pet_id'] = $winnerPetId === 0 ? 0 : ($winnerPetId === $petA ? $petB : $petA);
		$match['status'] = self::STATUS_COMPLETED;

		$rounds[$round][$index] = $match;
	}

	public static function getNextPlayableRound(array $rounds): ?int
	{
		ksort($rounds);
		foreach ($rounds AS $round => $matches)
		{
			foreach ($matches AS $match)
			{
				if ($match['status'] === self::STATUS_READY)
				{
					return (int) $round;
				}
			}
		}

		return null;
	}

	/**
	 * @return list<int>
	 */
	public static function getReadyMatchIndexes(array $rounds, int $round): array
	{
		$indexes = [];
		foreach ($rounds[$round] ?? [] AS $index => $match)
		{
			if ($match['status'] === self::STATUS_READY)
			{
				$indexes[] = (int) $index;
			}
		}

		return $indexes;
	}

	public static function isComplete(array $rounds): bool
	{
		if (!$rounds)
		{
			return false;
		}

		$final = $rounds[max(array_keys($rounds))][0] ?? null;

		return $final !== null && self::isResolved($final);
	}

	public static function getChampion(array $rounds): int
	{
		if (!self::isComplete($rounds))
		{
			return 0;
		}

		return (int) $rounds[max(array_keys($rounds))][0]['winner_pet_id'];
	}

	public static function isResolved(array $match): bool
	{
		return $match['status'] === self::STATUS_COMPLETED || $match['status'] === self::STATUS_BYE;
	}

	protected static function settle(array $match): array
	{
		$petA = (int) $match['pet_a_id'];
		$petB = (int) $match['pet_b_id'];

		if ($petA && $petB)
		{
			$match['status'] = self::STATUS_READY;
			$match['winner_pet_id'] = 0;
		}
		else
		{
			$match['status'] = self::STATUS_BYE;
			$match['winner_pet_id'] = $petA ?: $petB;
		}

		$match['loser_pet_id'] = 0;

		return $match;
	}

	protected static function wait(array $match): array
	{
		$match['status'] = self::STATUS_WAITING;
		$match['winner_pet_id'] = 0;
		$match['loser_pet_id'] = 0;

		return $match;
	}

	protected static function newMatch(int $petA, int $petB): array
	{
		return [
			'pet_a_id' => $petA,
			'pet_b_id' => $petB,
			'winner_pet_id' => 0,
			'loser_pet_id' => 0,
			'status' => self::STATUS_WAITING,
		];
	}
}

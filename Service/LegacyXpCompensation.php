<?php

namespace Sylphian\UserPets\Service;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Tutorial\TutorialRegistry;
use XF\Service\AbstractService;

/**
 * One-off correction for pets that earned XP before 1.5.0.
 *
 * Up to 1.4.8 every award that went through UserPetsRepository::awardPetExperience()
 * added a flat 10 XP whatever the configured amount, while the thread and post tracking
 * columns recorded the configured amount. Content counted by a recount (pet creation,
 * widget view, rebuild) was always credited correctly, and recounts keep the gap that
 * the flat awards left. What is missing per category, using today's options:
 *
 * - threads / replies: (configured XP - 10) for each visible, filter-matching thread or reply
 *   the owner posted after the pet existed (those were the flat awards);
 * - accepted solutions: nothing (only ever credited by recounts);
 * - tutorials: (configured XP - 10) for each tutorial completed while the pet existed and the
 *   full configured XP for each one completed before the pet existed (the reward job found
 *   no pet and paid nothing);
 * - duel wins: (configured win XP - 10) per completed duel the pet won;
 * - tournament prizes: (prize - 10) per completed tournament the pet won.
 *
 * Only awards inside the window (notBefore, notAfter] are considered. notAfter must be the
 * moment the site moved to 1.5.0: later awards were already paid correctly.
 * Pet actions are not covered (they are not logged).
 */
class LegacyXpCompensation extends AbstractService
{
	public const LEGACY_AWARD = 10;

	public const CATEGORIES = ['thread', 'post', 'tutorial', 'duel', 'tournament'];

	/**
	 * @var array<int,list<array{prize_experience:int,completed_at:int}>>|null
	 */
	protected ?array $prizeRowsByPet = null;

	/**
	 * @param int $notBefore Awards at or before this time are left alone (0 = no lower bound)
	 * @param int $notAfter Awards after this time are left alone (0 = no upper bound)
	 * @return array{thread:int,post:int,tutorial:int,duel:int,tournament:int,total:int}
	 */
	public function calculate(UserPets $pet, int $notBefore = 0, int $notAfter = 0): array
	{
		$repo = $this->getPetsRepo();
		$userId = (int) $pet->user_id;
		$contentAfter = max((int) $pet->created_at, $notBefore);

		$result = [
			'thread' => $this->getContentShortfall($repo, UserPetsRepository::CONTENT_THREAD, $userId, $contentAfter, $notAfter),
			'post' => $this->getContentShortfall($repo, UserPetsRepository::CONTENT_POST, $userId, $contentAfter, $notAfter),
			'tutorial' => $this->getTutorialShortfall($userId, (int) $pet->created_at, $notBefore, $notAfter),
			'duel' => $this->getDuelShortfall((int) $pet->pet_id, $notBefore, $notAfter),
			'tournament' => $this->getTournamentShortfall((int) $pet->pet_id, $notBefore, $notAfter),
		];
		$result['total'] = array_sum($result);

		return $result;
	}

	/**
	 * Adds the shortfall to the pet and recalculates its level without sending alerts.
	 *
	 * @param bool $allowDecrease Also remove XP where the flat 10 was more than configured
	 * @return int The experience actually added (negative only with $allowDecrease)
	 */
	public function apply(UserPets $pet, int $notBefore = 0, bool $allowDecrease = false, int $notAfter = 0): int
	{
		$shortfall = $this->calculate($pet, $notBefore, $notAfter);

		$amount = 0;
		foreach (self::CATEGORIES AS $category)
		{
			$amount += $allowDecrease ? $shortfall[$category] : max(0, $shortfall[$category]);
		}

		if ($amount === 0)
		{
			return 0;
		}

		$before = (int) $pet->experience;
		(new PetLeveling())->addExperience($pet, $amount);

		try
		{
			$pet->save();
		}
		catch (\Exception $e)
		{
			Logger::error('Could not apply legacy pet XP compensation: ' . $e->getMessage(), [
				'pet_id' => $pet->pet_id,
				'user_id' => $pet->user_id,
				'amount' => $amount,
			]);

			return 0;
		}

		return (int) $pet->experience - $before;
	}

	protected function getContentShortfall(UserPetsRepository $repo, string $contentType, int $userId, int $after, int $until): int
	{
		$configured = $repo->getContentExperiencePerUnit($contentType);
		if ($configured <= 0)
		{
			return 0;
		}

		$flatAwards = $repo->getContentCountForUser($contentType, $userId, $after, $until);

		return $flatAwards * ($configured - self::LEGACY_AWARD);
	}

	protected function getTutorialShortfall(int $userId, int $petCreatedAt, int $notBefore, int $notAfter): int
	{
		$finder = $this->finder('Sylphian\UserPets:UserPetTutorial')
			->where('user_id', $userId)
			->where('completed', 1);

		if ($notBefore > 0)
		{
			$finder->where('completed_date', '>', $notBefore);
		}
		if ($notAfter > 0)
		{
			$finder->where('completed_date', '<=', $notAfter);
		}

		$shortfall = 0;
		foreach ($finder->fetchColumns(['tutorial_key', 'completed_date']) AS $row)
		{
			$tutorial = TutorialRegistry::get((string) $row['tutorial_key']);
			$configured = $tutorial ? $tutorial->getRewardAmount() : 0;
			if ($configured <= 0)
			{
				continue;
			}

			$paid = ((int) $row['completed_date'] >= $petCreatedAt) ? self::LEGACY_AWARD : 0;
			$shortfall += $configured - $paid;
		}

		return $shortfall;
	}

	/**
	 * Read-only count on the duel entity: UserPetsDuelRepository::countDuelWinsForPets()
	 * has no date bounds, and wins after the upgrade must not be counted.
	 */
	protected function getDuelShortfall(int $petId, int $notBefore, int $notAfter): int
	{
		$perWin = (int) \XF::options()->sylphian_userpets_duel_win_exp - self::LEGACY_AWARD;
		if ($perWin <= 0 || $petId <= 0)
		{
			return 0;
		}

		$finder = $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('winner_pet_id', $petId)
			->where('status', 'completed');

		if ($notBefore > 0)
		{
			$finder->where('completed_at', '>', $notBefore);
		}
		if ($notAfter > 0)
		{
			$finder->where('completed_at', '<=', $notAfter);
		}

		return $finder->total() * $perWin;
	}

	protected function getTournamentShortfall(int $petId, int $notBefore, int $notAfter): int
	{
		$shortfall = 0;
		foreach ($this->getPrizeRowsByPet()[$petId] ?? [] AS $row)
		{
			if ($notBefore > 0 && $row['completed_at'] <= $notBefore)
			{
				continue;
			}
			if ($notAfter > 0 && $row['completed_at'] > $notAfter)
			{
				continue;
			}

			$shortfall += max(0, $row['prize_experience'] - self::LEGACY_AWARD);
		}

		return $shortfall;
	}

	/**
	 * @return array<int,list<array{prize_experience:int,completed_at:int}>>
	 */
	protected function getPrizeRowsByPet(): array
	{
		if ($this->prizeRowsByPet !== null)
		{
			return $this->prizeRowsByPet;
		}

		/** @var UserPetsTournamentRepository $tournamentRepo */
		$tournamentRepo = $this->repository('Sylphian\UserPets:UserPetsTournament');

		$grouped = [];
		foreach ($tournamentRepo->getPaidPrizeRows() AS $row)
		{
			$grouped[(int) $row['pet_id']][] = [
				'prize_experience' => (int) $row['prize_experience'],
				'completed_at' => (int) $row['completed_at'],
			];
		}

		$this->prizeRowsByPet = $grouped;

		return $grouped;
	}

	protected function getPetsRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');

		return $repo;
	}
}

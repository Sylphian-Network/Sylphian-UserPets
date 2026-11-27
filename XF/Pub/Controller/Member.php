<?php

namespace Sylphian\UserPets\XF\Pub\Controller;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\PetState;
use Sylphian\UserPets\Listener\PetPreload;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsXpLogRepository;
use Sylphian\UserPets\Service\PetLeveling;
use Sylphian\UserPets\Service\PetManager;
use XF\Entity\User;
use XF\Mvc\ParameterBag;
use XF\Phrase;

/**
 * The "Pet" tab of member profiles (members/pet), loaded like the core lazy tabs.
 */
class Member extends XFCP_Member
{
	public const PET_XP_LOG_PER_PAGE = 20;

	public const PET_RECENT_DUELS = 10;

	public const PET_TOURNAMENTS_WON = 10;

	public function actionPet(ParameterBag $params)
	{
		$this->assertNotEmbeddedImageRequest();

		$user = $this->assertViewableUser($params->user_id);

		$visitor = \XF::visitor();
		if (!PetPreload::canViewPets($visitor))
		{
			return $this->noPermission();
		}

		$pet = PetPreload::getProfilePet($user, $visitor);
		if (!$pet)
		{
			return $this->notFound(\XF::phrase('sylphian_userpets_member_has_no_pet'));
		}

		$xpPage = $this->filterPage(0, 'xp_page');

		/** @var UserPetsXpLogRepository $xpLogRepo */
		$xpLogRepo = $this->repository(UserPetsXpLogRepository::class);
		$xpLogFinder = $xpLogRepo->findLogsForPet((int) $pet->pet_id)->limitByPage($xpPage, self::PET_XP_LOG_PER_PAGE);
		$xpLogTotal = $xpLogFinder->total();

		$viewParams = [
			'user' => $user,
			'pet' => $pet,
			'xpLogs' => $xpLogFinder->fetch(),
			'xpLogTotal' => $xpLogTotal,
			'xpPage' => $xpPage,
			'xpHasMore' => $xpPage * self::PET_XP_LOG_PER_PAGE < $xpLogTotal,
			// later pages only append XP rows to the open tab
			'xpOnly' => $xpPage > 1,
		];

		if ($xpPage === 1)
		{
			$viewParams += $this->getPetProfileViewParams($user, $pet);
		}

		return $this->view('Sylphian\UserPets:Member\Pet', 'sylphian_userpets_member_pet', $viewParams);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getPetProfileViewParams(User $user, UserPets $pet): array
	{
		// rendering never saves: the decay is applied in memory only
		(new PetManager($pet))->updateStats(false);

		$leveling = new PetLeveling();

		/** @var UserPetsRepository $petRepo */
		$petRepo = $this->repository(UserPetsRepository::class);

		$options = $this->options();

		return [
			'petRender' => $petRepo->getRenderConfigForPet($pet),
			'ownerPetTitle' => DisplayName::renderUsernamePhrase('sylphian_userpets_widget_title', $user->username),
			'stateDisplay' => PetState::getDisplay($pet->state),
			'levelProgress' => $leveling->getLevelProgressPercentage($pet),
			'expNeeded' => $leveling->getExperienceNeededToLevelUp($pet),
			'petAge' => $this->getPetAgePhrase((int) $pet->created_at),
			'recentDuels' => $this->getRecentPetDuels($pet),
			'tournamentsWon' => $this->finder('Sylphian\UserPets:UserPetsTournament')
				->where('winner_pet_id', $pet->pet_id)
				->where('status', 'completed')
				->order('completed_at', 'DESC')
				->fetch(self::PET_TOURNAMENTS_WON),
			'scaleMin' => $options->sylphian_userpets_scale_min ?? 0.5,
			'scaleMax' => $options->sylphian_userpets_scale_max ?? 1.0,
		];
	}

	/**
	 * The latest completed duels of the pet, newest first, each with the other pet and the result.
	 *
	 * @return list<array{duel: \Sylphian\UserPets\Entity\UserPetsDuel, opponent: ?UserPets, won: bool}>
	 */
	protected function getRecentPetDuels(UserPets $pet): array
	{
		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $this->repository(UserPetsDuelRepository::class);
		$duelIds = $duelRepo->getDuelIdsForPet((int) $pet->pet_id, ['completed'], self::PET_RECENT_DUELS);
		if (!$duelIds)
		{
			return [];
		}

		$duels = $this->finder('Sylphian\UserPets:UserPetsDuel')
			->where('duel_id', $duelIds)
			->with(['ChallengerPet.User', 'OpponentPet.User'])
			->fetch();

		$rows = [];
		foreach ($duelIds AS $duelId)
		{
			$duel = $duels[$duelId] ?? null;
			if (!$duel)
			{
				continue;
			}

			$isChallenger = (int) $duel->challenger_pet_id === (int) $pet->pet_id;
			$rows[] = [
				'duel' => $duel,
				'opponent' => $isChallenger ? $duel->OpponentPet : $duel->ChallengerPet,
				'won' => (int) $duel->winner_pet_id === (int) $pet->pet_id,
			];
		}

		return $rows;
	}

	protected function getPetAgePhrase(int $createdAt): Phrase
	{
		$days = intdiv(max(0, \XF::$time - $createdAt), 86400);

		if ($days >= 730)
		{
			return \XF::phrase('x_years', ['years' => intdiv($days, 365)]);
		}
		if ($days >= 60)
		{
			return \XF::phrase('x_months', ['months' => intdiv($days, 30)]);
		}
		if ($days >= 14)
		{
			return \XF::phrase('x_weeks', ['weeks' => intdiv($days, 7)]);
		}

		return \XF::phrase('x_days', ['days' => $days]);
	}
}

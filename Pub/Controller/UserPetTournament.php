<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

class UserPetTournament extends AbstractController
{
	protected function repo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTournament');
		return $repo;
	}

	public function actionIndex(): View
	{
		return $this->view(
			'Sylphian\UserPets:Tournament\Index',
			'sylphian_userpets_tournament_list',
			[
				'activeTournaments' => $this->repo()->getPublicTournamentFinder([
					UserPetsTournament::STATUS_REGISTRATION_OPEN,
					UserPetsTournament::STATUS_REGISTRATION_CLOSED,
					UserPetsTournament::STATUS_IN_PROGRESS,
				])->fetch(),
				'completedTournaments' => $this->repo()->getPublicTournamentFinder([
					UserPetsTournament::STATUS_COMPLETED,
				])->fetch(),
			]
		);
	}

	public function actionView(ParameterBag $params): View|Error
	{
		$tournament = $this->assertViewableTournament($this->getRequestedTournamentId($params));

		$visitor = \XF::visitor();
		$entryCount = $this->repo()->getEntryCount($tournament->tournament_id);
		$isJoined = $this->repo()->isUserRegistered($tournament->tournament_id, $visitor->user_id);
		$canJoin = !$isJoined
			&& $entryCount < $tournament->max_participants
			&& $tournament->canJoin($visitor);

		$viewerPet = null;
		if ($visitor->user_id)
		{
			/** @var UserPetsRepository $petRepo */
			$petRepo = $this->repository('Sylphian\UserPets:UserPets');
			$viewerPet = $petRepo->getUserPet($visitor->user_id);
		}

		return $this->view(
			'Sylphian\UserPets:Tournament\View',
			'sylphian_userpets_tournament_view',
			[
				'tournament' => $tournament,
				'entries' => $this->repo()->getEntriesForTournament($tournament->tournament_id),
				'bracketRounds' => $this->repo()->getDisplayBracketData($tournament),
				'entryCount' => $entryCount,
				'canJoin' => $canJoin,
				'isJoined' => $isJoined,
				'viewerPet' => $viewerPet,
			]
		);
	}

	public function actionJoin(ParameterBag $params): Redirect|Error
	{
		$this->assertRegistrationRequired();
		$this->assertPostOnly();

		$tournament = $this->assertViewableTournament($this->getRequestedTournamentId($params));

		/** @var \Sylphian\UserPets\Service\UserPetTournament $service */
		$service = $this->service('Sylphian\UserPets:UserPetTournament');
		$result = $service->joinTournament($tournament, \XF::visitor());

		if (!$result['success'])
		{
			return $this->error($result['error']);
		}

		return $this->redirect(
			$this->buildLink('userpetstournaments/view', null, ['tournament_id' => $tournament->tournament_id]),
			\XF::phrase('sylphian_userpets_tournament_join_success')
		);
	}

	/**
	 * Drafts are only visible to administrators who manage tournaments; for everyone
	 * else they do not exist.
	 */
	protected function assertViewableTournament(int $tournamentId): UserPetsTournament
	{
		$tournament = $this->repo()->findTournamentById($tournamentId);
		if (!$tournament || !$tournament->canView())
		{
			throw $this->exception($this->notFound());
		}

		return $tournament;
	}

	protected function getRequestedTournamentId(ParameterBag $params): int
	{
		return (int) ($params->tournament_id ?: $this->filter('tournament_id', 'uint'));
	}
}

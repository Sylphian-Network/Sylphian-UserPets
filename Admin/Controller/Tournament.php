<?php

namespace Sylphian\UserPets\Admin\Controller;

use Sylphian\UserPets\Entity\UserPetsTournament;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use Sylphian\UserPets\Service\UserPetTournament;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

class Tournament extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params): void
	{
		$this->assertAdminPermission(UserPetsTournament::ADMIN_PERMISSION);
		$this->setSectionContext('sylphian_userpets_tournaments');
	}

	protected function repo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTournament');
		return $repo;
	}

	protected function tournamentService(): UserPetTournament
	{
		/** @var UserPetTournament $service */
		$service = $this->service('Sylphian\UserPets:UserPetTournament');
		return $service;
	}

	public function actionIndex(): View
	{
		return $this->view(
			'Sylphian\UserPets:Tournament\Index',
			'sylphian_userpets_tournament_admin_list',
			[
				'tournaments' => $this->repo()->getAdminTournamentFinder()->fetch()
			]
		);
	}

	public function actionAdd(): View
	{
		/** @var UserPetsTournament $tournament */
		$tournament = $this->em()->create('Sylphian\UserPets:UserPetsTournament');

		return $this->tournamentAddEdit($tournament);
	}

	public function actionEdit(ParameterBag $params): View|Error
	{
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));
		return $this->tournamentAddEdit($tournament);
	}

	protected function tournamentAddEdit(UserPetsTournament $tournament): View
	{
		return $this->view(
			'Sylphian\UserPets:Tournament\Edit',
			'sylphian_userpets_tournament_admin_edit',
			[
				'tournament' => $tournament,
				'entryCount' => $tournament->tournament_id ? $this->repo()->getEntryCount($tournament->tournament_id) : 0
			]
		);
	}

	/**
	 * Saves the tournament settings only. The status changes through the lifecycle
	 * actions (open, close, start, advance, cancel), never through this form.
	 */
	public function actionSave(ParameterBag $params): AbstractReply
	{
		$this->assertPostOnly();

		$tournamentId = $this->getRequestedTournamentId($params);
		if ($tournamentId)
		{
			$tournament = $this->assertTournamentExists($tournamentId);
		}
		else
		{
			/** @var UserPetsTournament $tournament */
			$tournament = $this->em()->create('Sylphian\UserPets:UserPetsTournament');
			$tournament->created_user_id = \XF::visitor()->user_id;
		}

		$this->tournamentSaveProcess($tournament)->run();

		return $this->redirect($this->buildLink('sylphian_userpets/tournaments'));
	}

	protected function tournamentSaveProcess(UserPetsTournament $tournament): FormAction
	{
		$input = $this->filter([
			'title' => 'str',
			'description' => 'str',
			'max_participants' => 'uint',
			'prize_experience' => 'uint',
			'resolution_mode' => 'str',
		]);

		$values = [
			'title' => $input['title'],
			'description' => $input['description'],
		];

		if ($tournament->canEditParticipantLimit())
		{
			$values['max_participants'] = $input['max_participants'];
		}
		if ($tournament->canEditPrize())
		{
			$values['prize_experience'] = $input['prize_experience'];
		}
		if ($tournament->canEditResolutionMode())
		{
			$values['resolution_mode'] = $input['resolution_mode'];
		}

		$form = $this->formAction();
		$form->basicEntitySave($tournament, $values);

		return $form;
	}

	public function actionOpen(ParameterBag $params): Redirect|Error
	{
		$this->assertPostOnly();
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		return $this->lifecycleReply($tournament, $this->tournamentService()->openRegistration($tournament));
	}

	public function actionClose(ParameterBag $params): Redirect|Error
	{
		$this->assertPostOnly();
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		return $this->lifecycleReply($tournament, $this->tournamentService()->closeRegistration($tournament));
	}

	public function actionStart(ParameterBag $params): Redirect|Error
	{
		$this->assertPostOnly();
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		return $this->lifecycleReply($tournament, $this->tournamentService()->startTournament($tournament));
	}

	public function actionAdvance(ParameterBag $params): Redirect|Error
	{
		$this->assertPostOnly();
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		return $this->lifecycleReply($tournament, $this->tournamentService()->advanceTournament($tournament));
	}

	public function actionCancel(ParameterBag $params): View|Redirect|Error
	{
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		if (!$this->isPost())
		{
			if (!$tournament->canCancel())
			{
				return $this->error(\XF::phrase('sylphian_userpets_tournament_action_unavailable'));
			}

			return $this->view(
				'Sylphian\UserPets:Tournament\Cancel',
				'sylphian_userpets_tournament_admin_cancel',
				['tournament' => $tournament]
			);
		}

		return $this->lifecycleReply($tournament, $this->tournamentService()->cancelTournament($tournament));
	}

	public function actionView(ParameterBag $params): View|Error
	{
		$tournament = $this->assertTournamentExists($this->getRequestedTournamentId($params));

		return $this->view(
			'Sylphian\UserPets:Tournament\View',
			'sylphian_userpets_tournament_admin_view',
			[
				'tournament' => $tournament,
				'entries' => $this->repo()->getEntriesForTournament($tournament->tournament_id),
				'bracketRounds' => $this->repo()->getDisplayBracketData($tournament),
				'entryCount' => $this->repo()->getEntryCount($tournament->tournament_id),
			]
		);
	}

	/**
	 * @param array{success:bool, error?:\XF\Phrase} $result
	 */
	protected function lifecycleReply(UserPetsTournament $tournament, array $result): Redirect|Error
	{
		if (!$result['success'])
		{
			return $this->error($result['error']);
		}

		return $this->redirect($this->buildLink('sylphian_userpets/tournaments/view', null, ['tournament_id' => $tournament->tournament_id]));
	}

	protected function assertTournamentExists(int $tournamentId): UserPetsTournament
	{
		$tournament = $this->repo()->findTournamentById($tournamentId);
		if (!$tournament)
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

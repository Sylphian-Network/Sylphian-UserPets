<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\UserPets\Duel\DurationFormatter;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsDuel;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Helper\DuelPermission;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Service\DuelChallengeResult;
use Sylphian\UserPets\Service\PetManager;
use Sylphian\UserPets\Service\UserPetDuel as DuelService;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Exception;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

class UserPetDuel extends AbstractController
{
	/**
	 * @throws Exception
	 */
	protected function preDispatchController($action, ParameterBag $params): void
	{
		parent::preDispatchController($action, $params);

		$this->assertRegistrationRequired();

		if (!\XF::options()->sylphian_userpets_duel_enable)
		{
			throw $this->exception($this->noPermission());
		}

		if (UserPetOptOut::isDisabledForUser(\XF::visitor()))
		{
			throw $this->exception($this->noPermission());
		}
	}

	public function actionIndex(): View|Error
	{
		$visitor = \XF::visitor();

		/** @var UserPetsRepository $petRepo */
		$petRepo = $this->repository('Sylphian\UserPets:UserPets');
		$pet = $petRepo->getOrCreatePet($visitor->user_id);
		if (!$pet->pet_id)
		{
			return $this->error(\XF::phrase('sylphian_userpets_you_dont_have_pet'));
		}

		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $this->repository('Sylphian\UserPets:UserPetsDuel');

		$page = $this->filterPage();
		$perPage = 10;
		$historyFilter = $duelRepo->normalizeHistoryFilter($this->filter('history_filter', 'str'));

		$incoming = $duelRepo->getIncomingPendingDuelFinderForPet($pet->pet_id)->fetch();
		$outgoing = $duelRepo->getOutgoingPendingDuelFinderForPet($pet->pet_id)->fetch();
		$active = $duelRepo->getAcceptedDuelsForPet($pet->pet_id);

		$historyTotal = $duelRepo->countDuelHistoryForPet($pet->pet_id, $historyFilter);
		$history = $duelRepo->getDuelHistoryForPet($pet->pet_id, $historyFilter, $page, $perPage);

		return $this->view('Sylphian\UserPets:DuelManage', 'sylphian_userpets_duel_manage', [
			'pet' => $pet,
			'canDuel' => DuelPermission::canDuel($visitor),
			'overview' => $duelRepo->getPetDuelOverview($pet->pet_id),
			'incomingDuels' => $this->prepareDuelList($incoming->toArray(), $pet),
			'outgoingDuels' => $this->prepareDuelList($outgoing->toArray(), $pet),
			'activeDuels' => $this->prepareDuelList($active->toArray(), $pet),
			'historyDuels' => $this->prepareDuelList($history->toArray(), $pet),
			'historyFilter' => $historyFilter,
			'page' => $page,
			'perPage' => $perPage,
			'historyTotal' => $historyTotal,
		]);
	}

	/**
	 * @param ParameterBag $params The route parameters
	 * @return View|Error The view
	 */
	public function actionChallenge(ParameterBag $params): View|Error
	{
		$opponentPetId = $this->filter('opponent_pet_id', 'uint');

		if (!$opponentPetId && isset($params['opponent_pet_id']))
		{
			$opponentPetId = $params['opponent_pet_id'];
		}

		if (!$opponentPetId)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_opponent'));
		}

		$visitor = \XF::visitor();
		if (!DuelPermission::canDuel($visitor))
		{
			return $this->noPermission();
		}

		/** @var UserPets $myPet */
		$myPet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $visitor->user_id)
			->fetchOne();

		if (!$myPet)
		{
			return $this->error(\XF::phrase('sylphian_userpets_you_dont_have_pet'));
		}

		/** @var UserPets $opponentPet */
		$opponentPet = $this->em()->find('Sylphian\UserPets:UserPets', $opponentPetId, ['User']);
		if (!$opponentPet || !$opponentPet->User)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_opponent'));
		}

		if ($opponentPet->pet_id === $myPet->pet_id || $opponentPet->user_id === $myPet->user_id)
		{
			return $this->error(\XF::phrase('sylphian_userpets_cannot_challenge_self'));
		}

		if (!DuelPermission::canBeChallenged($opponentPet->User))
		{
			return $this->error(\XF::phrase('sylphian_userpets_disabled_pet'));
		}

		// shows the stats the fight would use; the decay is only stored when the duel is fought
		(new PetManager($myPet))->updateStats(false);
		(new PetManager($opponentPet))->updateStats(false);

		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $this->repository('Sylphian\UserPets:UserPetsDuel');
		if ($duelRepo->findExistingPendingDuelBetweenPets($myPet->pet_id, $opponentPet->pet_id))
		{
			return $this->error(\XF::phrase('sylphian_userpets_challenge_already_pending'));
		}
		if ($duelRepo->findPendingOutgoingDuelForPet($myPet->pet_id))
		{
			return $this->error(\XF::phrase('sylphian_userpets_outgoing_challenge_pending'));
		}

		return $this->view('Sylphian\UserPets:DuelChallenge', 'sylphian_userpets_duel_challenge', [
			'myPet' => $myPet,
			'opponentPet' => $opponentPet,
			'opponentDisplayName' => DisplayName::decode($opponentPet->User->username ?? ''),
			'opponentPetTitle' => DisplayName::renderUsernamePhrase(
				'sylphian_userpets_opponent_pet',
				$opponentPet->User->username ?? ''
			),
		]);
	}

	/**
	 * Process the duel challenge confirmation
	 *
	 * @return Redirect|Error
	 * @throws Exception
	 */
	public function actionChallengeConfirm(): Redirect|Error
	{
		$this->assertPostOnly();

		$opponentPetId = $this->filter('opponent_pet_id', 'uint');
		$myPetId = $this->filter('my_pet_id', 'uint');

		if (!$opponentPetId || !$myPetId)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_pet_data'));
		}

		/** @var UserPets $myPet */
		$myPet = $this->em()->find('Sylphian\UserPets:UserPets', $myPetId);

		/** @var UserPets $opponentPet */
		$opponentPet = $this->em()->find('Sylphian\UserPets:UserPets', $opponentPetId);

		if (!$myPet || !$opponentPet)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_pet_data'));
		}

		$visitor = \XF::visitor();
		if ($myPet->user_id !== $visitor->user_id)
		{
			return $this->error(\XF::phrase('sylphian_userpets_not_your_pet'));
		}

		/** @var DuelService $duelService */
		$duelService = $this->service('Sylphian\UserPets:UserPetDuel');
		$result = $duelService->createDuelChallenge($myPetId, $opponentPetId);

		if (!$result->isSuccess())
		{
			$error = match ($result->getStatus())
			{
				DuelChallengeResult::ERROR_DUEL_ALREADY_EXISTS => \XF::phrase('sylphian_userpets_challenge_already_pending'),
				DuelChallengeResult::ERROR_OUTGOING_DUEL_PENDING => \XF::phrase('sylphian_userpets_outgoing_challenge_pending'),
				DuelChallengeResult::ERROR_SAME_PET => \XF::phrase('sylphian_userpets_cannot_challenge_self'),
				DuelChallengeResult::ERROR_ON_COOLDOWN => \XF::phrase('sylphian_userpets_duel_on_cooldown', [
					'remaining_time' => DurationFormatter::format((int) ($result->getData()['remaining_time'] ?? 0)),
				]),
				DuelChallengeResult::ERROR_NOT_PERMITTED => \XF::phrase('sylphian_userpets_duel_not_permitted'),
				DuelChallengeResult::ERROR_USER_DISABLED => \XF::phrase('sylphian_userpets_disabled_pet'),
				default => \XF::phrase('sylphian_userpets_challenge_failed'),
			};

			return $this->error($error);
		}

		return $this->redirect(
			$this->buildLink('userpetsduel'),
			\XF::phrase('sylphian_userpets_challenge_sent_successfully')
		);
	}

	/**
	 * @throws Exception
	 */
	public function actionAcceptChallenge(): Redirect|Error
	{
		$this->assertPostOnly();

		return $this->changeDuelStatus(
			fn (DuelService $service, int $duelId, int $userId) => $service->acceptChallenge($duelId, $userId),
			'sylphian_userpets_duel_accept_failed',
			'sylphian_userpets_duel_accepted_successfully'
		);
	}

	/**
	 * @throws Exception
	 */
	public function actionRejectChallenge(): Redirect|Error
	{
		$this->assertPostOnly();

		return $this->changeDuelStatus(
			fn (DuelService $service, int $duelId, int $userId) => $service->rejectChallenge($duelId, $userId),
			'sylphian_userpets_duel_reject_failed',
			'sylphian_userpets_duel_rejected_successfully'
		);
	}

	/**
	 * @throws Exception
	 */
	public function actionCancelChallenge(): Redirect|Error
	{
		$this->assertPostOnly();

		return $this->changeDuelStatus(
			fn (DuelService $service, int $duelId, int $userId) => $service->cancelChallenge($duelId, $userId),
			'sylphian_userpets_duel_cancel_failed',
			'sylphian_userpets_duel_cancelled_successfully'
		);
	}

	/**
	 * @param callable(DuelService, int, int): DuelChallengeResult $action
	 */
	protected function changeDuelStatus(callable $action, string $failedPhrase, string $successPhrase): Redirect|Error
	{
		$duelId = $this->filter('duel_id', 'uint');
		if (!$duelId)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_duel'));
		}

		/** @var DuelService $duelService */
		$duelService = $this->service('Sylphian\UserPets:UserPetDuel');
		$result = $action($duelService, $duelId, \XF::visitor()->user_id);

		if (!$result->isSuccess())
		{
			return $this->error(\XF::phrase($failedPhrase, [
				'error' => $result->getError(),
			]));
		}

		$message = \XF::phrase($successPhrase);

		$reply = $this->redirect($this->buildLink('userpetsduel'), $message);
		$reply->setJsonParam('success', true);
		$reply->setJsonParam('message', $message);

		return $reply;
	}

	/**
	 * @param UserPetsDuel[] $duels
	 */
	protected function prepareDuelList(array $duels, UserPets $currentPet): array
	{
		$output = [];

		foreach ($duels AS $duel)
		{
			$output[] = $this->prepareDuel($duel, $currentPet);
		}

		return $output;
	}

	protected function prepareDuel(UserPetsDuel $duel, UserPets $currentPet): array
	{
		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $this->repository('Sylphian\UserPets:UserPetsDuel');

		$isChallenger = ($duel->challenger_pet_id === $currentPet->pet_id);
		$opponentPet = $isChallenger ? $duel->OpponentPet : $duel->ChallengerPet;
		$result = null;

		if ($duel->status === 'completed')
		{
			if ($duel->winner_pet_id === $currentPet->pet_id)
			{
				$result = 'win';
			}
			else if ($duel->loser_pet_id === $currentPet->pet_id)
			{
				$result = 'loss';
			}
		}

		return [
			'duel' => $duel,
			'isChallenger' => $isChallenger,
			'opponentPet' => $opponentPet,
			'opponentDisplayName' => DisplayName::decode($opponentPet?->User->username ?? ''),
			'result' => $result,
			'expiresAt' => $duelRepo->getRequestExpiryDate($duel),
		];
	}
}

<?php

namespace Sylphian\UserPets\Pub\Controller;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\DuelChallengeResult;
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

        if (!\XF::options()->sylphian_userpets_duel_enable)
        {
            throw $this->exception($this->noPermission());
        }
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
		/** @var UserPets $myPet */
		$myPet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $visitor->user_id)
			->fetchOne();

		if (!$myPet)
		{
			return $this->error(\XF::phrase('sylphian_userpets_you_dont_have_pet'));
		}

		/** @var UserPets $opponentPet */
		$opponentPet = $this->em()->find('Sylphian\UserPets:UserPets', $opponentPetId);
		if (!$opponentPet)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_opponent'));
		}

		Logger::debug('Duel Challenge', [
			'myPet' => $myPet->toArray(),
			'opponentPet' => $opponentPet->toArray(),
		]);

		return $this->view('Sylphian\UserPets:DuelChallenge', 'sylphian_userpets_duel_challenge', [
			'myPet' => $myPet,
			'opponentPet' => $opponentPet,
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

		Logger::debug("Duel challenge confirmation received for pets: {$myPetId} vs {$opponentPetId}");

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

		/** @var \Sylphian\UserPets\Service\UserPetDuel $duelService */
		$duelService = $this->service('Sylphian\UserPets:UserPetDuel');
		$result = $duelService->createDuelChallenge($myPetId, $opponentPetId);

		if (!$result->isSuccess())
		{
			return match ($result->getStatus())
			{
				DuelChallengeResult::ERROR_DUEL_ALREADY_EXISTS => Logger::loggedWarning(
					\XF::phrase('sylphian_userpets_challenge_already_pending'),
					['result' => $result->getStatus()]
				),
				DuelChallengeResult::ERROR_SAME_PET => Logger::loggedWarning(
					\XF::phrase('sylphian_userpets_cannot_challenge_self'),
					['result' => $result->getStatus()]
				),
				DuelChallengeResult::ERROR_ON_COOLDOWN => Logger::loggedWarning(
					\XF::phrase('sylphian_userpets_duel_on_cooldown', [
						'remaining_time' => $this->formatRemainingTime($result->getData()['remaining_time']),
					]),
					['result' => $result->getStatus()]
				),
				default => Logger::loggedWarning(
					\XF::phrase('sylphian_userpets_challenge_failed'),
					['result' => $result->getStatus()]
				),
			};
		}

		return $this->redirect(
			$this->buildLink('index'),
			\XF::phrase('sylphian_userpets_challenge_sent_successfully')
		);
	}

	/**
	 * Accept a duel challenge
	 *
	 * @return View|Redirect|Error
	 */
	public function actionAcceptChallenge(): Redirect|View|Error
	{
		$duelId = $this->filter('duel_id', 'uint');

		if (!$duelId)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_duel'));
		}

		$visitor = \XF::visitor();

		/** @var \Sylphian\UserPets\Service\UserPetDuel $duelService */
		$duelService = $this->service('Sylphian\UserPets:UserPetDuel');
		$result = $duelService->acceptChallenge($duelId, $visitor->user_id);

		if (!$result->isSuccess())
		{
			return $this->error(\XF::phrase('sylphian_userpets_duel_accept_failed', [
				'error' => $result->getData()['error'] ?? 'Unknown error',
			]));
		}

		$reply = $this->redirect($this->buildLink('index'));
		$reply->setJsonParam('success', true);
		$reply->setJsonParam('message', \XF::phrase('sylphian_userpets_duel_accepted_successfully'));

		return $reply;
	}

	/**
	 * Reject a duel challenge
	 *
	 * @return View|Redirect|Error
	 */
	public function actionRejectChallenge(): Redirect|View|Error
	{
		$duelId = $this->filter('duel_id', 'uint');

		if (!$duelId)
		{
			return $this->error(\XF::phrase('sylphian_userpets_invalid_duel'));
		}

		$visitor = \XF::visitor();

		/** @var \Sylphian\UserPets\Service\UserPetDuel $duelService */
		$duelService = $this->service('Sylphian\UserPets:UserPetDuel');
		$result = $duelService->rejectChallenge($duelId, $visitor->user_id);

		if (!$result->isSuccess())
		{
			return $this->error(\XF::phrase('sylphian_userpets_duel_reject_failed', [
				'error' => $result->getData()['error'] ?? 'Unknown error',
			]));
		}

		$reply = $this->redirect($this->buildLink('index'));
		$reply->setJsonParam('success', true);
		$reply->setJsonParam('message', \XF::phrase('sylphian_userpets_duel_rejected_successfully'));

		return $reply;
	}

	private function formatRemainingTime(int $seconds): string
	{
		$hours = floor($seconds / 3600);
		$minutes = floor(($seconds % 3600) / 60);

		$parts = [];
		if ($hours > 0)
		{
			$parts[] = "{$hours} " . \XF::phrase('hours');
		}
		if ($minutes > 0)
		{
			$parts[] = "{$minutes} " . \XF::phrase('minutes');
		}

		return implode(', ', $parts);
	}
}

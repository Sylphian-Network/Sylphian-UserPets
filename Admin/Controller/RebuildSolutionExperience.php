<?php

namespace Sylphian\UserPets\Admin\Controller;

use Sylphian\UserPets\Job\LegacyXpCorrection;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

class RebuildSolutionExperience extends AbstractController
{
	/**
	 * Set by the 1.5.0 upgrade: the moment awards started to pay the configured amount.
	 */
	public const LEGACY_XP_UNTIL_KEY = 'sylUserPetsLegacyXpUntil';

	public const COUNTER_REBUILD_KEY = 'sylphianUserPetsRebuildCounters';

	protected function preDispatchController($action, ParameterBag $params): void
	{
		$this->assertAdminPermission('syl_userpetsRebuild');
		$this->setSectionContext('sylphian_userpets_rebuild_solution_xp');
	}

	protected function repo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');
		return $repo;
	}

	public function actionIndex(): View
	{
		$repo = $this->repo();

		return $this->view(
			'Sylphian\UserPets:RebuildSolutionExperience\Index',
			'sylphian_userpets_rebuild_solution_xp',
			[
				'petCount' => $repo->getPetCount(),
				'summary' => $repo->getContentRewardSummary(),
				'threadXp' => (int) \XF::options()->sylphian_userpets_experience_per_thread,
				'postXp' => (int) \XF::options()->sylphian_userpets_experience_per_post,
				'xpPerSolution' => (int) \XF::options()->sylphian_userpets_experience_per_solution,
			]
		);
	}

	public function actionRun(): Redirect
	{
		$this->assertPostOnly();

		$this->app->jobManager()->enqueueUnique(
			'sylphianUserPetsRebuildSolutionExperience',
			'Sylphian\UserPets:RebuildSolutionExperience',
			[],
			true
		);

		return $this->redirect(
			$this->buildLink('sylphian_userpets/rebuild'),
			\XF::phrase('sylphian_userpets_content_xp_rebuild_started')
		);
	}

	/**
	 * Recounts every pet's duel wins, duel losses and tournament wins from the finished
	 * duels and tournaments.
	 */
	public function actionRecountCounters(): Redirect
	{
		$this->assertPostOnly();

		$jobId = $this->app->jobManager()->enqueueUnique(
			self::COUNTER_REBUILD_KEY,
			'Sylphian\UserPets:RebuildPetCounters',
			[],
			true
		);

		$reply = $this->redirect($this->buildLink('tools/run-job', null, [
			'only_id' => $jobId,
			'_xfRedirect' => $this->buildLink('sylphian_userpets/rebuild'),
		]));
		$reply->setPageParam('skipManualJobRun', true);

		return $reply;
	}

	public function actionLegacyXp(): View
	{
		return $this->view(
			'Sylphian\UserPets:LegacyXp',
			'sylphian_userpets_legacy_xp_correction',
			[
				'marker' => $this->getLegacyXpMarker(),
				'defaultNotBefore' => 0,
				'upgradeDate' => $this->getLegacyXpUntil(),
				'isRunning' => (bool) $this->app->jobManager()->getUniqueJob(LegacyXpCorrection::UNIQUE_KEY),
			]
		);
	}

	public function actionLegacyXpRun(): AbstractReply
	{
		$this->assertPostOnly();

		$jobManager = $this->app->jobManager();
		if ($jobManager->getUniqueJob(LegacyXpCorrection::UNIQUE_KEY))
		{
			return $this->error(\XF::phrase('sylphian_userpets_legacy_xp_already_running'));
		}

		$marker = $this->getLegacyXpMarker();
		$confirmRerun = $this->filter('confirm_rerun', 'bool');
		if ($marker && !$confirmRerun)
		{
			return $this->error(\XF::phrase('sylphian_userpets_legacy_xp_rerun_required'));
		}

		$notBefore = max(0, (int) $this->filter('not_before', 'datetime'));
		$enteredNotAfter = max(0, (int) $this->filter('not_after', 'datetime'));
		$upgradeDate = (int) $this->getLegacyXpUntil();

		// awards after the upgrade were paid correctly, so a later date must never widen the window
		$notAfter = $enteredNotAfter && $upgradeDate ? min($enteredNotAfter, $upgradeDate) : ($enteredNotAfter ?: $upgradeDate);
		if ($notAfter <= 0)
		{
			return $this->error(\XF::phrase('sylphian_userpets_legacy_xp_until_required'));
		}

		if ($notBefore >= $notAfter)
		{
			return $this->error(\XF::phrase('sylphian_userpets_legacy_xp_invalid_range'));
		}

		$jobId = $jobManager->enqueueUnique(
			LegacyXpCorrection::UNIQUE_KEY,
			'Sylphian\UserPets:LegacyXpCorrection',
			[
				'not_before' => $notBefore,
				'not_after' => $notAfter,
				'confirm_rerun' => $marker !== null,
			],
			true
		);

		$reply = $this->redirect($this->buildLink('tools/run-job', null, [
			'only_id' => $jobId,
			'_xfRedirect' => $this->buildLink('sylphian_userpets/rebuild/legacy-xp'),
		]));
		$reply->setPageParam('skipManualJobRun', true);

		return $reply;
	}

	/**
	 * @return array<string,int>|null
	 */
	protected function getLegacyXpMarker(): ?array
	{
		$marker = $this->app->registry()->get(LegacyXpCorrection::MARKER_KEY);

		return is_array($marker) ? $marker : null;
	}

	protected function getLegacyXpUntil(): ?int
	{
		$until = (int) $this->app->registry()->get(self::LEGACY_XP_UNTIL_KEY);

		return $until > 0 ? $until : null;
	}
}

<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Finder;

class DuelWinsProvider extends AbstractPetProvider
{
	public function getKey(): string
	{
		return 'pet_duel_wins';
	}

	public function getTitle(): string
	{
		return (string) \XF::phrase('sylphian_userpets_leaderboard_pet_duel_wins_title');
	}

	public function getDescription(): ?string
	{
		return (string) \XF::phrase('sylphian_userpets_leaderboard_pet_duel_wins_description');
	}

	public function getColumns(): array
	{
		return [
			['key' => 'position', 'label' => (string) \XF::phrase('position'), 'width' => '10%'],
			['key' => 'username', 'label' => (string) \XF::phrase('user'), 'width' => '30%'],
			['key' => 'wins',     'label' => (string) \XF::phrase('sylphian_userpets_wins'), 'width' => '20%'],
			['key' => 'losses',   'label' => (string) \XF::phrase('sylphian_userpets_losses'), 'width' => '20%'],
			['key' => 'wl_rate',  'label' => (string) \XF::phrase('sylphian_userpets_win_loss_rate'), 'width' => '30%'],
		];
	}

	protected function fetchRankedRows(App $app, int $limit): array
	{
		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $app->repository('Sylphian\UserPets:UserPetsDuel');

		return $duelRepo->getTopUsersByWins($limit);
	}

	protected function buildMetrics(array $rankedRow): array
	{
		$wins = (int) $rankedRow['wins'];
		$losses = (int) $rankedRow['losses'];

		return [
			'wins' => $wins,
			'losses' => $losses,
			'wl_rate' => static::getWinLossRate($wins, $losses),
		];
	}

	public function buildFinder(App $app): Finder
	{
		return $app->finder('XF:User')->limit(0);
	}

	public function mapRow(Entity $entity, int $position): array
	{
		return [
			'position' => $position,
			'username' => $entity,
			'wins'     => 0,
			'losses'   => 0,
		];
	}
}

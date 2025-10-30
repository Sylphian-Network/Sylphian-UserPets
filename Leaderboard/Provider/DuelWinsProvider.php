<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\Leaderboard\Provider\AbstractProvider;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Finder;

class DuelWinsProvider extends AbstractProvider
{
	private const string ENTITY_USER = 'XF:User';
	private const string ENTITY_DUEL = 'Sylphian\\UserPets:UserPetsDuel';
	private const string ENTITY_PET  = 'Sylphian\\UserPets:UserPets';

	public function getKey(): string
	{
		return 'pet_duel_wins';
	}

	public function getTitle(): string
	{
		return 'Pet duel wins';
	}

	public function getDescription(): ?string
	{
		return 'Users ranked by total completed pet duel wins.';
	}

	public function getColumns(): array
	{
		return [
			['key' => 'position', 'label' => 'Position', 'width' => '10%'],
			['key' => 'username', 'label' => 'User',     'width' => '30%'],
			['key' => 'wins',     'label' => 'Wins',     'width' => '20%'],
			['key' => 'losses',   'label' => 'Losses',   'width' => '20%'],
			['key' => 'wl_rate',  'label' => 'Win/Lose rate',      'width' => '30%'],
		];
	}

	public function fetchRows(App $app): array
	{
		$limit = $this->getLimit();
		if ($limit <= 0)
		{
			return [];
		}

		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $app->repository('Sylphian\UserPets:UserPetsDuel');
		$ranked = $duelRepo->getTopUsersByWins($limit);
		if (!$ranked)
		{
			return [];
		}

		$userIds = array_column($ranked, 'user_id');
		$usersById = $app->em()->findByIds(self::ENTITY_USER, $userIds);

		$rows = [];
		$position = 1;
		foreach ($ranked AS $r)
		{
			$user = $usersById[$r['user_id']] ?? null;
			if (!$user)
			{
				continue;
			}

			$wins = (int) $r['wins'];
			$losses = (int) $r['losses'];
			$rate = $losses > 0 ? ($wins / $losses) : (float) $wins;
			$rate = round($rate, 1);

			$rows[] = [
				'position' => $position++,
				'username' => $user,
				'wins'     => $wins,
				'losses'   => $losses,
				'wl_rate'  => $rate,
			];
		}

		return $rows;
	}

	public function buildFinder(App $app): Finder
	{
		return $app->finder(self::ENTITY_USER)->limit(0);
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

	private function resolveTables(App $app): array
	{
		$em = $app->em();
		$duel = $em->getEntityStructure(self::ENTITY_DUEL)->table;
		$pets = $em->getEntityStructure(self::ENTITY_PET)->table;
		return ["`{$duel}`", "`{$pets}`"];
	}

	public function canView(App $app): bool
	{
		return true;
	}
}

<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\Leaderboard\Provider\AbstractProvider;
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
			['key' => 'position', 'label' => 'Position'],
			['key' => 'username', 'label' => 'User'],
			['key' => 'wins',     'label' => 'Wins'],
			['key' => 'losses',   'label' => 'Losses'],
		];
	}

	public function fetchRows(App $app): array
	{
		$limit = $this->getLimit();
		if ($limit <= 0)
		{
			return [];
		}

		[$duelTable, $petsTable] = $this->resolveTables($app);

		$sql = "
            SELECT t.user_id,
                   SUM(t.wins)   AS wins,
                   SUM(t.losses) AS losses
            FROM (
                SELECT p.user_id, 1 AS wins, 0 AS losses
                FROM {$duelTable} d
                INNER JOIN {$petsTable} p ON p.pet_id = d.winner_pet_id
                WHERE d.status = 'completed'

                UNION ALL

                SELECT p.user_id, 0 AS wins, 1 AS losses
                FROM {$duelTable} d
                INNER JOIN {$petsTable} p ON p.pet_id = d.loser_pet_id
                WHERE d.status = 'completed'
            ) AS t
            GROUP BY t.user_id
            HAVING SUM(t.wins) > 0
            ORDER BY wins DESC, t.user_id
            LIMIT {$limit}
        ";

		$db = $app->db();
		$ranked = $db->fetchAll($sql);
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

			$rows[] = [
				'position' => $position++,
				'username' => $user,
				'wins'     => (int) $r['wins'],
				'losses'   => (int) $r['losses'],
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

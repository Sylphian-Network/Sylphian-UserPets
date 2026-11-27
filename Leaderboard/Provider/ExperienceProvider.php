<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\UserPets\Entity\UserPets;
use XF\App;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Finder as EntityFinder;

class ExperienceProvider extends AbstractPetProvider
{
	public function getKey(): string
	{
		return 'pet_level';
	}

	public function getTitle(): string
	{
		return (string) \XF::phrase('sylphian_userpets_leaderboard_pet_level_title');
	}

	public function getDescription(): ?string
	{
		return (string) \XF::phrase('sylphian_userpets_leaderboard_pet_level_description');
	}

	public function getColumns(): array
	{
		return [
			['key' => 'position', 'label' => (string) \XF::phrase('position'), 'width' => '10%'],
			['key' => 'username', 'label' => (string) \XF::phrase('user'), 'width' => '40%'],
			['key' => 'level',    'label' => (string) \XF::phrase('sylphian_userpets_level_label'), 'width' => '15%'],
			['key' => 'exp',      'label' => (string) \XF::phrase('sylphian_userpets_experience'), 'width' => '35%'],
		];
	}

	public function buildFinder(App $app): EntityFinder
	{
		return $app->finder('Sylphian\\UserPets:UserPets')
			->with('User', true)
			->with('User.Profile')
			->order('experience', 'DESC')
			->order('level', 'DESC');
	}

	protected function fetchRankedRows(App $app, int $limit): array
	{
		$rows = $app->finder('Sylphian\\UserPets:UserPets')
			->with('User', true)
			->order('experience', 'DESC')
			->order('level', 'DESC')
			->order('pet_id')
			->limit($limit)
			->fetchColumns('user_id', 'level', 'experience');

		return array_map(fn (array $row) => [
			'user_id' => (int) $row['user_id'],
			'level' => (int) $row['level'],
			'experience' => (int) $row['experience'],
		], $rows);
	}

	protected function buildMetrics(array $rankedRow): array
	{
		return [
			'level' => (int) $rankedRow['level'],
			'exp' => (int) $rankedRow['experience'],
		];
	}

	/** @param UserPets $entity */
	public function mapRow(Entity $entity, int $position): array
	{
		/** @var User|null $user */
		$user = $entity->User;
		return [
			'position' => $position,
			'username' => $user,
			'level' => (int) ($entity->get('level') ?? 0),
			'exp' => (int) ($entity->get('experience') ?? 0),
		];
	}
}

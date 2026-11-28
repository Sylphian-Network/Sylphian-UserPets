<?php

namespace Sylphian\UserPets\Leaderboard\Provider;

use Sylphian\Leaderboard\Provider\AbstractProvider;
use Sylphian\UserPets\Entity\UserPets;
use XF\App;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Finder as EntityFinder;

class ExperienceProvider extends AbstractProvider
{
	public function getKey(): string
	{
		return 'pet_level';
	}
	public function getTitle(): string
	{
		return \XF::phrase('sylphian_userpets_leaderboard_pet_level')->render();
	}

	public function getDescription(): ?string
	{
		return \XF::phrase('sylphian_userpets_leaderboard_pet_level_desc')->render();
	}

	public function getColumns(): array
	{
		return [
			['key' => 'position', 'label' => \XF::phrase('position')->render(), 'width' => '10%'],
			['key' => 'username', 'label' => \XF::phrase('user')->render(), 'width' => '40%'],
			['key' => 'level', 'label' => \XF::phrase('sylphian_userpets_column_level')->render(), 'width' => '15%'],
			['key' => 'exp', 'label' => \XF::phrase('sylphian_userpets_column_experience')->render(), 'width' => '35%'],
		];
	}

	public function buildFinder(App $app): EntityFinder
	{
		return $app->finder('Sylphian\\UserPets:UserPets')
			->with('User', true)
			->order('experience', 'DESC')
			->order('level', 'DESC');
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

	public function canView(App $app): bool
	{
		return true;
	}
}

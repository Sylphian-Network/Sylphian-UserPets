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
		return 'Pet Level';
	}

	public function getDescription(): ?string
	{
		return 'Users ranked by total pet experience.';
	}

	public function getColumns(): array
	{
		return [
			['key' => 'position', 'label' => 'Position',  'width' => '10%'],
			['key' => 'username', 'label' => 'User',      'width' => '40%'],
			['key' => 'level',    'label' => 'Level',     'width' => '15%'],
			['key' => 'exp',      'label' => 'Experience','width' => '35%'],
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

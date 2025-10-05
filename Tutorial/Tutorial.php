<?php

namespace Sylphian\UserPets\Tutorial;

use Sylphian\UserPets\Repository\UserPetsTutorialRepository;

/**
 * Represents a single tutorial in the system
 */
class Tutorial
{
	private string $id;
	private string $title;
	private string $rewardExpOption;

	private function __construct(string $id, string $title, string $rewardExpOption)
	{
		$this->id = $id;
		$this->title = $title;
		$this->rewardExpOption = $rewardExpOption;
	}

	public static function create(string $id, string $title, string $rewardExpOption): self
	{
		return new self($id, $title, $rewardExpOption);
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getRewardExpOption(): string
	{
		return $this->rewardExpOption;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'reward_exp_option' => $this->rewardExpOption,
		];
	}

	public function isCompleted(int $userId): bool
	{
		/** @var UserPetsTutorialRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPetsTutorial');
		return $repo->isTutorialCompleted($userId, $this->id);
	}

	public function complete(int $userId): bool
	{
		/** @var UserPetsTutorialRepository $repo */
		$repo = \XF::repository('Sylphian\UserPets:UserPetsTutorial');
		return $repo->completeTutorial($userId, $this->id);
	}

	public function getRewardAmount(): int
	{
		return \XF::options()->{$this->rewardExpOption} ?? 0;
	}

	public function getMetadata(): array
	{
		return [
			'title' => $this->title,
			'reward_exp_option' => $this->rewardExpOption,
		];
	}
}

<?php

namespace Sylphian\UserPets\Tutorial;

use Sylphian\UserPets\Repository\UserPetsTutorialRepository;
use XF\Phrase;

/**
 * Represents a single tutorial in the system
 */
class Tutorial
{
	private string $id;
	private string $titlePhrase;
	private string $rewardExpOption;

	private function __construct(string $id, string $titlePhrase, string $rewardExpOption)
	{
		$this->id = $id;
		$this->titlePhrase = $titlePhrase;
		$this->rewardExpOption = $rewardExpOption;
	}

	public static function create(string $id, string $titlePhrase, string $rewardExpOption): self
	{
		return new self($id, $titlePhrase, $rewardExpOption);
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): Phrase
	{
		return \XF::phrase($this->titlePhrase);
	}

	public function getRewardExpOption(): string
	{
		return $this->rewardExpOption;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->getTitle(),
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
			'title' => $this->getTitle(),
			'reward_exp_option' => $this->rewardExpOption,
		];
	}
}

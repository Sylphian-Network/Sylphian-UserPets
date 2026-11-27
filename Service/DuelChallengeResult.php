<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPetsDuel;

class DuelChallengeResult
{
	public const string SUCCESS = 'success';
	public const string ERROR_PETS_NOT_FOUND = 'pets_not_found';
	public const string ERROR_SAME_PET = 'same_pet';
	public const string ERROR_DUEL_ALREADY_EXISTS = 'duel_already_exists';
	public const string ERROR_OUTGOING_DUEL_PENDING = 'outgoing_duel_pending';
	public const string ERROR_UNKNOWN = 'unknown';
	public const string ERROR_ON_COOLDOWN = 'on_cooldown';
	public const string ERROR_USER_DISABLED = 'user_disabled';
	public const string ERROR_NOT_PERMITTED = 'not_permitted';

	protected string $status;
	protected ?UserPetsDuel $duel;
	protected ?array $data;

	public function __construct(string $status, ?UserPetsDuel $duel = null, ?array $data = null)
	{
		$this->status = $status;
		$this->duel = $duel;
		$this->data = $data;
	}

	public function isSuccess(): bool
	{
		return $this->status === self::SUCCESS;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function getDuel(): ?UserPetsDuel
	{
		return $this->duel;
	}

	public function getData(): ?array
	{
		return $this->data;
	}

	/**
	 * Member-facing reason for a failure (a phrase, or the text of a PrintableException).
	 */
	public function getError(): \XF\Phrase|string
	{
		return $this->data['error'] ?? \XF::phrase('sylphian_userpets_unknown_error');
	}
}

<?php

namespace Sylphian\UserPets\Entity;

use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Repository\UserPetsTournamentRepository;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use XF\Phrase;

/**
 * COLUMNS
 * @property int $tournament_id
 * @property string $title
 * @property string $description
 * @property int $max_participants
 * @property int $prize_experience
 * @property string $resolution_mode
 * @property string $status
 * @property int $winner_pet_id
 * @property int $created_user_id
 * @property int $created_at
 * @property int $registration_opened_at
 * @property int $registration_closed_at
 * @property int $started_at
 * @property int $completed_at
 *
 * RELATIONS
 * @property UserPets|null $WinnerPet
 */
class UserPetsTournament extends Entity
{
	public const STATUS_DRAFT = 'draft';
	public const STATUS_REGISTRATION_OPEN = 'registration_open';
	public const STATUS_REGISTRATION_CLOSED = 'registration_closed';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';

	public const MODE_MANUAL = 'manual';
	public const MODE_AUTOMATIC = 'automatic';

	public const MIN_PARTICIPANTS = 2;
	public const MAX_PARTICIPANTS = 512;

	public const ADMIN_PERMISSION = 'syl_userpetsTournaments';

	/**
	 * @return list<string>
	 */
	public static function getStartableStatuses(): array
	{
		return [self::STATUS_REGISTRATION_OPEN, self::STATUS_REGISTRATION_CLOSED];
	}

	/**
	 * @return list<string>
	 */
	public static function getCancellableStatuses(): array
	{
		return [
			self::STATUS_DRAFT,
			self::STATUS_REGISTRATION_OPEN,
			self::STATUS_REGISTRATION_CLOSED,
			self::STATUS_IN_PROGRESS,
		];
	}

	public function isRegistrationPhase(): bool
	{
		return in_array($this->status, [
			self::STATUS_DRAFT,
			self::STATUS_REGISTRATION_OPEN,
			self::STATUS_REGISTRATION_CLOSED,
		], true);
	}

	public function isFinished(): bool
	{
		return $this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELLED;
	}

	public function canView(?User $user = null): bool
	{
		if ($this->status !== self::STATUS_DRAFT)
		{
			return true;
		}

		$user = $user ?: \XF::visitor();

		return $user->is_admin && $user->hasAdminPermission(self::ADMIN_PERMISSION);
	}

	/**
	 * Whether the user may sign up. A pet is not required: joining creates one.
	 */
	public function canJoin(?User $user = null, &$error = null): bool
	{
		$user = $user ?: \XF::visitor();

		if (!$user->user_id)
		{
			return false;
		}

		if ($this->status !== self::STATUS_REGISTRATION_OPEN)
		{
			$error = \XF::phrase('sylphian_userpets_tournament_registration_closed');
			return false;
		}

		if (UserPetOptOut::isDisabledForUser($user))
		{
			$error = \XF::phrase('sylphian_userpets_disabled_pet');
			return false;
		}

		return true;
	}

	public function canOpenRegistration(): bool
	{
		return $this->status === self::STATUS_DRAFT || $this->status === self::STATUS_REGISTRATION_CLOSED;
	}

	public function canCloseRegistration(): bool
	{
		return $this->status === self::STATUS_REGISTRATION_OPEN;
	}

	public function canStart(): bool
	{
		return in_array($this->status, self::getStartableStatuses(), true);
	}

	public function canAdvance(): bool
	{
		return $this->status === self::STATUS_IN_PROGRESS;
	}

	public function canCancel(): bool
	{
		return in_array($this->status, self::getCancellableStatuses(), true);
	}

	public function canEditParticipantLimit(): bool
	{
		return !$this->exists() || $this->isRegistrationPhase();
	}

	public function canEditPrize(): bool
	{
		return !$this->isFinished();
	}

	public function canEditResolutionMode(): bool
	{
		return !$this->isFinished();
	}

	public function getStatusPhrase(): Phrase
	{
		return \XF::phrase('sylphian_userpets_tournament_status_' . $this->status);
	}

	protected function _preSave(): void
	{
		if ($this->isUpdate() && $this->isChanged('max_participants'))
		{
			$entryCount = $this->getTournamentRepo()->getEntryCount($this->tournament_id);
			if ($this->max_participants < $entryCount)
			{
				$this->error(
					\XF::phrase('sylphian_userpets_tournament_max_below_entries', ['count' => $entryCount]),
					'max_participants'
				);
			}
		}
	}

	protected function getTournamentRepo(): UserPetsTournamentRepository
	{
		/** @var UserPetsTournamentRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsTournament');
		return $repo;
	}

	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_user_pets_tournaments';
		$structure->shortName = 'Sylphian\UserPets:UserPetsTournament';
		$structure->primaryKey = 'tournament_id';

		$structure->columns = [
			'tournament_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'title' => ['type' => self::STR, 'required' => 'please_enter_valid_title', 'maxLength' => 100],
			'description' => ['type' => self::STR, 'default' => ''],
			'max_participants' => [
				'type' => self::UINT,
				'default' => 8,
				'min' => self::MIN_PARTICIPANTS,
				'max' => self::MAX_PARTICIPANTS,
			],
			'prize_experience' => ['type' => self::UINT, 'default' => 0],
			'resolution_mode' => [
				'type' => self::STR,
				'allowedValues' => [self::MODE_MANUAL, self::MODE_AUTOMATIC],
				'default' => self::MODE_MANUAL
			],
			'status' => [
				'type' => self::STR,
				'allowedValues' => [
					self::STATUS_DRAFT,
					self::STATUS_REGISTRATION_OPEN,
					self::STATUS_REGISTRATION_CLOSED,
					self::STATUS_IN_PROGRESS,
					self::STATUS_COMPLETED,
					self::STATUS_CANCELLED,
				],
				'default' => self::STATUS_DRAFT
			],
			'winner_pet_id' => ['type' => self::UINT, 'default' => 0],
			'created_user_id' => ['type' => self::UINT, 'default' => 0],
			'created_at' => ['type' => self::UINT, 'default' => \XF::$time],
			'registration_opened_at' => ['type' => self::UINT, 'default' => 0],
			'registration_closed_at' => ['type' => self::UINT, 'default' => 0],
			'started_at' => ['type' => self::UINT, 'default' => 0],
			'completed_at' => ['type' => self::UINT, 'default' => 0],
		];

		$structure->getters = [];

		$structure->relations = [
			'WinnerPet' => [
				'entity' => 'Sylphian\UserPets:UserPets',
				'type' => self::TO_ONE,
				'conditions' => [['pet_id', '=', '$winner_pet_id']],
				'primary' => true
			]
		];

		return $structure;
	}
}

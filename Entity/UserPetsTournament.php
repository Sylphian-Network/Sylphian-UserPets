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
 * @property int $registration_open_date 0 = opened by an admin
 * @property int $registration_close_date 0 = closed by an admin
 * @property int $start_date 0 = started by an admin
 * @property int $round_interval_hours Automatic mode: hours between rounds; 0 = all rounds at once
 * @property int $next_round_date
 * @property int $repeat_days 0 = no repeat; otherwise a copy is scheduled this many days later
 * @property int $repeat_of_id The tournament this one was repeated from
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

	public const SCHEDULE_REGISTRATION_OPEN = 'registration_open';
	public const SCHEDULE_REGISTRATION_CLOSE = 'registration_close';
	public const SCHEDULE_START = 'start';
	public const SCHEDULE_NEXT_ROUND = 'next_round';

	/**
	 * Schedule date columns in the order the steps happen.
	 */
	public const SCHEDULE_DATE_COLUMNS = ['registration_open_date', 'registration_close_date', 'start_date'];

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
	 * Whether the user may sign up: the joinTournament permission and a pet that is not
	 * opted out. A pet row is not required: joining creates one.
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

		if (!$user->hasPermission('sylUserPets', 'joinTournament'))
		{
			$error = \XF::phrase('do_not_have_permission');
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

	/**
	 * A schedule date can change only while its step has not happened yet.
	 */
	public function canEditRegistrationOpenDate(): bool
	{
		return !$this->exists() || $this->status === self::STATUS_DRAFT;
	}

	public function canEditRegistrationCloseDate(): bool
	{
		return !$this->exists()
			|| $this->status === self::STATUS_DRAFT
			|| $this->status === self::STATUS_REGISTRATION_OPEN;
	}

	public function canEditStartDate(): bool
	{
		return !$this->exists() || $this->isRegistrationPhase();
	}

	public function canEditRoundInterval(): bool
	{
		return $this->canEditResolutionMode();
	}

	public function canEditRepeat(): bool
	{
		return !$this->isFinished();
	}

	/**
	 * Automatic mode with an interval plays one round per interval instead of every round
	 * at the start.
	 */
	public function hasRoundInterval(): bool
	{
		return $this->resolution_mode === self::MODE_AUTOMATIC && $this->round_interval_hours > 0;
	}

	public function hasSchedule(): bool
	{
		return $this->registration_open_date > 0
			|| $this->registration_close_date > 0
			|| $this->start_date > 0
			|| $this->hasRoundInterval();
	}

	/**
	 * The scheduled steps still ahead for the current status, in the order they happen,
	 * keyed by the SCHEDULE_* constants. Empty when nothing is scheduled any more.
	 *
	 * @return array<string, int>
	 */
	public function getUpcomingSchedule(): array
	{
		$dates = [];

		switch ($this->status)
		{
			case self::STATUS_DRAFT:
				$dates[self::SCHEDULE_REGISTRATION_OPEN] = $this->registration_open_date;
				$dates[self::SCHEDULE_REGISTRATION_CLOSE] = $this->registration_close_date;
				$dates[self::SCHEDULE_START] = $this->start_date;
				break;

			case self::STATUS_REGISTRATION_OPEN:
				$dates[self::SCHEDULE_REGISTRATION_CLOSE] = $this->registration_close_date;
				$dates[self::SCHEDULE_START] = $this->start_date;
				break;

			case self::STATUS_REGISTRATION_CLOSED:
				$dates[self::SCHEDULE_START] = $this->start_date;
				break;

			case self::STATUS_IN_PROGRESS:
				if ($this->hasRoundInterval())
				{
					$dates[self::SCHEDULE_NEXT_ROUND] = $this->next_round_date;
				}
				break;
		}

		return array_filter($dates, static fn (int $date): bool => $date > 0);
	}

	/**
	 * @return array{step:string, date:int}|null
	 */
	public function getNextScheduledStep(): ?array
	{
		$schedule = $this->getUpcomingSchedule();
		if (!$schedule)
		{
			return null;
		}

		$step = array_key_first($schedule);

		return ['step' => $step, 'date' => $schedule[$step]];
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

		$this->validateSchedule();

		if ($this->isUpdate()
			&& $this->status === self::STATUS_IN_PROGRESS
			&& ($this->isChanged('resolution_mode') || $this->isChanged('round_interval_hours'))
		)
		{
			$this->next_round_date = $this->hasRoundInterval()
				? \XF::$time + $this->round_interval_hours * 3600
				: 0;
		}
	}

	/**
	 * Registration opens before it closes and the tournament starts no earlier than the
	 * close; a date that is set or moved must lie ahead. Dates that are already past and
	 * untouched stay valid, so an edit never fails because of a step that has happened.
	 */
	protected function validateSchedule(): void
	{
		$open = $this->registration_open_date;
		$close = $this->registration_close_date;
		$start = $this->start_date;

		foreach (self::SCHEDULE_DATE_COLUMNS AS $column)
		{
			if ($this->isChanged($column) && $this->get($column) > 0 && $this->get($column) <= \XF::$time)
			{
				$this->error(\XF::phrase('please_enter_a_date_in_the_future'), $column);
			}
		}

		if ($open && $close && $close <= $open)
		{
			$this->error(\XF::phrase('sylphian_userpets_tournament_close_after_open'), 'registration_close_date');
		}

		if ($close && $start && $start < $close)
		{
			$this->error(\XF::phrase('sylphian_userpets_tournament_start_after_close'), 'start_date');
		}
		elseif ($open && $start && $start <= $open)
		{
			$this->error(\XF::phrase('sylphian_userpets_tournament_start_after_open'), 'start_date');
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
			'registration_open_date' => ['type' => self::UINT, 'default' => 0],
			'registration_close_date' => ['type' => self::UINT, 'default' => 0],
			'start_date' => ['type' => self::UINT, 'default' => 0],
			'round_interval_hours' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
			'next_round_date' => ['type' => self::UINT, 'default' => 0],
			'repeat_days' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
			'repeat_of_id' => ['type' => self::UINT, 'default' => 0],
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

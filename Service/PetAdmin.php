<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\PetState;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsXpLogRepository;
use Sylphian\UserPets\XpSource;
use XF\Mvc\Entity\Finder;
use XF\Phrase;
use XF\Repository\UserRepository;
use XF\Service\AbstractService;

/**
 * The admin pet tool. Every XP change goes through UserPetsRepository::savePetWithExperience(),
 * so the level follows the new XP and the XP history names the admin who made the change.
 */
class PetAdmin extends AbstractService
{
	public const ORDERS = ['level', 'experience', 'duel_wins', 'created_at'];

	public const DEFAULT_ORDER = 'level';

	/**
	 * The XP history stores the change as a signed 32-bit amount.
	 */
	public const MAX_EXPERIENCE = 2147483647;

	protected ?PetSettings $petSettings = null;

	public function normalizeOrder(string $order): string
	{
		return in_array($order, self::ORDERS, true) ? $order : self::DEFAULT_ORDER;
	}

	public function normalizeDirection(string $direction): string
	{
		return strtolower($direction) === 'asc' ? 'asc' : 'desc';
	}

	/**
	 * Pets with their owners, optionally limited to owners whose name starts with $usernamePrefix.
	 */
	public function findPetsForList(string $usernamePrefix = '', string $order = self::DEFAULT_ORDER, string $direction = 'desc'): Finder
	{
		$order = $this->normalizeOrder($order);
		$direction = $this->normalizeDirection($direction);

		$finder = $this->finder('Sylphian\UserPets:UserPets')->with('User');

		$usernamePrefix = trim($usernamePrefix);
		if ($usernamePrefix !== '')
		{
			$finder->where('User.username', 'LIKE', $finder->escapeLike($usernamePrefix, '?%'));
		}

		$finder->order($order, $direction);
		if ($order === 'level')
		{
			$finder->order('experience', $direction);
		}
		$finder->order('pet_id', $direction);

		return $finder;
	}

	/**
	 * Normalizes and validates a pet name with the rules members get (PetSettings). An empty
	 * name is valid and means the default name.
	 *
	 * @param Phrase|null $error set when the name is refused
	 * @return string the normalized name
	 */
	public function validatePetName(string $name, ?Phrase &$error = null): string
	{
		$settings = $this->getPetSettingsService();

		$name = $settings->normalizePetName($name);
		$error = $settings->getPetNameError($name);

		return $name;
	}

	public function getNameMaxLength(): int
	{
		return $this->getPetSettingsService()->getNameMaxLength();
	}

	/**
	 * Appearances an admin can choose, from the cached render index: file name => label.
	 * The empty key is the default appearance.
	 *
	 * @param string $current the pet's sheet; listed even when its file is gone, so the form
	 *     shows it instead of silently switching to the default
	 * @return array<string, string>
	 */
	public function getAppearanceChoices(string $current = ''): array
	{
		$index = $this->getPetRepo()->getSpritesheetRenderIndex();

		$choices = [];
		foreach ($index['sheets'] ?? [] AS $filename => $sheet)
		{
			if (!is_array($sheet) || empty($sheet['exists']))
			{
				continue;
			}

			$title = trim((string) ($sheet['title'] ?? ''));
			$choices[(string) $filename] = $title !== '' ? $title : (string) $filename;
		}

		natcasesort($choices);

		$current = trim($current);
		if ($current !== '' && !isset($choices[$current]))
		{
			$choices[$current] = $current;
		}

		return ['' => (string) \XF::phrase('sylphian_userpets_default_appearance')] + $choices;
	}

	/**
	 * Saves the name, appearance, disabled and sound settings. XP is changed with setExperience().
	 *
	 * @param array{pet_name?:string, spritesheet?:string, is_disabled?:bool, sound_enabled?:bool} $input
	 * @return array<string, Phrase> errors by field; empty when the pet was saved
	 */
	public function updatePet(UserPets $pet, array $input): array
	{
		$errors = [];

		// A name that is submitted unchanged is kept even if it predates the name rules.
		$name = $this->getPetSettingsService()->normalizePetName((string) ($input['pet_name'] ?? ''));
		if ($name !== (string) $pet->pet_name)
		{
			$name = $this->validatePetName($name, $nameError);
			if ($nameError)
			{
				$errors['pet_name'] = $nameError;
			}
		}

		// An appearance whose file has gone may stay as it is; it cannot be chosen anew.
		$spritesheet = trim((string) ($input['spritesheet'] ?? ''));
		if ($spritesheet !== ''
			&& $spritesheet !== (string) $pet->spritesheet
			&& !isset($this->getAppearanceChoices()[$spritesheet])
		)
		{
			$errors['spritesheet'] = \XF::phrase('please_enter_valid_value');
		}

		if ($errors)
		{
			return $errors;
		}

		$pet->pet_name = $name;
		$pet->spritesheet = $spritesheet;
		$pet->is_disabled = !empty($input['is_disabled']);
		$pet->sound_enabled = !empty($input['sound_enabled']);
		$pet->save();

		return [];
	}

	/**
	 * Sets the pet's total XP. The difference is applied and logged as an admin change.
	 *
	 * @param Phrase|null $error set when the value is refused
	 */
	public function setExperience(UserPets $pet, int $experience, int $actorUserId, ?Phrase &$error = null): bool
	{
		$error = null;

		if ($experience < 0)
		{
			$error = \XF::phrase('please_enter_number_that_is_at_least_x', ['min' => 0]);
			return false;
		}

		if ($experience > self::MAX_EXPERIENCE)
		{
			$error = \XF::phrase('please_enter_number_that_is_no_more_than_x', ['max' => self::MAX_EXPERIENCE]);
			return false;
		}

		$delta = $experience - (int) $pet->experience;

		return $this->getPetRepo()->savePetWithExperience($pet, $delta, XpSource::ADMIN, '', 0, $actorUserId);
	}

	/**
	 * XP 0, level 1, full stats. The removed XP is logged as an admin change; the duel and
	 * tournament counters and the XP history are kept.
	 */
	public function resetPet(UserPets $pet, int $actorUserId): bool
	{
		$pet->hunger = 100;
		$pet->sleepiness = 100;
		$pet->happiness = 100;
		$pet->state = PetState::DEFAULT_STATE;
		$pet->last_update = \XF::$time;

		return $this->getPetRepo()->savePetWithExperience(
			$pet,
			-(int) $pet->experience,
			XpSource::ADMIN,
			'',
			0,
			$actorUserId
		);
	}

	/**
	 * Applies the list toggles: pet id => enabled.
	 *
	 * @param array<int, bool> $enabledByPetId
	 * @return int pets changed
	 */
	public function setEnabledStates(array $enabledByPetId): int
	{
		$petIds = array_values(array_filter(array_map('intval', array_keys($enabledByPetId))));
		if (!$petIds)
		{
			return 0;
		}

		$changed = 0;

		/** @var UserPets $pet */
		foreach ($this->em()->findByIds('Sylphian\UserPets:UserPets', $petIds) AS $petId => $pet)
		{
			$disabled = empty($enabledByPetId[$petId]);
			if ((bool) $pet->is_disabled === $disabled)
			{
				continue;
			}

			$pet->is_disabled = $disabled;
			$pet->save();
			$changed++;
		}

		return $changed;
	}

	public function findXpLogs(UserPets $pet): Finder
	{
		return $this->getXpLogRepo()->findLogsForPet((int) $pet->pet_id)->with('Actor');
	}

	/**
	 * The member settings service carries the name rules; they do not depend on the user, so
	 * it is built for a guest and loads no pet.
	 */
	protected function getPetSettingsService(): PetSettings
	{
		if ($this->petSettings === null)
		{
			/** @var UserRepository $userRepo */
			$userRepo = $this->repository(UserRepository::class);

			/** @var PetSettings $settings */
			$settings = $this->service(PetSettings::class, $userRepo->getGuestUser());
			$this->petSettings = $settings;
		}

		return $this->petSettings;
	}

	protected function getPetRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository(UserPetsRepository::class);

		return $repo;
	}

	protected function getXpLogRepo(): UserPetsXpLogRepository
	{
		/** @var UserPetsXpLogRepository $repo */
		$repo = $this->repository(UserPetsXpLogRepository::class);

		return $repo;
	}
}

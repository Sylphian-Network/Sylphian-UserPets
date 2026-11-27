<?php

namespace Sylphian\UserPets\Service;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\DisplayName;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
use XF\App;
use XF\Entity\User;
use XF\Phrase;
use XF\PrintableException;
use XF\Service\AbstractService;
use XF\Service\ValidateAndSavableTrait;

/**
 * The member's own pet settings: name, appearance, disabled and level-up sound.
 *
 * Only the values that were set are validated and saved. A name that is submitted unchanged is
 * kept even if it predates the name rules, so changing another setting never forces a rename;
 * such names are only shown through the censor.
 */
class PetSettings extends AbstractService
{
	use ValidateAndSavableTrait;

	/** Length of xf_user_pets.pet_name. */
	public const NAME_COLUMN_LENGTH = 50;

	/**
	 * Letters, combining marks, decimal digits, spaces and - _ ' . only. Variation selectors
	 * and the marks for symbols are combining marks too, but they build emoji (keycaps, emoji
	 * presentation), so they are excluded.
	 */
	public const NAME_PATTERN = '/^(?:(?![\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{20D0}-\x{20FF}])[\p{L}\p{Mn}\p{Mc}\p{Nd} _\'.\-])+$/u';

	protected User $user;

	protected ?UserPets $pet = null;

	protected ?string $petName = null;

	protected ?string $spritesheet = null;

	protected ?bool $disabled = null;

	protected ?bool $soundEnabled = null;

	protected bool $appearanceRequired = false;

	/**
	 * @var array<string,array{filename:string,title:string,url:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int}>|null
	 */
	protected ?array $appearanceChoices = null;

	public function __construct(App $app, User $user)
	{
		$this->user = $user;

		parent::__construct($app);
	}

	protected function setup()
	{
		$userId = (int) $this->user->user_id;
		$this->pet = $userId ? $this->getPetRepo()->getUserPet($userId) : null;
	}

	public function getUser(): User
	{
		return $this->user;
	}

	public function getPet(): ?UserPets
	{
		return $this->pet;
	}

	public function setPetName(string $name): void
	{
		$name = $this->normalizePetName($name);
		$this->petName = $name === $this->getStoredPetName() ? null : $name;
	}

	/**
	 * @param string $filename '' leaves the appearance unchanged
	 */
	public function setSpritesheet(string $filename): void
	{
		$filename = trim($filename);
		$this->spritesheet = $filename === '' ? null : $filename;
	}

	public function setDisabled(bool $disabled): void
	{
		$this->disabled = $disabled;
	}

	public function setSoundEnabled(bool $enabled): void
	{
		$this->soundEnabled = $enabled;
	}

	/**
	 * Registration: an appearance must be chosen unless no sheet is available at all.
	 */
	public function setAppearanceRequired(bool $required): void
	{
		$this->appearanceRequired = $required;
	}

	public function normalizePetName(string $name): string
	{
		$collapsed = preg_replace('/[\s\p{Z}]+/u', ' ', $name);

		return trim($collapsed ?? $name);
	}

	public function getNameMaxLength(): int
	{
		$max = (int) \XF::options()->sylphian_userpets_name_max_length;

		return ($max < 1 || $max > self::NAME_COLUMN_LENGTH) ? self::NAME_COLUMN_LENGTH : $max;
	}

	/**
	 * @param string $name A normalized name; '' means the default name and is valid
	 */
	public function getPetNameError(string $name): ?Phrase
	{
		if ($name === '')
		{
			return null;
		}

		$maxLength = $this->getNameMaxLength();
		if (utf8_strlen($name) > $maxLength)
		{
			return \XF::phrase('sylphian_userpets_error_name_too_long', ['max' => $maxLength]);
		}

		if ($this->containsLink($name))
		{
			return \XF::phrase('sylphian_userpets_error_name_link');
		}

		if ($this->app->stringFormatter()->censorText($name) !== $name)
		{
			return \XF::phrase('sylphian_userpets_error_name_censored');
		}

		if (preg_match(self::NAME_PATTERN, $name) !== 1)
		{
			return \XF::phrase('sylphian_userpets_error_name_characters');
		}

		return null;
	}

	/**
	 * Every sheet whose file exists, in index order, keyed by file name.
	 *
	 * @return array<string,array{filename:string,title:string,url:string,frame_width:int,frame_height:int,frames_per_animation:int,fps:int}>
	 */
	public function getAppearanceChoices(): array
	{
		if ($this->appearanceChoices !== null)
		{
			return $this->appearanceChoices;
		}

		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');

		$choices = [];
		foreach ($spritesheetRepo->buildIndexList() AS $file)
		{
			$filename = (string) ($file['name'] ?? '');
			if ($filename === '' || empty($file['exists']))
			{
				continue;
			}

			$entity = $file['entity'] ?? null;
			$title = trim((string) ($file['title'] ?? ''));

			$choices[$filename] = [
				'filename' => $filename,
				'title' => $title !== '' ? $title : $filename,
				'url' => (string) ($file['url'] ?? $spritesheetRepo->getFileUrl($filename)),
				'frame_width' => (int) ($entity?->frame_width ?: UserPetsRepository::DEFAULT_FRAME_SIZE),
				'frame_height' => (int) ($entity?->frame_height ?: UserPetsRepository::DEFAULT_FRAME_SIZE),
				'frames_per_animation' => (int) ($entity?->frames_per_animation ?: UserPetsRepository::DEFAULT_FRAMES_PER_ANIMATION),
				'fps' => (int) ($entity?->fps ?: UserPetsRepository::DEFAULT_FPS),
			];
		}

		$this->appearanceChoices = $choices;

		return $choices;
	}

	public function isAvailableSpritesheet(string $filename): bool
	{
		return $filename !== '' && isset($this->getAppearanceChoices()[$filename]);
	}

	/**
	 * @return string The pet's own sheet if it is still available, otherwise ''
	 */
	public function getSelectedSpritesheet(): string
	{
		$own = $this->pet ? (string) $this->pet->spritesheet : '';

		return $this->isAvailableSpritesheet($own) ? $own : '';
	}

	/**
	 * @return string The sheet the pet is drawn from before evolution: its own choice or the default
	 */
	public function getCurrentSpritesheet(): string
	{
		$selected = $this->getSelectedSpritesheet();
		if ($selected !== '')
		{
			return $selected;
		}

		return (string) ($this->getPetRepo()->getRenderConfigForPet(null)['filename'] ?? '');
	}

	/**
	 * @return array{pet_name:string,default_name:string,is_disabled:bool,sound_enabled:bool,name_max_length:int,selected_spritesheet:string,current_spritesheet:string}
	 */
	public function getFormValues(): array
	{
		$petName = $this->getStoredPetName();

		return [
			'pet_name' => $petName,
			'default_name' => DisplayName::renderUsernamePhrase('sylphian_userpets_widget_title', $this->user->username),
			'is_disabled' => $this->pet ? (bool) $this->pet->is_disabled : false,
			'sound_enabled' => $this->pet ? (bool) $this->pet->sound_enabled : true,
			'name_max_length' => $this->getNameMaxLength(),
			'selected_spritesheet' => $this->getSelectedSpritesheet(),
			'current_spritesheet' => $this->getCurrentSpritesheet(),
		];
	}

	protected function getStoredPetName(): string
	{
		return $this->pet ? (string) $this->pet->pet_name : '';
	}

	protected function containsLink(string $name): bool
	{
		if (stripos($name, '://') !== false || stripos($name, 'www.') !== false)
		{
			return true;
		}

		$escaped = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

		return $this->app->stringFormatter()->autoLinkStructuredText($escaped) !== $escaped;
	}

	protected function _validate(): array
	{
		$errors = [];

		if ($this->petName !== null)
		{
			$nameError = $this->getPetNameError($this->petName);
			if ($nameError)
			{
				$errors['pet_name'] = $nameError;
			}
		}

		if ($this->spritesheet !== null)
		{
			if (!$this->isAvailableSpritesheet($this->spritesheet))
			{
				$errors['spritesheet'] = \XF::phrase('sylphian_userpets_error_choose_appearance');
			}
		}
		else if ($this->appearanceRequired && $this->getAppearanceChoices() && $this->getSelectedSpritesheet() === '')
		{
			$errors['spritesheet'] = \XF::phrase('sylphian_userpets_error_choose_appearance');
		}

		return $errors;
	}

	/**
	 * @throws PrintableException If the pet row could not be created or saved
	 */
	protected function _save(): UserPets
	{
		$userId = (int) $this->user->user_id;
		if (!$userId)
		{
			throw new \LogicException('Pet settings can only be saved for a saved user.');
		}

		$pet = $this->pet ?? $this->getPetRepo()->getOrCreatePet($userId);
		if (!$pet->pet_id)
		{
			throw new PrintableException(\XF::phrase('sylphian_userpets_action_error'));
		}

		if ($this->petName !== null)
		{
			$pet->pet_name = $this->petName;
		}
		if ($this->spritesheet !== null)
		{
			$pet->spritesheet = $this->spritesheet;
		}
		if ($this->disabled !== null)
		{
			$pet->is_disabled = $this->disabled;
		}
		if ($this->soundEnabled !== null)
		{
			$pet->sound_enabled = $this->soundEnabled;
		}

		$pet->save();

		$this->pet = $pet;

		if (isset($this->user->structure()->relations['UserPet']))
		{
			$this->user->hydrateRelation('UserPet', $pet);
		}

		return $pet;
	}

	protected function getPetRepo(): UserPetsRepository
	{
		/** @var UserPetsRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPets');

		return $repo;
	}
}

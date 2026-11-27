<?php

namespace Sylphian\UserPets\XF\Pub\Controller;

use Sylphian\UserPets\Service\PetSettings;
use XF\ConnectedAccount\ProviderData\AbstractProviderData;
use XF\Entity\User;
use XF\Mvc\Reply\View;
use XF\Service\User\RegistrationService;

/**
 * Extends XF\Pub\Controller\RegisterController: the appearance picker on the registration
 * forms when sylphian_userpets_reg_sprite_req is on, and the choice on the new user's pet.
 */
class Register extends XFCP_Register
{
	/** Input name of the picker in the register_form and register_connected_account modifications. */
	public const SYL_USERPETS_INPUT = 'sylphian_userpets_spritesheet';

	/** The validated choice, kept between setup and finalize of one registration. */
	protected string $sylUserPetsSpritesheet = '';

	public function actionIndex()
	{
		$reply = parent::actionIndex();

		return $this->addSylUserPetsAppearances($reply);
	}

	protected function getConnectedRegisterResponse(array $viewParams)
	{
		$reply = parent::getConnectedRegisterResponse($viewParams);

		return $this->addSylUserPetsAppearances($reply);
	}

	protected function setupRegistration(array $input)
	{
		$registration = parent::setupRegistration($input);
		$this->validateSylUserPetsAppearance($registration);

		return $registration;
	}

	protected function setupConnectedRegistration(array $input, AbstractProviderData $providerData)
	{
		$registration = parent::setupConnectedRegistration($input, $providerData);
		$this->validateSylUserPetsAppearance($registration);

		return $registration;
	}

	protected function finalizeRegistration(User $user)
	{
		parent::finalizeRegistration($user);

		$this->saveSylUserPetsAppearance($user);
	}

	protected function isSylUserPetsAppearanceRequired(): bool
	{
		return (bool) $this->options()->sylphian_userpets_reg_sprite_req;
	}

	/**
	 * @template T
	 * @param T $reply
	 * @return T
	 */
	protected function addSylUserPetsAppearances($reply)
	{
		if ($reply instanceof View && $this->isSylUserPetsAppearanceRequired())
		{
			/** @var PetSettings $settings */
			$settings = $this->service(PetSettings::class, \XF::visitor());
			$reply->setParam('sylUserPetsAppearances', $settings->getAppearanceChoices());
		}

		return $reply;
	}

	/**
	 * Errors go on the new user entity, so RegistrationService::validate() reports them with
	 * the other registration errors and nothing is saved.
	 */
	protected function validateSylUserPetsAppearance(RegistrationService $registration): void
	{
		$this->sylUserPetsSpritesheet = '';

		if (!$this->isSylUserPetsAppearanceRequired())
		{
			return;
		}

		$user = $registration->getUser();
		$filename = trim($this->filter(self::SYL_USERPETS_INPUT, 'str'));

		/** @var PetSettings $settings */
		$settings = $this->service(PetSettings::class, $user);
		$settings->setAppearanceRequired(true);
		$settings->setSpritesheet($filename);

		if (!$settings->validate($errors))
		{
			$user->error(reset($errors), self::SYL_USERPETS_INPUT);

			return;
		}

		$this->sylUserPetsSpritesheet = $filename;
	}

	/**
	 * The account exists by now, so a failure here is logged and never stops the registration.
	 */
	protected function saveSylUserPetsAppearance(User $user): void
	{
		$filename = $this->sylUserPetsSpritesheet;
		$this->sylUserPetsSpritesheet = '';

		if ($filename === '' || !$user->user_id)
		{
			return;
		}

		try
		{
			/** @var PetSettings $settings */
			$settings = $this->service(PetSettings::class, $user);
			$settings->setSpritesheet($filename);
			if ($settings->validate())
			{
				$settings->save();
			}
		}
		catch (\Exception $e)
		{
			\XF::logException($e, false, 'UserPets: could not store the appearance chosen at registration: ');
		}
	}
}

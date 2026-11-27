<?php

namespace Sylphian\UserPets\XF\Pub\Controller;

use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Service\PetSettings;
use XF\Entity\User;
use XF\Mvc\Reply\AbstractReply;

/**
 * Extends XF\Pub\Controller\AccountController.
 */
class Account extends XFCP_Account
{
	public function actionPet(): AbstractReply
	{
		$visitor = \XF::visitor();
		if (!$visitor->hasPermission(UserPetOptOut::PERMISSION_GROUP, UserPetOptOut::PERMISSION_HAVE_PET))
		{
			return $this->noPermission();
		}

		if ($this->isPost())
		{
			$settings = $this->setupSylUserPetsSettings($visitor);
			if (!$settings->validate($errors))
			{
				return $this->error($errors);
			}

			$settings->save();

			return $this->redirect($this->buildLink('account/pet'));
		}

		/** @var PetSettings $settings */
		$settings = $this->service(PetSettings::class, $visitor);
		$values = $settings->getFormValues();

		$viewParams = [
			'petName' => $values['pet_name'],
			'defaultName' => $values['default_name'],
			'nameMaxLength' => $values['name_max_length'],
			'isDisabled' => $values['is_disabled'],
			'soundEnabled' => $values['sound_enabled'],
			'appearances' => $settings->getAppearanceChoices(),
			'selectedSpritesheet' => $values['selected_spritesheet'],
			'currentSpritesheet' => $values['current_spritesheet'],
		];

		$view = $this->view('Sylphian\UserPets:Account\Pet', 'sylphian_userpets_account_pet', $viewParams);

		return $this->addAccountWrapperParams($view, 'sylphian_userpets_pet');
	}

	protected function setupSylUserPetsSettings(User $visitor): PetSettings
	{
		$input = $this->filter([
			'pet_name' => 'str',
			'spritesheet' => 'str',
			'is_disabled' => 'bool',
			'sound_enabled' => 'bool',
		]);

		/** @var PetSettings $settings */
		$settings = $this->service(PetSettings::class, $visitor);
		$settings->setPetName($input['pet_name']);
		$settings->setSpritesheet($input['spritesheet']);
		$settings->setDisabled($input['is_disabled']);
		$settings->setSoundEnabled($input['sound_enabled']);

		return $settings;
	}
}

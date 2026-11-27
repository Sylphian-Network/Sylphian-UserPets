<?php

namespace Sylphian\UserPets\Admin\Controller;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Service\PetAdmin;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Pets extends AbstractController
{
	public const ADMIN_PERMISSION = 'syl_userpetsPets';

	public const PER_PAGE = 20;

	public const HISTORY_PER_PAGE = 50;

	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission(self::ADMIN_PERMISSION);
		$this->setSectionContext('sylphian_userpets_pets');
	}

	public function actionIndex()
	{
		$service = $this->getPetAdminService();

		$username = $this->filter('username', 'str');
		$order = $service->normalizeOrder($this->filter('order', 'str'));
		$direction = $service->normalizeDirection($this->filter('direction', 'str'));

		$page = $this->filterPage();
		$finder = $service->findPetsForList($username, $order, $direction)->limitByPage($page, self::PER_PAGE);
		$total = $finder->total();

		$linkParams = array_filter([
			'username' => $username,
			'order' => $order !== PetAdmin::DEFAULT_ORDER ? $order : '',
			'direction' => $direction !== 'desc' ? $direction : '',
		], 'strlen');

		return $this->view('Sylphian\UserPets:Pets\Listing', 'sylphian_userpets_pets_list', [
			'pets' => $finder->fetch(),
			'total' => $total,
			'page' => $page,
			'perPage' => self::PER_PAGE,
			'username' => $username,
			'order' => $order,
			'direction' => $direction,
			'linkParams' => $linkParams,
		]);
	}

	public function actionEdit()
	{
		$pet = $this->assertPetExists($this->filter('pet_id', 'uint'));

		$service = $this->getPetAdminService();

		return $this->view('Sylphian\UserPets:Pets\Edit', 'sylphian_userpets_pets_edit', [
			'pet' => $pet,
			'appearanceChoices' => $service->getAppearanceChoices((string) $pet->spritesheet),
			'nameMaxLength' => $service->getNameMaxLength(),
		]);
	}

	public function actionSave()
	{
		$this->assertPostOnly();

		$pet = $this->assertPetExists($this->filter('pet_id', 'uint'));

		$errors = $this->getPetAdminService()->updatePet($pet, $this->filter([
			'pet_name' => 'str',
			'spritesheet' => 'str',
			'is_disabled' => 'bool',
			'sound_enabled' => 'bool',
		]));
		if ($errors)
		{
			return $this->error($errors);
		}

		return $this->redirect($this->buildLink('sylphian_userpets/pets/edit', null, ['pet_id' => $pet->pet_id]));
	}

	public function actionSetXp()
	{
		$pet = $this->assertPetExists($this->filter('pet_id', 'uint'));

		if ($this->isPost())
		{
			$saved = $this->getPetAdminService()->setExperience(
				$pet,
				$this->filter('experience', 'int'),
				(int) \XF::visitor()->user_id,
				$error
			);
			if ($error)
			{
				return $this->error($error);
			}
			if (!$saved)
			{
				return $this->error(\XF::phrase('sylphian_userpets_pet_could_not_be_saved'));
			}

			return $this->redirect($this->buildLink('sylphian_userpets/pets/edit', null, ['pet_id' => $pet->pet_id]));
		}

		return $this->view('Sylphian\UserPets:Pets\SetXp', 'sylphian_userpets_pets_set_xp', [
			'pet' => $pet,
			'maxExperience' => PetAdmin::MAX_EXPERIENCE,
		]);
	}

	public function actionReset()
	{
		$pet = $this->assertPetExists($this->filter('pet_id', 'uint'));

		if ($this->isPost())
		{
			if (!$this->getPetAdminService()->resetPet($pet, (int) \XF::visitor()->user_id))
			{
				return $this->error(\XF::phrase('sylphian_userpets_pet_could_not_be_saved'));
			}

			return $this->redirect($this->buildLink('sylphian_userpets/pets/edit', null, ['pet_id' => $pet->pet_id]));
		}

		return $this->view('Sylphian\UserPets:Pets\Reset', 'sylphian_userpets_pets_reset', [
			'pet' => $pet,
		]);
	}

	public function actionHistory()
	{
		$pet = $this->assertPetExists($this->filter('pet_id', 'uint'));

		$page = $this->filterPage();
		$finder = $this->getPetAdminService()->findXpLogs($pet)->limitByPage($page, self::HISTORY_PER_PAGE);
		$total = $finder->total();

		return $this->view('Sylphian\UserPets:Pets\History', 'sylphian_userpets_pets_history', [
			'pet' => $pet,
			'logs' => $finder->fetch(),
			'total' => $total,
			'page' => $page,
			'perPage' => self::HISTORY_PER_PAGE,
		]);
	}

	public function actionToggle()
	{
		$this->assertPostOnly();

		$this->getPetAdminService()->setEnabledStates($this->filter('enabled', 'array-bool'));

		return $this->message(\XF::phrase('your_changes_have_been_saved'));
	}

	protected function assertPetExists(int $petId): UserPets
	{
		/** @var UserPets $pet */
		$pet = $this->assertRecordExists(
			'Sylphian\UserPets:UserPets',
			$petId,
			['User'],
			'sylphian_userpets_requested_pet_not_found'
		);

		return $pet;
	}

	protected function getPetAdminService(): PetAdmin
	{
		/** @var PetAdmin $service */
		$service = $this->service(PetAdmin::class);

		return $service;
	}
}

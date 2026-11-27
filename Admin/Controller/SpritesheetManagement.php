<?php

namespace Sylphian\UserPets\Admin\Controller;

use Sylphian\UserPets\Entity\UserPetsSpritesheet;
use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

class SpritesheetManagement extends AbstractController
{
	public const ADMIN_PERMISSION = 'syl_userpetsSpritesheets';

	protected function preDispatchController($action, ParameterBag $params): void
	{
		$this->assertAdminPermission(self::ADMIN_PERMISSION);
		$this->setSectionContext('sylphian_userpets_spritesheet_management');
	}

	protected function repo(): UserPetsSpritesheetRepository
	{
		/** @var UserPetsSpritesheetRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');
		return $repo;
	}

	public function actionIndex(): View
	{
		$repo = $this->repo();
		$health = $repo->getPathHealth();

		return $this->view(
			'Sylphian\UserPets:SpritesheetManagement\\Index',
			'sylphian_userpets_spritesheets_list',
			[
				'files' => $repo->buildIndexList(),
				'baseUrl' => $repo->getBaseUrl(),
				'pathHealth' => $health['dirs'],
				'hasPathIssues' => $health['hasIssues'],
			]
		);
	}

	public function actionAdd(): View
	{
		/** @var UserPetsSpritesheet $entity */
		$entity = $this->em()->create('Sylphian\UserPets:UserPetsSpritesheet');

		return $this->view(
			'Sylphian\\UserPets:SpritesheetManagement\\Add',
			'sylphian_userpets_spritesheets_add',
			[
				'entity'  => $entity,
				'baseUrl' => $this->repo()->getBaseUrl(),
			]
		);
	}

	public function actionEdit(): View|Error
	{
		$filename = $this->filter('file', 'str');
		if (trim($filename) === '')
		{
			return $this->error(\XF::phrase('sylphian_userpets_no_file_specified'));
		}

		$data = $this->repo()->prepareEditData($filename);
		if (!$data)
		{
			return $this->error(\XF::phrase('sylphian_userpets_error_invalid_file_path'));
		}

		return $this->view(
			'Sylphian\\UserPets:SpritesheetManagement\\Edit',
			'sylphian_userpets_spritesheets_edit',
			[
				'entity'   => $data['entity'],
				'filename' => $data['entity']->filename,
				'fileUrl'  => $data['fileUrl'],
				'exists'   => $data['exists'],
			]
		);
	}

	public function actionSave(): Redirect|Error
	{
		$this->assertPostOnly();

		$input = $this->filter([
			'filename'              => 'str',
			'title'                 => 'str',
			'frame_width'           => 'uint',
			'frame_height'          => 'uint',
			'frames_per_animation'  => 'uint',
			'fps'                   => 'uint',
		]);

		$result = $this->repo()->saveSpritesheet(
			$this->filter('spritesheet_id', 'uint'),
			$this->filter('file', 'str'),
			$input,
			$this->request()->getFile('image', false)
		);

		if (!empty($result['errors']))
		{
			return $this->error($result['errors']);
		}

		return $this->redirect($this->buildLink('sylphian_userpets-spritesheets'));
	}

	public function actionDelete(): View|Redirect|Error
	{
		$filename = $this->filter('file', 'str');
		if (trim($filename) === '')
		{
			return $this->error(\XF::phrase('sylphian_userpets_no_file_specified'));
		}

		if (!$this->isPost())
		{
			$data = $this->repo()->prepareDeleteData($filename);
			if (!$data)
			{
				return $this->error(\XF::phrase('sylphian_userpets_error_invalid_file_path'));
			}

			return $this->view(
				'Sylphian\\UserPets:SpritesheetManagement\\Delete',
				'sylphian_userpets_spritesheets_delete',
				$data
			);
		}

		$errors = $this->repo()->deleteByFilename($filename);
		if ($errors)
		{
			return $this->error($errors);
		}

		return $this->redirect($this->buildLink('sylphian_userpets-spritesheets'));
	}
}

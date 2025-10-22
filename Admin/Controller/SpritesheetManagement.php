<?php

namespace Sylphian\UserPets\Admin\Controller;

use Sylphian\UserPets\Entity\UserPetsSpritesheet;
use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
use XF\Admin\Controller\AbstractController;
use XF\Http\Upload;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

class SpritesheetManagement extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params): void
	{
		$this->assertAdminPermission('syl_userpetsSpritesheets');
	}

	protected function repo(): UserPetsSpritesheetRepository
	{
		/** @var UserPetsSpritesheetRepository $repo */
		$repo = $this->repository('Sylphian\UserPets:UserPetsSpritesheetRepository');
		return $repo;
	}

	public function actionIndex(): View
	{
		$repo  = $this->repo();
		$files = $repo->buildIndexList();
		return $this->view(
			'Sylphian\UserPets:SpritesheetManagement\\Index',
			'sylphian_userpets_spritesheets_list',
			[
				'files'   => $files,
				'baseUrl' => $repo->getBaseUrl(),
			]
		);
	}

	public function actionAdd(): View
	{
		$repo = $this->repo();

		/** @var UserPetsSpritesheet $entity */
		$entity = $this->em()->create('Sylphian\UserPets:UserPetsSpritesheet');
		$entity->filename = '';
		$entity->title = '';
		$entity->frame_width = 192;
		$entity->frame_height = 192;
		$entity->frames_per_animation = 4;
		$entity->fps = 4;
		$entity->last_modified = 0;

		return $this->view(
			'Sylphian\\UserPets:SpritesheetManagement\\Add',
			'sylphian_userpets_spritesheets_add',
			[
				'entity'  => $entity,
				'baseUrl' => $repo->getBaseUrl(),
			]
		);
	}

	public function actionEdit(): View|Error
	{
		$filename = $this->filter('file', 'str');
		if ($filename === '')
		{
			return $this->error('No file specified.');
		}

		$repo = $this->repo();
		$data = $repo->prepareEditData($filename);

		return $this->view(
			'Sylphian\\UserPets:SpritesheetManagement\\Edit',
			'sylphian_userpets_spritesheets_edit',
			[
				'entity'   => $data['entity'],
				'filename' => $filename,
				'fileUrl'  => $data['fileUrl'],
				'exists'   => $data['exists'],
			]
		);
	}

	public function actionSave(): Redirect|Error
	{
		$this->assertPostOnly();

		$repo = $this->repo();

		$id            = (int) $this->filter('spritesheet_id', 'uint');
		$origFilename  = (string) $this->filter('file', 'str');
		$input         = $this->filter([
			'filename'              => 'str',
			'title'                 => 'str',
			'frame_width'           => 'uint',
			'frame_height'          => 'uint',
			'frames_per_animation'  => 'uint',
			'fps'                   => 'uint',
		]);

		/** @var Upload|null $upload */
		$upload = $this->request()->getFile('image', false);

		$result = $repo->saveSpritesheet($id, $origFilename, $input, $upload);
		if (!empty($result['error']))
		{
			return $this->error($result['error']);
		}

		return $this->redirect($this->buildLink('sylphian_userpets/spritesheets'));
	}

	public function actionDelete(): View|Redirect|Error
	{
		$filename = $this->filter('file', 'str');
		if (!$this->isPost())
		{
			$repo = $this->repo();
			return $this->view(
				'Sylphian\\UserPets:SpritesheetManagement\\Delete',
				'sylphian_userpets_spritesheets_delete',
				[
					'filename' => $filename,
					'exists'   => is_file($repo->getBasePath() . DIRECTORY_SEPARATOR . $filename),
					'url'      => $repo->getBaseUrl() . '/' . rawurlencode($filename),
					'entity'   => $this->repo()->findByFilename($filename),
				]
			);
		}

		$result = $this->repo()->deleteByFilename($filename);
		if ($result !== true)
		{
			return $this->error((string) $result);
		}
		return $this->redirect($this->buildLink('sylphian_userpets/spritesheets'));
	}
}

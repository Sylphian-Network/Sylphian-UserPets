<?php

namespace Sylphian\UserPets;

use Sylphian\Library\Install\SylInstallHelperTrait;
use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Repository\UserPetsRepository;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;
	use SylInstallHelperTrait;

	public function installStep1(): void
	{
		$this->schemaManager()->createTable('xf_user_pets', function (Create $table)
		{
			$table->addColumn('pet_id', 'int')->autoIncrement();
			$table->addColumn('user_id', 'int')->nullable(false);
			$table->addColumn('level', 'int')->setDefault(1);
			$table->addColumn('experience', 'int')->setDefault(0);
			$table->addColumn('hunger', 'int')->setDefault(100);
			$table->addColumn('sleepiness', 'int')->setDefault(100);
			$table->addColumn('happiness', 'int')->setDefault(100);
			$table->addColumn('state', 'varchar', 30)->setDefault('idle');
			$table->addColumn('last_update', 'int')->setDefault(\XF::$time);
			$table->addColumn('last_action_time', 'int')->setDefault(0);
			$table->addColumn('created_at', 'int')->setDefault(\XF::$time);

			$table->addPrimaryKey('pet_id');
			$table->addKey('user_id');
		});
	}

	public function installStep2(): void
	{
		try
		{
			/** @var UserPetsRepository $repository */
			$repository = $this->app()->repository('Sylphian\UserPets:UserPets');
			$spriteSheets = $repository->getAvailableSpriteSheets();

			$this->createUserField(
				'syl_userpets_spritesheet',
				'Sprite Sheet',
				'The sprite sheet image used for your pet.',
				[
					'field_type' => 'select',
					'field_choices' => $spriteSheets,
					'display_group' => 'preferences',
					'display_order' => 500,
					'required' => true,
					'user_editable' => 'yes',
					'show_registration' => false,
					'viewable_profile' => false,
					'viewable_message' => false,
				]
			);

		}
		catch (\Exception $e)
		{
			Logger::error('Unexpected error in installStep2', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
		}
	}

	public function installStep3(): void
	{
		$this->schemaManager()->createTable('xf_user_pets_tutorials', function (Create $table)
		{
			$table->addColumn('tutorial_id', 'int')->autoIncrement();
			$table->addColumn('user_id', 'int')->nullable(false);
			$table->addColumn('tutorial_key', 'varchar', 50)->nullable(false);
			$table->addColumn('completed', 'bool')->setDefault(0);
			$table->addColumn('completed_date', 'int')->nullable(true);

			$table->addPrimaryKey('tutorial_id');
			$table->addUniqueKey(['user_id', 'tutorial_key'], 'user_tutorial');
			$table->addKey('user_id');
			$table->addKey(['user_id', 'completed'], 'user_completed');
		});
	}

	public function uninstallStep1(): void
	{
		$this->schemaManager()->dropTable('xf_user_pets');
	}

	public function uninstallStep2(): void
	{
		$this->removeUserField('syl_userpets_spritesheet');
	}

	public function uninstallStep3(): void
	{
		$this->schemaManager()->dropTable('xf_user_pets_tutorials');
	}
}

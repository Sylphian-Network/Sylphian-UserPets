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
			$table->addColumn('last_duel_time', 'int')->setDefault(0);
			$table->addColumn('created_at', 'int')->setDefault(\XF::$time);

			$table->addPrimaryKey('pet_id');
			$table->addKey('user_id');
		});
	}

	public function installStep2(): void
	{
		//TODO: This needs to read the spritesheets available rather than hardcoding them on installation.

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

	public function installStep4(): void
	{
		try
		{
			$this->createUserField(
				'syl_userpets_custom_name',
				'Custom pet name',
				"Write your custom name into this field, if you don't want a custom name set this to blank.",
				[
					'field_type' => 'textbox',
					'display_group' => 'preferences',
					'display_order' => 501,
					'max_length' => 15,
					'user_editable' => 'yes',
					'required' => false,
					'show_registration' => false,
					'viewable_profile' => false,
					'viewable_message' => false,
				]
			);

		}
		catch (\Exception $e)
		{
			Logger::error('Unexpected error in installStep4', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
		}
	}

	public function installStep5(): void
	{
		$this->schemaManager()->createTable('xf_user_pets_duels', function (Create $table)
		{
			$table->addColumn('duel_id', 'int')->autoIncrement();
			$table->addColumn('challenger_pet_id', 'int')->nullable(false);
			$table->addColumn('opponent_pet_id', 'int')->nullable(false);
			$table->addColumn('status', 'enum')->values(['pending', 'accepted', 'declined', 'completed'])->setDefault('pending');
			$table->addColumn('winner_pet_id', 'int')->setDefault(0);
			$table->addColumn('loser_pet_id', 'int')->setDefault(0);
			$table->addColumn('created_at', 'int')->setDefault(\XF::$time);
			$table->addColumn('completed_at', 'int')->setDefault(0);

			$table->addPrimaryKey('duel_id');
			$table->addKey(['challenger_pet_id', 'opponent_pet_id'], 'challenger_opponent');
			$table->addKey('status');
		});
	}

	public function installStep6(): void
	{
		$this->schemaManager()->createTable('xf_user_pets_spritesheet', function (Create $table)
		{
			$table->addColumn('spritesheet_id', 'int')->autoIncrement();
			$table->addColumn('filename', 'varchar', 255);
			$table->addColumn('title', 'varchar', 100)->setDefault('');
			$table->addColumn('frame_width', 'smallint')->setDefault(192);
			$table->addColumn('frame_height', 'smallint')->setDefault(192);
			$table->addColumn('frames_per_animation', 'smallint')->setDefault(4);
			$table->addColumn('fps', 'int')->setDefault(4);
			$table->addColumn('last_modified', 'int')->setDefault(0);

			$table->addUniqueKey('filename');
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

	public function uninstallStep4(): void
	{
		$this->removeUserField('syl_userpets_custom_name');
	}

	public function uninstallStep5(): void
	{
		$this->schemaManager()->dropTable('xf_user_pets_duels');
	}

	public function uninstallStep6(): void
	{
		$this->schemaManager()->dropTable('xf_user_pets_spritesheet');
	}
}

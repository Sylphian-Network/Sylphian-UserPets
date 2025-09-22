<?php

namespace Sylphian\UserPets;

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

    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_user_pets', function (Create $table)
        {
            $table->addColumn('pet_id', 'int')->autoIncrement();
            $table->addColumn('user_id', 'int')->nullable(false);
            $table->addColumn('hunger', 'int')->setDefault(100);
            $table->addColumn('sleepiness', 'int')->setDefault(100);
            $table->addColumn('happiness', 'int')->setDefault(100);
            $table->addColumn('state', 'varchar', 20)->setDefault('idle');
            $table->addColumn('last_update', 'int')->setDefault(\XF::$time);
            $table->addColumn('last_action_time', 'int')->setDefault(0);
            $table->addColumn('created_at', 'int')->setDefault(\XF::$time);

            $table->addPrimaryKey('pet_id');
            $table->addKey('user_id');
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_user_pets');
    }
}
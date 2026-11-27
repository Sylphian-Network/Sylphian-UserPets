<?php

namespace Sylphian\UserPets;

use Sylphian\Library\Install\CustomFieldInstallTrait;
use Sylphian\UserPets\Duel\AlgorithmRegistry;
use Sylphian\UserPets\Repository\UserPetsDuelRepository;
use Sylphian\UserPets\Repository\UserPetsRepository;
use Sylphian\UserPets\Repository\UserPetsSpritesheetRepository;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Entity\UserField;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;
	use CustomFieldInstallTrait;

	public const USER_FIELD_SPRITESHEET = 'syl_userpets_spritesheet';
	public const USER_FIELD_CUSTOM_NAME = 'syl_userpets_custom_name';
	public const USER_FIELD_DISABLE_PET = 'syl_userpets_disable_pet';

	/** Index this add-on adds to a core table when no index starting with the column exists. */
	public const SOLUTION_USER_INDEX = 'syl_userpets_solution_user';

	/** Written by the legacy XP correction tool when it finishes. */
	public const LEGACY_XP_REGISTRY_KEY = 'sylUserPetsLegacyXp';

	/** When the site upgraded to 1.5.0; awards after it already used the configured amounts. */
	public const LEGACY_XP_UNTIL_REGISTRY_KEY = 'sylUserPetsLegacyXpUntil';

	public function installStep1(): void
	{
		$sm = $this->schemaManager();
		foreach ($this->getTables() AS $tableName => $closure)
		{
			$sm->createTable($tableName, $closure);
		}
	}

	public function installStep2(): void
	{
		$this->ensureSolutionUserIndex();
	}

	/**
	 * User fields are created after the add-on data import, so their title, description and choice
	 * phrases (shipped by this add-on) already exist and keep their add-on ownership.
	 */
	public function postInstall(array &$stateChanges): void
	{
		$this->ensureUserFields();
	}

	/*
	 * Steps for versions before 1.5.0. They only change the schema and are safe to re-run; user fields,
	 * spritesheet choices and the registration requirement are handled by postUpgrade().
	 */

	public function upgrade1010180Step1(): void
	{
		$this->addMissingColumns('xf_user_pets', [
			'solution_reward_count' => 'int',
			'solution_reward_experience' => 'int',
		]);
	}

	public function upgrade1010200Step1(): void
	{
		$this->addMissingColumns('xf_user_pets', [
			'thread_reward_count' => 'int',
			'thread_reward_experience' => 'int',
			'post_reward_count' => 'int',
			'post_reward_experience' => 'int',
		]);
	}

	public function upgrade1010290Step1(): void
	{
		$this->schemaManager()->alterTable('xf_user_pets_duels', function (Alter $table)
		{
			$table->changeColumn('status', 'enum')
				->values(['pending', 'accepted', 'declined', 'cancelled', 'completed'])
				->setDefault('pending');
		});
	}

	public function upgrade1010480Step1(): void
	{
		$this->createMissingTables([
			'xf_user_pets_tournaments',
			'xf_user_pets_tournament_entries',
			'xf_user_pets_tournament_matches',
		]);
	}

	public function upgrade1010520Step1(): void
	{
		if ($this->schemaManager()->columnExists('xf_user_pets_tournaments', 'resolution_mode'))
		{
			return;
		}

		$this->schemaManager()->alterTable('xf_user_pets_tournaments', function (Alter $table)
		{
			$table->addColumn('resolution_mode', 'enum')->values(['manual', 'automatic'])->setDefault('manual');
		});
	}

	public function upgrade1050070Step1(): void
	{
		$this->createMissingTables(array_keys($this->getTables()));

		$this->addMissingColumns('xf_user_pets', [
			'thread_reward_count' => 'int',
			'thread_reward_experience' => 'int',
			'post_reward_count' => 'int',
			'post_reward_experience' => 'int',
			'solution_reward_count' => 'int',
			'solution_reward_experience' => 'int',
		]);
	}

	/**
	 * Earlier versions could create a second pet for a user under concurrent requests. Every code path
	 * used the lowest pet id, so that pet is kept and the others are merged into it.
	 */
	public function upgrade1050070Step2(): void
	{
		$db = $this->db();

		/** @var UserPetsDuelRepository $duelRepo */
		$duelRepo = $this->app()->repository(UserPetsDuelRepository::class);

		$duplicates = $db->fetchAll('
			SELECT user_id, MIN(pet_id) AS kept_pet_id
			FROM xf_user_pets
			GROUP BY user_id
			HAVING COUNT(*) > 1
		');

		foreach ($duplicates AS $row)
		{
			$keptPetId = (int) $row['kept_pet_id'];
			$droppedPetIds = array_map('intval', $db->fetchAllColumn(
				'SELECT pet_id FROM xf_user_pets WHERE user_id = ? AND pet_id <> ?',
				[(int) $row['user_id'], $keptPetId]
			));

			foreach ($droppedPetIds AS $droppedPetId)
			{
				$duelRepo->mergeDuelsIntoPet($droppedPetId, $keptPetId);

				$db->update('xf_user_pets_tournament_entries', ['pet_id' => $keptPetId], 'pet_id = ?', $droppedPetId);
				foreach (['pet_a_id', 'pet_b_id', 'winner_pet_id', 'loser_pet_id'] AS $column)
				{
					$db->update('xf_user_pets_tournament_matches', [$column => $keptPetId], "$column = ?", $droppedPetId);
				}
				$db->update('xf_user_pets_tournaments', ['winner_pet_id' => $keptPetId], 'winner_pet_id = ?', $droppedPetId);

				$db->delete('xf_user_pets', 'pet_id = ?', $droppedPetId);
			}
		}
	}

	public function upgrade1050070Step3(): void
	{
		$sm = $this->schemaManager();

		if ($this->indexExists('xf_user_pets', 'user_id') && !$this->indexIsUnique('xf_user_pets', 'user_id'))
		{
			$sm->alterTable('xf_user_pets', function (Alter $table)
			{
				$table->dropIndexes('user_id');
			});
		}

		$sm->alterTable('xf_user_pets', function (Alter $table)
		{
			if (!$this->indexExists('xf_user_pets', 'user_id'))
			{
				$table->addUniqueKey('user_id');
			}
			if (!$this->indexExists('xf_user_pets', 'experience_level'))
			{
				$table->addKey(['experience', 'level'], 'experience_level');
			}
			if (!$this->indexExists('xf_user_pets', 'level_experience'))
			{
				$table->addKey(['level', 'experience'], 'level_experience');
			}
		});
	}

	public function upgrade1050070Step4(): void
	{
		$sm = $this->schemaManager();

		$sm->alterTable('xf_user_pets_duels', function (Alter $table)
		{
			$keys = [
				'opponent_pet_id_status' => ['opponent_pet_id', 'status'],
				'winner_pet_id_status' => ['winner_pet_id', 'status'],
				'loser_pet_id_status' => ['loser_pet_id', 'status'],
			];
			foreach ($keys AS $name => $columns)
			{
				if (!$this->indexExists('xf_user_pets_duels', $name))
				{
					$table->addKey($columns, $name);
				}
			}
		});

		if (!$this->indexExists('xf_user_pets_tournament_entries', 'user_id'))
		{
			$sm->alterTable('xf_user_pets_tournament_entries', function (Alter $table)
			{
				$table->addKey('user_id');
			});
		}

		$this->ensureSolutionUserIndex();
	}

	/**
	 * Solution XP is recounted from the solved questions themselves; this table was never written.
	 */
	public function upgrade1050070Step5(): void
	{
		$this->schemaManager()->dropTable('xf_user_pets_solution_rewards');
	}

	public function upgrade1050070Step6(): void
	{
		$value = (string) $this->db()->fetchOne(
			'SELECT option_value FROM xf_option WHERE option_id = ?',
			'sylphian_userpets_duel_algorithm'
		);

		if (!\in_array($value, AlgorithmRegistry::getValidKeys(), true))
		{
			$this->db()->update(
				'xf_option',
				['option_value' => AlgorithmRegistry::DEFAULT_KEY],
				'option_id = ?',
				'sylphian_userpets_duel_algorithm'
			);
		}
	}

	/**
	 * Duels left in "accepted" by the old resolver are resolved again; the conditional status change
	 * lets only one run complete each of them.
	 */
	public function upgrade1050070Step7(): void
	{
		$jobManager = $this->app()->jobManager();

		$duelIds = $this->db()->fetchAllColumn(
			"SELECT duel_id FROM xf_user_pets_duels WHERE status = 'accepted'"
		);
		foreach ($duelIds AS $duelId)
		{
			$jobManager->enqueueUnique(
				'sylUserPetsDuel' . $duelId,
				'Sylphian\UserPets:DuelResolve',
				['duel_id' => (int) $duelId],
				false
			);
		}

		$registry = $this->app()->registry();
		if (!$registry->get(self::LEGACY_XP_UNTIL_REGISTRY_KEY))
		{
			$registry->set(self::LEGACY_XP_UNTIL_REGISTRY_KEY, \XF::$time);
		}

		$jobManager->enqueueUnique(
			'sylphianUserPetsRebuildSolutionExperience',
			'Sylphian\UserPets:RebuildSolutionExperience',
			[],
			false
		);
	}

	public function postUpgrade($previousVersion, array &$stateChanges): void
	{
		$this->ensureUserFields();
	}

	public function uninstallStep1(): void
	{
		$this->removeCustomFields(UserField::class, [
			self::USER_FIELD_SPRITESHEET,
			self::USER_FIELD_CUSTOM_NAME,
			self::USER_FIELD_DISABLE_PET,
		]);
	}

	public function uninstallStep2(): void
	{
		$sm = $this->schemaManager();
		foreach (array_keys($this->getTables()) AS $tableName)
		{
			$sm->dropTable($tableName);
		}
		$sm->dropTable('xf_user_pets_solution_rewards');

		if ($this->indexExists('xf_thread_question', self::SOLUTION_USER_INDEX))
		{
			$sm->alterTable('xf_thread_question', function (Alter $table)
			{
				$table->dropIndexes(self::SOLUTION_USER_INDEX);
			});
		}
	}

	public function uninstallStep3(): void
	{
		$db = $this->db();

		$db->delete('xf_user_alert', 'content_type = ?', 'syl_userpet');
		$db->delete(
			'xf_job',
			'execute_class LIKE ?',
			$db->escapeLike('Sylphian\UserPets', '?%')
		);

		$this->app()->registry()->delete([self::LEGACY_XP_REGISTRY_KEY, self::LEGACY_XP_UNTIL_REGISTRY_KEY]);
	}

	/**
	 * @return array<string, \Closure>
	 */
	protected function getTables(): array
	{
		return [
			'xf_user_pets' => function (Create $table)
			{
				$table->addColumn('pet_id', 'int')->autoIncrement();
				$table->addColumn('user_id', 'int');
				$table->addColumn('level', 'int')->setDefault(1);
				$table->addColumn('experience', 'int')->setDefault(0);
				$table->addColumn('hunger', 'int')->setDefault(100);
				$table->addColumn('sleepiness', 'int')->setDefault(100);
				$table->addColumn('happiness', 'int')->setDefault(100);
				$table->addColumn('state', 'varchar', 30)->setDefault('idle');
				$table->addColumn('last_update', 'int')->setDefault(0);
				$table->addColumn('last_action_time', 'int')->setDefault(0);
				$table->addColumn('last_duel_time', 'int')->setDefault(0);
				$table->addColumn('thread_reward_count', 'int')->setDefault(0);
				$table->addColumn('thread_reward_experience', 'int')->setDefault(0);
				$table->addColumn('post_reward_count', 'int')->setDefault(0);
				$table->addColumn('post_reward_experience', 'int')->setDefault(0);
				$table->addColumn('solution_reward_count', 'int')->setDefault(0);
				$table->addColumn('solution_reward_experience', 'int')->setDefault(0);
				$table->addColumn('created_at', 'int')->setDefault(0);

				$table->addPrimaryKey('pet_id');
				$table->addUniqueKey('user_id');
				$table->addKey(['experience', 'level'], 'experience_level');
				$table->addKey(['level', 'experience'], 'level_experience');
			},
			'xf_user_pets_tutorials' => function (Create $table)
			{
				$table->addColumn('tutorial_id', 'int')->autoIncrement();
				$table->addColumn('user_id', 'int');
				$table->addColumn('tutorial_key', 'varchar', 50);
				$table->addColumn('completed', 'bool')->setDefault(0);
				$table->addColumn('completed_date', 'int')->nullable(true);

				$table->addPrimaryKey('tutorial_id');
				$table->addUniqueKey(['user_id', 'tutorial_key'], 'user_tutorial');
				$table->addKey(['user_id', 'completed'], 'user_completed');
			},
			'xf_user_pets_duels' => function (Create $table)
			{
				$table->addColumn('duel_id', 'int')->autoIncrement();
				$table->addColumn('challenger_pet_id', 'int');
				$table->addColumn('opponent_pet_id', 'int');
				$table->addColumn('status', 'enum')->values(['pending', 'accepted', 'declined', 'cancelled', 'completed'])->setDefault('pending');
				$table->addColumn('winner_pet_id', 'int')->setDefault(0);
				$table->addColumn('loser_pet_id', 'int')->setDefault(0);
				$table->addColumn('created_at', 'int')->setDefault(0);
				$table->addColumn('completed_at', 'int')->setDefault(0);

				$table->addPrimaryKey('duel_id');
				$table->addKey(['challenger_pet_id', 'opponent_pet_id'], 'challenger_opponent');
				$table->addKey(['opponent_pet_id', 'status'], 'opponent_pet_id_status');
				$table->addKey(['winner_pet_id', 'status'], 'winner_pet_id_status');
				$table->addKey(['loser_pet_id', 'status'], 'loser_pet_id_status');
				$table->addKey('status');
			},
			'xf_user_pets_spritesheet' => function (Create $table)
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
			},
			'xf_user_pets_tournaments' => function (Create $table)
			{
				$table->addColumn('tournament_id', 'int')->autoIncrement();
				$table->addColumn('title', 'varchar', 100);
				$table->addColumn('description', 'mediumtext');
				$table->addColumn('max_participants', 'smallint')->setDefault(8);
				$table->addColumn('prize_experience', 'int')->setDefault(0);
				$table->addColumn('resolution_mode', 'enum')->values(['manual', 'automatic'])->setDefault('manual');
				$table->addColumn('status', 'enum')->values(['draft', 'registration_open', 'registration_closed', 'in_progress', 'completed', 'cancelled'])->setDefault('draft');
				$table->addColumn('winner_pet_id', 'int')->setDefault(0);
				$table->addColumn('created_user_id', 'int')->setDefault(0);
				$table->addColumn('created_at', 'int')->setDefault(0);
				$table->addColumn('registration_opened_at', 'int')->setDefault(0);
				$table->addColumn('registration_closed_at', 'int')->setDefault(0);
				$table->addColumn('started_at', 'int')->setDefault(0);
				$table->addColumn('completed_at', 'int')->setDefault(0);

				$table->addPrimaryKey('tournament_id');
				$table->addKey('status');
				$table->addKey('winner_pet_id');
			},
			'xf_user_pets_tournament_entries' => function (Create $table)
			{
				$table->addColumn('entry_id', 'int')->autoIncrement();
				$table->addColumn('tournament_id', 'int');
				$table->addColumn('pet_id', 'int');
				$table->addColumn('user_id', 'int');
				$table->addColumn('seed', 'smallint')->setDefault(0);
				$table->addColumn('joined_at', 'int')->setDefault(0);

				$table->addPrimaryKey('entry_id');
				$table->addUniqueKey(['tournament_id', 'pet_id'], 'tournament_pet');
				$table->addUniqueKey(['tournament_id', 'user_id'], 'tournament_user');
				$table->addKey('user_id');
			},
			'xf_user_pets_tournament_matches' => function (Create $table)
			{
				$table->addColumn('match_id', 'int')->autoIncrement();
				$table->addColumn('tournament_id', 'int');
				$table->addColumn('round_number', 'smallint');
				$table->addColumn('match_number', 'smallint');
				$table->addColumn('pet_a_id', 'int')->setDefault(0);
				$table->addColumn('pet_b_id', 'int')->setDefault(0);
				$table->addColumn('winner_pet_id', 'int')->setDefault(0);
				$table->addColumn('loser_pet_id', 'int')->setDefault(0);
				$table->addColumn('status', 'enum')->values(['waiting', 'ready', 'bye', 'completed'])->setDefault('waiting');
				$table->addColumn('resolved_at', 'int')->setDefault(0);

				$table->addPrimaryKey('match_id');
				$table->addUniqueKey(['tournament_id', 'round_number', 'match_number'], 'tournament_round_match');
				$table->addKey(['tournament_id', 'status'], 'tournament_status');
			},
		];
	}

	/**
	 * The titles here are only used when the add-on's own phrases are missing; the shipped phrases
	 * (user_field_title.*, user_field_desc.*, user_field_choice.*) are translatable.
	 */
	protected function ensureUserFields(): void
	{
		/** @var UserPetsRepository $petRepo */
		$petRepo = $this->app()->repository(UserPetsRepository::class);

		$this->ensureUserField(self::USER_FIELD_DISABLE_PET, 'Disable pet', 'A disabled pet is hidden, does not gain XP and cannot take part in duels or tournaments.', [
			'field_type' => 'checkbox',
			'field_choices' => ['disable_option' => 'Disable my pet'],
			'display_order' => 499,
			'match_type' => 'none',
			'match_params' => [],
			'max_length' => 0,
		]);

		$this->ensureUserField(self::USER_FIELD_SPRITESHEET, 'Pet appearance', 'The appearance used for your pet.', [
			'field_type' => 'select',
			'field_choices' => $petRepo->getAvailableSpriteSheets(),
			'display_order' => 500,
		]);

		$this->ensureUserField(self::USER_FIELD_CUSTOM_NAME, 'Custom pet name', 'Give your pet a name. Leave this blank to use the default name.', [
			'field_type' => 'textbox',
			'display_order' => 501,
			'max_length' => 15,
		]);

		$this->repairLegacyDisableChoice();

		/** @var UserPetsSpritesheetRepository $spritesheetRepo */
		$spritesheetRepo = $this->app()->repository(UserPetsSpritesheetRepository::class);
		$spritesheetRepo->syncUserFieldOptions();

		$petRepo->syncSpritesheetRegistrationRequirement(
			(bool) ($this->app()->options()->sylphian_userpets_reg_sprite_req ?? false)
		);
	}

	/**
	 * Versions before 1.5.0 created the field before their phrases were imported, so the stored
	 * choice text was the raw phrase key. Saving the field in the control panel would have copied
	 * that key into the displayed choice. A choice an admin has renamed is left alone.
	 */
	protected function repairLegacyDisableChoice(): void
	{
		/** @var UserField|null $field */
		$field = $this->app()->em()->find(UserField::class, self::USER_FIELD_DISABLE_PET);
		if (!$field)
		{
			return;
		}

		$choices = $field->field_choices;
		if (($choices['disable_option'] ?? null) !== 'sylphian_userpets_user_field_disable_pet_choice')
		{
			return;
		}

		$choices['disable_option'] = 'Disable my pet';
		$field->field_choices = $choices;
		$field->save();
	}

	protected function ensureSolutionUserIndex(): void
	{
		$hasUsableIndex = (bool) $this->db()->fetchOne(
			"SHOW INDEX FROM xf_thread_question WHERE Column_name = 'solution_user_id' AND Seq_in_index = 1"
		);
		if ($hasUsableIndex)
		{
			return;
		}

		$this->schemaManager()->alterTable('xf_thread_question', function (Alter $table)
		{
			$table->addKey('solution_user_id', self::SOLUTION_USER_INDEX);
		});
	}

	/**
	 * @param list<string> $tableNames
	 */
	protected function createMissingTables(array $tableNames): void
	{
		$sm = $this->schemaManager();
		$tables = $this->getTables();

		foreach ($tableNames AS $tableName)
		{
			if (!$sm->tableExists($tableName))
			{
				$sm->createTable($tableName, $tables[$tableName]);
			}
		}
	}

	/**
	 * @param array<string, string> $columns column name => type, all defaulting to 0
	 */
	protected function addMissingColumns(string $tableName, array $columns): void
	{
		$sm = $this->schemaManager();
		$missing = array_filter(
			$columns,
			fn (string $type, string $column) => !$sm->columnExists($tableName, $column),
			ARRAY_FILTER_USE_BOTH
		);
		if (!$missing)
		{
			return;
		}

		$sm->alterTable($tableName, function (Alter $table) use ($missing)
		{
			foreach ($missing AS $column => $type)
			{
				$table->addColumn($column, $type)->setDefault(0);
			}
		});
	}

	protected function indexExists(string $tableName, string $indexName): bool
	{
		return (bool) $this->db()->fetchOne(
			'SHOW INDEX FROM ' . $tableName . ' WHERE Key_name = ?',
			$indexName
		);
	}

	protected function indexIsUnique(string $tableName, string $indexName): bool
	{
		$row = $this->db()->fetchRow(
			'SHOW INDEX FROM ' . $tableName . ' WHERE Key_name = ?',
			$indexName
		);

		return $row && (int) $row['Non_unique'] === 0;
	}
}

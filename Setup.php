<?php

namespace Sylphian\UserPets;

use Sylphian\Library\Install\CustomFieldInstallTrait;
use Sylphian\UserPets\Duel\AlgorithmRegistry;
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

	public const PERMISSION_GROUP = 'sylUserPets';

	/** Granted on install and on the 1.6.0 upgrade to every group that can view the board, as before. */
	public const DEFAULT_PERMISSIONS = ['havePet', 'duel', 'joinTournament'];

	public const SPRITESHEET_DIRECTORY = 'data://assets/sylphian/userpets/spritesheets';

	public const DEFAULT_WIDGET_KEY = 'sylphian_userpets_widget';

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

	public function installStep3(): void
	{
		$this->applyDefaultPermissions();
	}

	public function postInstall(array &$stateChanges): void
	{
		$this->createDefaultWidget();
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
	 *
	 * Plain SQL on purpose: this step runs on the 1.4.8 schema, before later steps add columns that
	 * the current repositories expect. The win/loss counters are counted in upgrade1060070Step4.
	 */
	public function upgrade1050070Step2(): void
	{
		$db = $this->db();

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
				$this->mergeLegacyDuels($droppedPetId, $keptPetId);

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

	public function upgrade1060070Step1(): void
	{
		$this->createMissingTables(['xf_user_pets_xp_log']);

		$sm = $this->schemaManager();

		$sm->alterTable('xf_user_pets', function (Alter $table) use ($sm)
		{
			$missing = fn (string $column) => !$sm->columnExists('xf_user_pets', $column);

			if ($missing('pet_name'))
			{
				$table->addColumn('pet_name', 'varchar', 50)->setDefault('');
			}
			if ($missing('spritesheet'))
			{
				$table->addColumn('spritesheet', 'varchar', 255)->setDefault('');
			}
			if ($missing('is_disabled'))
			{
				$table->addColumn('is_disabled', 'tinyint', 3)->setDefault(0);
			}
			if ($missing('sound_enabled'))
			{
				$table->addColumn('sound_enabled', 'tinyint', 3)->setDefault(1);
			}
			foreach (['duel_wins', 'duel_losses', 'tournament_wins'] AS $column)
			{
				if ($missing($column))
				{
					$table->addColumn($column, 'int')->setDefault(0);
				}
			}
			foreach (['duel_wins', 'duel_losses', 'tournament_wins'] AS $index)
			{
				if (!$this->indexExists('xf_user_pets', $index))
				{
					$table->addKey($index);
				}
			}
		});

		$sm->alterTable('xf_user_pets_spritesheet', function (Alter $table) use ($sm)
		{
			if (!$sm->columnExists('xf_user_pets_spritesheet', 'evolve_level'))
			{
				$table->addColumn('evolve_level', 'smallint')->setDefault(0);
			}
			if (!$sm->columnExists('xf_user_pets_spritesheet', 'evolves_to'))
			{
				$table->addColumn('evolves_to', 'varchar', 255)->setDefault('');
			}
		});

		$sm->alterTable('xf_user_pets_tournaments', function (Alter $table) use ($sm)
		{
			$columns = [
				'registration_open_date' => 'int',
				'registration_close_date' => 'int',
				'start_date' => 'int',
				'round_interval_hours' => 'smallint',
				'next_round_date' => 'int',
				'repeat_days' => 'smallint',
				'repeat_of_id' => 'int',
			];
			foreach ($columns AS $column => $type)
			{
				if (!$sm->columnExists('xf_user_pets_tournaments', $column))
				{
					$table->addColumn($column, $type)->setDefault(0);
				}
			}
			if (!$this->indexExists('xf_user_pets_tournaments', 'repeat_of_id'))
			{
				$table->addKey('repeat_of_id');
			}
		});
	}

	/**
	 * Pet name, appearance and the opt-out move from custom user fields to the pet row. Runs in
	 * batches of users; a user with any of the values set gets a pet row, so a disabled pet stays
	 * disabled. The recount queued in step 5 credits content XP to pets created here.
	 */
	public function upgrade1060070Step2(array $stepParams = []): ?array
	{
		$db = $this->db();
		$fieldIds = [self::USER_FIELD_CUSTOM_NAME, self::USER_FIELD_SPRITESHEET, self::USER_FIELD_DISABLE_PET];
		$position = (int) ($stepParams['position'] ?? 0);

		$userIds = $db->fetchAllColumn(
			'SELECT DISTINCT user_id
			FROM xf_user_field_value
			WHERE field_id IN (' . $db->quote($fieldIds) . ')
				AND user_id > ?
			ORDER BY user_id
			LIMIT 1000',
			$position
		);
		if (!$userIds)
		{
			return null;
		}
		$position = (int) end($userIds);

		// Values left behind by deleted users must not create pets.
		$userIds = $db->fetchAllColumn(
			'SELECT user_id FROM xf_user WHERE user_id IN (' . $db->quote($userIds) . ')'
		);
		$sheetMap = $stepParams['sheetMap'] ?? $this->getLegacySpritesheetChoiceMap();
		if (!$userIds)
		{
			return ['position' => $position, 'sheetMap' => $sheetMap];
		}

		$values = [];
		foreach ($db->fetchAll(
			'SELECT user_id, field_id, field_value
			FROM xf_user_field_value
			WHERE field_id IN (' . $db->quote($fieldIds) . ')
				AND user_id IN (' . $db->quote($userIds) . ')'
		) AS $row)
		{
			$values[(int) $row['user_id']][$row['field_id']] = (string) $row['field_value'];
		}

		$petIds = $db->fetchPairs(
			'SELECT user_id, MIN(pet_id) FROM xf_user_pets WHERE user_id IN (' . $db->quote(array_keys($values) ?: [0]) . ') GROUP BY user_id'
		);

		foreach ($values AS $userId => $fields)
		{
			$name = trim((string) ($fields[self::USER_FIELD_CUSTOM_NAME] ?? ''));
			$name = mb_substr($name, 0, 50);

			$sheetValue = trim((string) ($fields[self::USER_FIELD_SPRITESHEET] ?? ''));
			$sheet = $sheetMap[$sheetValue] ?? (in_array($sheetValue, $sheetMap, true) ? $sheetValue : '');

			$disabledValue = @unserialize((string) ($fields[self::USER_FIELD_DISABLE_PET] ?? ''), ['allowed_classes' => false]);
			$disabled = is_array($disabledValue) && in_array('disable_option', $disabledValue, true) ? 1 : 0;

			if ($name === '' && $sheet === '' && !$disabled)
			{
				continue;
			}

			$settings = ['pet_name' => $name, 'spritesheet' => $sheet, 'is_disabled' => $disabled];

			if (isset($petIds[$userId]))
			{
				$db->update('xf_user_pets', $settings, 'pet_id = ?', (int) $petIds[$userId]);
			}
			else
			{
				$db->insert('xf_user_pets', $settings + [
					'user_id' => $userId,
					'created_at' => \XF::$time,
					'last_update' => \XF::$time,
				]);
			}
		}

		return ['position' => $position, 'sheetMap' => $sheetMap];
	}

	public function upgrade1060070Step3(): void
	{
		$this->convertLegacyDefaultSpritesheetOption();

		$this->removeCustomFields(UserField::class, [
			self::USER_FIELD_SPRITESHEET,
			self::USER_FIELD_CUSTOM_NAME,
			self::USER_FIELD_DISABLE_PET,
		]);
	}

	public function upgrade1060070Step4(): void
	{
		$this->db()->query("
			UPDATE xf_user_pets AS pet
			SET duel_wins = (
					SELECT COUNT(*) FROM xf_user_pets_duels AS duel
					WHERE duel.winner_pet_id = pet.pet_id AND duel.status = 'completed'
				),
				duel_losses = (
					SELECT COUNT(*) FROM xf_user_pets_duels AS duel
					WHERE duel.loser_pet_id = pet.pet_id AND duel.status = 'completed'
				),
				tournament_wins = (
					SELECT COUNT(*) FROM xf_user_pets_tournaments AS tournament
					WHERE tournament.winner_pet_id = pet.pet_id AND tournament.status = 'completed'
				)
		");
	}

	public function upgrade1060070Step5(): void
	{
		$this->applyDefaultPermissions();

		$this->app()->jobManager()->enqueueUnique(
			'sylphianUserPetsRebuildSolutionExperience',
			'Sylphian\UserPets:RebuildSolutionExperience',
			[],
			false
		);
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

		$db->delete('xf_user_alert', 'content_type IN (' . $db->quote(['syl_userpet', 'syl_userpet_tournament']) . ')');
		$db->delete('xf_permission_entry', 'permission_group_id = ?', self::PERMISSION_GROUP);
		$db->delete('xf_permission_entry_content', 'permission_group_id = ?', self::PERMISSION_GROUP);
		$db->delete('xf_permission_entry', 'permission_group_id = ? AND permission_id = ?', ['general', 'sylUserPetsDuelCooldown']);
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
				$table->addColumn('pet_name', 'varchar', 50)->setDefault('');
				$table->addColumn('spritesheet', 'varchar', 255)->setDefault('');
				$table->addColumn('is_disabled', 'tinyint', 3)->setDefault(0);
				$table->addColumn('sound_enabled', 'tinyint', 3)->setDefault(1);
				$table->addColumn('duel_wins', 'int')->setDefault(0);
				$table->addColumn('duel_losses', 'int')->setDefault(0);
				$table->addColumn('tournament_wins', 'int')->setDefault(0);

				$table->addPrimaryKey('pet_id');
				$table->addUniqueKey('user_id');
				$table->addKey(['experience', 'level'], 'experience_level');
				$table->addKey(['level', 'experience'], 'level_experience');
				$table->addKey('duel_wins');
				$table->addKey('duel_losses');
				$table->addKey('tournament_wins');
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
				$table->addColumn('evolve_level', 'smallint')->setDefault(0);
				$table->addColumn('evolves_to', 'varchar', 255)->setDefault('');

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
				$table->addColumn('registration_open_date', 'int')->setDefault(0);
				$table->addColumn('registration_close_date', 'int')->setDefault(0);
				$table->addColumn('start_date', 'int')->setDefault(0);
				$table->addColumn('round_interval_hours', 'smallint')->setDefault(0);
				$table->addColumn('next_round_date', 'int')->setDefault(0);
				$table->addColumn('repeat_days', 'smallint')->setDefault(0);
				$table->addColumn('repeat_of_id', 'int')->setDefault(0);

				$table->addPrimaryKey('tournament_id');
				$table->addKey('status');
				$table->addKey('winner_pet_id');
				$table->addKey('repeat_of_id');
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
			'xf_user_pets_xp_log' => function (Create $table)
			{
				$table->addColumn('log_id', 'int')->autoIncrement();
				$table->addColumn('pet_id', 'int');
				$table->addColumn('user_id', 'int');
				$table->addColumn('amount', 'int')->unsigned(false);
				$table->addColumn('source', 'varbinary', 25);
				$table->addColumn('content_type', 'varbinary', 25)->setDefault('');
				$table->addColumn('content_id', 'int')->setDefault(0);
				$table->addColumn('actor_user_id', 'int')->setDefault(0);
				$table->addColumn('level_after', 'int')->setDefault(0);
				$table->addColumn('log_date', 'int');

				$table->addPrimaryKey('log_id');
				$table->addKey(['pet_id', 'log_date'], 'pet_id_log_date');
				$table->addKey('log_date');
			},
		];
	}

	protected function applyDefaultPermissions(): void
	{
		foreach (self::DEFAULT_PERMISSIONS AS $permissionId)
		{
			$this->applyGlobalPermission(self::PERMISSION_GROUP, $permissionId, 'general', 'view');
		}
	}

	/**
	 * Only on a fresh install; an upgrade keeps the widgets the admin has placed.
	 */
	protected function createDefaultWidget(): void
	{
		$exists = $this->db()->fetchOne(
			'SELECT widget_id FROM xf_widget WHERE definition_id = ?',
			'sylphian_userpets_widget'
		);
		if ($exists)
		{
			return;
		}

		$this->createWidget(self::DEFAULT_WIDGET_KEY, 'sylphian_userpets_widget', [
			'positions' => [
				'forum_list_sidebar' => 50,
				'thread_view_sidebar' => 50,
				'member_view_sidebar' => 50,
			],
		]);
	}

	/**
	 * Duels between the two pets would become self-duels and open duels of the dropped pet can no
	 * longer be fought, so both are removed; finished duels move to the kept pet.
	 */
	protected function mergeLegacyDuels(int $fromPetId, int $toPetId): void
	{
		$db = $this->db();

		$db->delete(
			'xf_user_pets_duels',
			'(challenger_pet_id = ? AND opponent_pet_id = ?) OR (challenger_pet_id = ? AND opponent_pet_id = ?)',
			[$fromPetId, $toPetId, $toPetId, $fromPetId]
		);
		$db->delete(
			'xf_user_pets_duels',
			"status IN ('pending', 'accepted') AND (challenger_pet_id = ? OR opponent_pet_id = ?)",
			[$fromPetId, $fromPetId]
		);

		foreach (['challenger_pet_id', 'opponent_pet_id', 'winner_pet_id', 'loser_pet_id'] AS $column)
		{
			$db->update('xf_user_pets_duels', [$column => $toPetId], $column . ' = ?', $fromPetId);
		}
	}

	/**
	 * Up to 1.5.0 the default spritesheet option could hold a user field choice ID instead of a
	 * file name; 1.6.0 only understands file names.
	 */
	protected function convertLegacyDefaultSpritesheetOption(): void
	{
		$db = $this->db();
		$value = (string) $db->fetchOne(
			'SELECT option_value FROM xf_option WHERE option_id = ?',
			'sylphian_userpets_default_spritesheet'
		);

		$map = $this->getLegacySpritesheetChoiceMap();
		if ($value === '' || !isset($map[$value]))
		{
			return;
		}

		$db->update(
			'xf_option',
			['option_value' => $map[$value]],
			'option_id = ?',
			'sylphian_userpets_default_spritesheet'
		);

		/** @var \XF\Repository\OptionRepository $optionRepo */
		$optionRepo = $this->app()->repository('XF:Option');
		$optionRepo->rebuildOptionCache();
	}

	/**
	 * Choice IDs used by the spritesheet user field up to 1.5.0, mapped to file names. The ID
	 * scheme is copied here so the upgrade does not depend on code that 1.6.0 removed.
	 *
	 * @return array<string, string> choice ID => file name
	 */
	protected function getLegacySpritesheetChoiceMap(): array
	{
		$filenames = $this->db()->fetchAllColumn('SELECT filename FROM xf_user_pets_spritesheet');

		try
		{
			foreach ($this->app()->fs()->listContents(self::SPRITESHEET_DIRECTORY) AS $item)
			{
				if (($item['type'] ?? '') === 'file')
				{
					$filenames[] = (string) $item['basename'];
				}
			}
		}
		catch (\Throwable $e)
		{
			// a missing directory only means there are no files to map
		}

		$map = [];
		foreach (array_unique($filenames) AS $filename)
		{
			$base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
			$safe = trim((string) preg_replace('/_+/', '_', (string) preg_replace('/[^a-z0-9_]/i', '_', $base)), '_');
			$safe = substr($safe !== '' ? $safe : 'sheet', 0, 12);

			$map['ss_' . $safe . '_' . substr(md5(strtolower($filename)), 0, 8)] = $filename;
		}

		return $map;
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

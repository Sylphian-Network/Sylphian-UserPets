<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Repository\UserFieldRepository as LibraryUserFieldRepository;
use Sylphian\UserPets\Entity\UserPetsSpritesheet;
use Sylphian\UserPets\Repository\Traits\FileSystemTrait;
use Sylphian\UserPets\Repository\Traits\MirrorOperationsTrait;
use Sylphian\UserPets\Repository\Traits\SpritesheetFinderTrait;
use XF\Http\Upload;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use XF\Repository\UserFieldRepository;
use XF\Util\File;

class UserPetsSpritesheetRepository extends Repository
{
	use FileSystemTrait;
	use MirrorOperationsTrait;
	use SpritesheetFinderTrait;

	public const USER_FIELD_ID = 'syl_userpets_spritesheet';

	/**
	 * Returns health info for the directories this repo uses.
	 *
	 * @return array{dirs: array<int, array{key:string,label:string,path:string,exists:bool,readable:bool,writable:bool}>, hasIssues: bool}
	 */
	public function getPathHealth(): array
	{
		$targets = [
			[
				'key'          => 'primary',
				'label_phrase' => 'sylphian_userpets_primary_spritesheet_storage',
				'path'         => $this->getBasePath(),
			],
		];

		if ($this->isMirroringEnabled())
		{
			$targets[] = [
				'key'          => 'mirror',
				'label_phrase' => 'sylphian_userpets_dev_mirror_storage',
				'path'         => $this->getDevMirrorPath(),
			];
		}

		$dirs = [];
		$hasIssues = false;
		foreach ($targets AS $t)
		{
			$path = $t['path'];
			$exists = is_dir($path);
			$readable = $exists && is_readable($path);
			$writable = $exists && is_writable($path);

			$dirs[] = [
				'key'      => $t['key'],
				'label'    => (string) \XF::phrase($t['label_phrase']),
				'path'     => $path,
				'exists'   => $exists,
				'readable' => $readable,
				'writable' => $writable,
			];

			if (!$exists || !$readable || !$writable)
			{
				$hasIssues = true;
			}
		}

		return [
			'dirs' => $dirs,
			'hasIssues' => $hasIssues,
		];
	}

	protected function primaryPath(): string
	{
		return $this->getBasePath();
	}

	protected function mirrorPath(): string
	{
		return $this->getDevMirrorPath();
	}

	public function getFileUrl(string $filename): string
	{
		return rtrim($this->getBaseUrl(), '/') . '/' . rawurlencode($filename);
	}

	/**
	 * Registered spritesheets plus unregistered image files in the primary directory.
	 * Each entry: name, url, size, mtime, entity (?UserPetsSpritesheet), exists.
	 *
	 * @return list<array{name:string, url:string, size:int, mtime:int, entity:?UserPetsSpritesheet, exists:bool}>
	 */
	public function buildIndexList(): array
	{
		$basePath = $this->getBasePath();

		$files = [];
		$make = function (string $name, ?UserPetsSpritesheet $entity) use ($basePath): array
		{
			$full = $this->resolvePathInDirectory($basePath, $name);
			$exists = $full !== null && is_file($full);

			return [
				'name'   => $name,
				'url'    => $this->getFileUrl($name),
				'size'   => $exists ? (int) filesize($full) : 0,
				'mtime'  => $exists ? (int) filemtime($full) : 0,
				'entity' => $entity,
				'exists' => $exists,
			];
		};

		/** @var UserPetsSpritesheet $row */
		foreach ($this->finder('Sylphian\UserPets:UserPetsSpritesheet')->order(['title', 'filename'])->fetch() AS $row)
		{
			$files[$row->filename] = $make($row->filename, $row);
		}

		if (is_dir($basePath))
		{
			try
			{
				foreach (new \DirectoryIterator($basePath) AS $fileInfo)
				{
					if ($fileInfo->isDot() || !$fileInfo->isFile())
					{
						continue;
					}

					$name = $this->normalizeFilename($fileInfo->getFilename());
					if ($name !== null && !isset($files[$name]))
					{
						$files[$name] = $make($name, null);
					}
				}
			}
			catch (\Throwable $e)
			{
				\XF::logException($e, false, 'UserPets spritesheet scan failed: ');
			}
		}

		ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
		return array_values($files);
	}

	/**
	 * @return array{entity: UserPetsSpritesheet, exists: bool, fileUrl: string}|null null for a name that is not a plain image file name
	 */
	public function prepareEditData(string $filename): ?array
	{
		$name = $this->normalizeFilename($filename);
		if ($name === null)
		{
			return null;
		}

		$full = $this->resolvePathInDirectory($this->getBasePath(), $name);
		$exists = $full !== null && is_file($full);

		$entity = $this->findByFilename($name);
		if (!$entity)
		{
			/** @var UserPetsSpritesheet $entity */
			$entity = $this->em->create('Sylphian\UserPets:UserPetsSpritesheet');
			$entity->filename = $name;
			$entity->title = pathinfo($name, PATHINFO_FILENAME);
			$entity->last_modified = $exists ? (int) filemtime($full) : 0;
		}

		return [
			'entity'  => $entity,
			'exists'  => $exists,
			'fileUrl' => $this->getFileUrl($name),
		];
	}

	/**
	 * @return array{filename: string, exists: bool, url: string, entity: ?UserPetsSpritesheet}|null null for a name that is not a plain image file name
	 */
	public function prepareDeleteData(string $filename): ?array
	{
		$name = $this->normalizeFilename($filename);
		if ($name === null)
		{
			return null;
		}

		$full = $this->resolvePathInDirectory($this->getBasePath(), $name);

		return [
			'filename' => $name,
			'exists'   => $full !== null && is_file($full),
			'url'      => $this->getFileUrl($name),
			'entity'   => $this->findByFilename($name),
		];
	}

	/**
	 * Creates or updates a spritesheet record and its file: renames the file when the
	 * name changes, replaces it when an image is uploaded and keeps the dev mirror in
	 * step. Everything is validated before the first file is touched.
	 *
	 * @param int $id Existing entity ID (0 if new).
	 * @param string $origFilename File name the form was opened for ('' when adding).
	 * @param array<string,mixed> $input filename, title, frame_width, frame_height, frames_per_animation, fps
	 * @param Upload|null $upload Uploaded image, if any.
	 *
	 * @return array{entity?: UserPetsSpritesheet, errors?: array}
	 */
	public function saveSpritesheet(int $id, string $origFilename, array $input, ?Upload $upload): array
	{
		$basePath = $this->getBasePath();
		File::createDirectory($basePath, false);

		$origName = null;
		if (trim($origFilename) !== '')
		{
			$origName = $this->normalizeFilename($origFilename);
			if ($origName === null)
			{
				return $this->errors(\XF::phrase('sylphian_userpets_error_invalid_file_path'));
			}
		}

		if ($upload)
		{
			$upload->setAllowedExtensions($this->getAllowedExtensions());
			$upload->requireImage();
			$upload->setImageOptimize(false);
			if (!$upload->isValid($uploadErrors))
			{
				return ['errors' => array_values($uploadErrors)];
			}
		}

		$requested = trim((string) ($input['filename'] ?? ''));
		if ($requested === '')
		{
			$requested = $upload ? (string) $upload->getFileName() : (string) $origName;
		}

		$newName = $this->normalizeFilename($requested);
		if ($newName === null)
		{
			return $this->errors(\XF::phrase('sylphian_userpets_error_invalid_filename'));
		}

		$newFull = $this->resolvePathInDirectory($basePath, $newName);
		$origFull = $origName !== null ? $this->resolvePathInDirectory($basePath, $origName) : null;
		if ($newFull === null || ($origName !== null && $origFull === null))
		{
			return $this->errors(\XF::phrase('sylphian_userpets_error_invalid_file_path'));
		}

		$entity = $this->findById($id);
		if (!$entity && $origName !== null)
		{
			$entity = $this->findByFilename($origName);
		}
		if (!$entity)
		{
			/** @var UserPetsSpritesheet $entity */
			$entity = $this->em->create('Sylphian\UserPets:UserPetsSpritesheet');
		}

		$existing = $this->findByFilename($newName);
		if ($existing && (!$entity->exists() || $existing->spritesheet_id !== $entity->spritesheet_id))
		{
			return $this->errors(\XF::phrase('sylphian_userpets_error_spritesheet_filename_exists'));
		}

		$renaming = $origName !== null && $newName !== $origName;
		$origExists = $origFull !== null && is_file($origFull);

		if ($renaming && !$upload && !$origExists)
		{
			return $this->errors(\XF::phrase('sylphian_userpets_error_original_file_missing_for_rename'));
		}

		if (($renaming || $origName === null) && file_exists($newFull))
		{
			return $this->errors(\XF::phrase('sylphian_userpets_error_new_filename_exists'));
		}

		$entity->bulkSet([
			'filename' => $newName,
			'title' => (string) ($input['title'] ?? ''),
			'frame_width' => max(1, (int) ($input['frame_width'] ?? 1)),
			'frame_height' => max(1, (int) ($input['frame_height'] ?? 1)),
			'frames_per_animation' => max(1, (int) ($input['frames_per_animation'] ?? 1)),
			'fps' => max(1, (int) ($input['fps'] ?? 1)),
		]);

		$entity->preSave();
		if ($entity->hasErrors())
		{
			return ['errors' => $entity->getErrors()];
		}

		if ($upload)
		{
			$tmpTarget = $newFull . '.tmp-' . $this->getTemporarySuffix();

			try
			{
				File::copyFile($upload->getTempFile(), $tmpTarget, false);
			}
			catch (\Throwable $e)
			{
				@unlink($tmpTarget);
				\XF::logException($e, false, 'UserPets spritesheet upload failed: ');

				return $this->errors(\XF::phrase('sylphian_userpets_error_save_uploaded_image', [
					'error' => \XF::phrase('sylphian_userpets_spritesheet_path_error'),
				]));
			}

			if (!@rename($tmpTarget, $newFull))
			{
				@unlink($tmpTarget);
				return $this->errors(\XF::phrase('sylphian_userpets_error_atomic_write_failed'));
			}

			if ($renaming && $origExists)
			{
				@unlink($origFull);
			}
		}
		elseif ($renaming)
		{
			if (!@rename($origFull, $newFull))
			{
				return $this->errors(\XF::phrase('sylphian_userpets_error_rename_failed'));
			}

			$this->mirrorRename($origName, $newName);
		}

		clearstatcache();
		if (is_file($newFull))
		{
			$this->mirrorCopyFromPrimary($newName);
		}
		if ($renaming)
		{
			$this->mirrorDelete($origName);
		}

		$entity->set('last_modified', is_file($newFull) ? (int) filemtime($newFull) : 0, ['forceSet' => true]);

		try
		{
			$entity->save();
		}
		catch (PrintableException $e)
		{
			return ['errors' => $e->getMessages()];
		}
		catch (\Exception $e)
		{
			\XF::logException($e, false, 'UserPets spritesheet save failed: ');

			return $this->errors(\XF::phrase('sylphian_userpets_error_save_spritesheet', [
				'error' => \XF::phrase('sylphian_userpets_unknown_error'),
			]));
		}

		$this->syncUserFieldOptionsSafely($renaming ? [$origName => $newName] : []);

		return ['entity' => $entity];
	}

	/**
	 * Deletes a spritesheet file and its record. Only a plain file name directly
	 * inside the spritesheet directory is accepted.
	 *
	 * @return array list of errors; empty on success
	 */
	public function deleteByFilename(string $filename): array
	{
		if (trim($filename) === '')
		{
			return [\XF::phrase('sylphian_userpets_no_file_specified')];
		}

		$name = $this->normalizeFilename($filename);
		if ($name === null)
		{
			return [\XF::phrase('sylphian_userpets_error_invalid_file_path')];
		}

		$basePath = $this->getBasePath();
		if (is_dir($basePath))
		{
			$fullPath = $this->resolvePathInDirectory($basePath, $name);
			if ($fullPath === null)
			{
				return [\XF::phrase('sylphian_userpets_error_invalid_file_path')];
			}

			if (is_file($fullPath) && !@unlink($fullPath))
			{
				return [\XF::phrase('sylphian_userpets_error_delete_file_from_disk', [
					'error' => \XF::phrase('sylphian_userpets_spritesheet_path_error'),
				])];
			}
		}

		$entity = $this->findByFilename($name);
		if ($entity)
		{
			try
			{
				if (!$entity->delete(false))
				{
					return $entity->getErrors() ?: [\XF::phrase('sylphian_userpets_error_delete_database_record', [
						'error' => \XF::phrase('sylphian_userpets_unknown_error'),
					])];
				}
			}
			catch (PrintableException $e)
			{
				return $e->getMessages();
			}
			catch (\Exception $e)
			{
				\XF::logException($e, false, 'UserPets spritesheet delete failed: ');

				return [\XF::phrase('sylphian_userpets_error_delete_database_record', [
					'error' => \XF::phrase('sylphian_userpets_unknown_error'),
				])];
			}
		}

		$this->mirrorDelete($name);
		$this->syncUserFieldOptionsSafely();

		return [];
	}

	/**
	 * Rebuilds the choices of the spritesheet user field and moves stored values that
	 * still name a file (or a renamed sheet) onto the current choice ids.
	 *
	 * @param array<string, string> $renamedFilenames old file name => new file name
	 */
	public function syncUserFieldOptions(array $renamedFilenames = []): array
	{
		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $this->repository('Sylphian\UserPets:UserPets');
		$petsRepo->clearSpritesheetCaches();
		$choices = $petsRepo->getAvailableSpriteSheets();

		/** @var LibraryUserFieldRepository $libraryFieldRepo */
		$libraryFieldRepo = $this->repository('Sylphian\Library:UserFieldRepository');
		$result = $libraryFieldRepo->updateChoicesIfChanged(self::USER_FIELD_ID, $choices);

		$this->normalizeUserFieldValues($renamedFilenames);

		return $result;
	}

	/**
	 * Rewrites spritesheet user field values that are not a current choice id but name
	 * a known sheet (file name, base name, a legacy id or a renamed sheet) to that
	 * sheet's choice id, then rebuilds the custom field cache of the affected users.
	 * Values already holding a choice id, and values matching nothing, are untouched.
	 *
	 * @param array<string, string> $renamedFilenames old file name => new file name
	 *
	 * @return int number of users whose value changed
	 */
	public function normalizeUserFieldValues(array $renamedFilenames = []): int
	{
		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $this->repository('Sylphian\UserPets:UserPets');

		$choiceFiles = [];
		foreach ($this->buildIndexList() AS $file)
		{
			$choiceFiles[$petsRepo->getSpritesheetChoiceId($file['name'])] = $file['name'];
		}

		if (!$choiceFiles)
		{
			return 0;
		}

		$aliases = $this->buildUserFieldAliases($choiceFiles, $renamedFilenames);

		$db = $this->db();
		$currentIds = [];
		foreach (array_keys($choiceFiles) AS $choiceId)
		{
			$currentIds[] = 'CAST(' . $db->quote((string) $choiceId) . ' AS BINARY)';
		}

		$rows = $db->fetchAll(
			'SELECT user_id, field_value
			FROM xf_user_field_value
			WHERE field_id = ?
				AND LOWER(field_value) IN (' . $db->quote(array_map('strval', array_keys($aliases))) . ')
				AND CAST(field_value AS BINARY) NOT IN (' . implode(', ', $currentIds) . ')',
			self::USER_FIELD_ID
		);

		$userIdsByChoice = [];
		foreach ($rows AS $row)
		{
			$choiceId = $aliases[mb_strtolower((string) $row['field_value'])] ?? null;
			if ($choiceId !== null && $choiceId !== $row['field_value'])
			{
				$userIdsByChoice[$choiceId][] = (int) $row['user_id'];
			}
		}

		if (!$userIdsByChoice)
		{
			return 0;
		}

		$changedUserIds = [];
		foreach ($userIdsByChoice AS $choiceId => $userIds)
		{
			foreach (array_chunk($userIds, 500) AS $chunk)
			{
				$db->update(
					'xf_user_field_value',
					['field_value' => (string) $choiceId],
					'field_id = ? AND user_id IN (' . $db->quote($chunk) . ')',
					self::USER_FIELD_ID
				);
			}

			$changedUserIds = array_merge($changedUserIds, $userIds);
		}

		$this->rebuildUserFieldValueCaches($changedUserIds);

		return count($changedUserIds);
	}

	/**
	 * @param list<int> $userIds
	 */
	protected function rebuildUserFieldValueCaches(array $userIds): void
	{
		/** @var UserFieldRepository $fieldRepo */
		$fieldRepo = $this->repository('XF:UserField');
		$db = $this->db();

		foreach (array_chunk(array_values(array_unique($userIds)), 500) AS $chunk)
		{
			$db->beginTransaction();
			foreach ($chunk AS $userId)
			{
				$fieldRepo->rebuildUserFieldValuesCache($userId);
			}
			$db->commit();
		}
	}

	/**
	 * Lower-cased alias => choice id. Exact names win over loose ones (base name, base
	 * name with symbols replaced), and the first sheet wins a loose alias that two
	 * sheets share, as UserPetsRepository::resolveSpritesheetFilename() resolves it.
	 *
	 * @param array<string, string> $choiceFiles choice id => file name
	 * @param array<string, string> $renamedFilenames old file name => new file name
	 *
	 * @return array<string, string>
	 */
	protected function buildUserFieldAliases(array $choiceFiles, array $renamedFilenames): array
	{
		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $this->repository('Sylphian\UserPets:UserPets');

		$aliases = [];
		$add = static function (string $alias, string $choiceId) use (&$aliases): void
		{
			$alias = mb_strtolower(trim($alias));
			if ($alias !== '' && !isset($aliases[$alias]))
			{
				$aliases[$alias] = $choiceId;
			}
		};

		foreach ($choiceFiles AS $choiceId => $filename)
		{
			$add((string) $choiceId, (string) $choiceId);
			$add($filename, (string) $choiceId);
		}

		$renamedChoices = [];
		foreach ($renamedFilenames AS $oldName => $newName)
		{
			$newChoiceId = $petsRepo->getSpritesheetChoiceId((string) $newName);
			if (!isset($choiceFiles[$newChoiceId]))
			{
				continue;
			}

			$add($petsRepo->getSpritesheetChoiceId((string) $oldName), $newChoiceId);
			$add((string) $oldName, $newChoiceId);
			$renamedChoices[(string) $oldName] = $newChoiceId;
		}

		foreach ($choiceFiles AS $choiceId => $filename)
		{
			foreach ($this->getLooseAliases($filename) AS $alias)
			{
				$add($alias, (string) $choiceId);
			}
		}

		foreach ($renamedChoices AS $oldName => $choiceId)
		{
			foreach ($this->getLooseAliases($oldName) AS $alias)
			{
				$add($alias, $choiceId);
			}
		}

		return $aliases;
	}

	/**
	 * @return list<string>
	 */
	protected function getLooseAliases(string $filename): array
	{
		$base = pathinfo($filename, PATHINFO_FILENAME);
		$safe = trim((string) preg_replace('/[^a-z0-9_]/i', '_', $base), '_');

		return array_values(array_filter([$base, $safe], 'strlen'));
	}

	/**
	 * @param array<string, string> $renamedFilenames
	 */
	protected function syncUserFieldOptionsSafely(array $renamedFilenames = []): void
	{
		try
		{
			$this->syncUserFieldOptions($renamedFilenames);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets spritesheet user field sync failed: ');
		}
	}

	protected function errors(\XF\Phrase $error): array
	{
		return ['errors' => [$error]];
	}
}

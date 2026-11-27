<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Entity\UserPetsSpritesheet;
use Sylphian\UserPets\Repository\Traits\FileSystemTrait;
use Sylphian\UserPets\Repository\Traits\MirrorOperationsTrait;
use Sylphian\UserPets\Repository\Traits\SpritesheetFinderTrait;
use XF\Http\Upload;
use XF\Mvc\Entity\Repository;
use XF\Phrase;
use XF\PrintableException;
use XF\Repository\OptionRepository;
use XF\Util\File;

class UserPetsSpritesheetRepository extends Repository
{
	use FileSystemTrait;
	use MirrorOperationsTrait;
	use SpritesheetFinderTrait;

	public const DEFAULT_SPRITESHEET_OPTION = 'sylphian_userpets_default_spritesheet';

	public const MIN_EVOLVE_LEVEL = 2;

	protected const REFERENCE_BATCH_SIZE = 1000;

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
	 * title is the sheet title or, without one, the file name; evolution is the chain
	 * of appearances the sheet evolves through (see buildEvolutionChain()).
	 *
	 * @return list<array{name:string, url:string, size:int, mtime:int, entity:?UserPetsSpritesheet, exists:bool, title:string, evolve_level:int, evolves_to:string, evolution:list<array{filename:string, title:string, level:int, exists:bool}>}>
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
				'title'  => ($entity && $entity->title !== '') ? $entity->title : $name,
				'evolve_level' => $entity ? (int) $entity->evolve_level : 0,
				'evolves_to' => $entity ? (string) $entity->evolves_to : '',
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

		$chains = [];
		foreach ($files AS $name => $file)
		{
			$chains[$name] = static::buildEvolutionChain($files, (string) $name);
		}
		foreach ($chains AS $name => $chain)
		{
			$files[$name]['evolution'] = $chain;
		}

		return array_values($files);
	}

	/**
	 * Index rows keyed by file name, the shape resolveEvolvedFilename() and
	 * getEvolutionErrors() take.
	 *
	 * @param list<array{name:string}> $files rows of buildIndexList()
	 *
	 * @return array<string, array>
	 */
	public static function indexByFilename(array $files): array
	{
		$byName = [];
		foreach ($files AS $file)
		{
			$byName[(string) $file['name']] = $file;
		}

		return $byName;
	}

	/**
	 * Sheets an admin can pick (default appearance, evolution target): every indexed
	 * sheet whose file exists.
	 *
	 * @return array<string, string> file name => title
	 */
	public function getSpritesheetChoices(string $exclude = ''): array
	{
		$choices = [];
		foreach ($this->buildIndexList() AS $file)
		{
			if ($file['exists'] && $file['name'] !== $exclude)
			{
				$choices[$file['name']] = $file['title'];
			}
		}

		return $choices;
	}

	/**
	 * Choices for the "Evolves into" select: the other sheets, plus the current target
	 * when its file is gone, so that saving reports it instead of silently dropping it.
	 *
	 * @return array<string, string> file name => title
	 */
	public function getEvolutionTargetChoices(UserPetsSpritesheet $sheet): array
	{
		$choices = $this->getSpritesheetChoices((string) $sheet->filename);

		$current = (string) $sheet->evolves_to;
		if ($current !== '' && !isset($choices[$current]))
		{
			$choices[$current] = $current;
		}

		return $choices;
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
	 * step. A rename moves every reference (pets, other sheets' evolution, the default
	 * option) to the new name. Everything is validated before the first file is touched.
	 *
	 * @param int $id Existing entity ID (0 if new).
	 * @param string $origFilename File name the form was opened for ('' when adding).
	 * @param array<string,mixed> $input filename, title, frame_width, frame_height, frames_per_animation, fps, evolves_to, evolve_level
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

		$evolvesTo = trim((string) ($input['evolves_to'] ?? ''));
		$evolveLevel = max(0, (int) ($input['evolve_level'] ?? 0));
		if ($evolvesTo === '')
		{
			$evolveLevel = 0;
		}
		else
		{
			$evolutionErrors = static::getEvolutionErrors(
				static::indexByFilename($this->buildIndexList()),
				$newName,
				$evolvesTo,
				$evolveLevel,
				$entity->exists() ? (string) $entity->getExistingValue('filename') : (string) $origName
			);
			if ($evolutionErrors)
			{
				return ['errors' => $evolutionErrors];
			}
		}

		$entity->bulkSet([
			'filename' => $newName,
			'title' => (string) ($input['title'] ?? ''),
			'frame_width' => max(1, (int) ($input['frame_width'] ?? 1)),
			'frame_height' => max(1, (int) ($input['frame_height'] ?? 1)),
			'frames_per_animation' => max(1, (int) ($input['frames_per_animation'] ?? 1)),
			'fps' => max(1, (int) ($input['fps'] ?? 1)),
			'evolves_to' => $evolvesTo,
			'evolve_level' => $evolveLevel,
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

		if ($renaming)
		{
			$this->updateReferencesSafely(fn () => $this->renameSpritesheetReferences($origName, $newName));
		}
		else
		{
			$this->clearCaches();
		}

		return ['entity' => $entity];
	}

	/**
	 * Deletes a spritesheet file and its record, and points every reference to it back
	 * to the default appearance. Only a plain file name directly inside the spritesheet
	 * directory is accepted.
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
		$this->updateReferencesSafely(fn () => $this->clearSpritesheetReferences($name));

		return [];
	}

	/**
	 * The appearance a pet of $level shows when its sheet is $filename: follows
	 * evolves_to while level >= evolve_level > 0 and the target is in $sheetsByFilename
	 * (and not marked 'exists' => false). A loop in the data ends at the last sheet
	 * not yet visited. Returns $filename when no step applies.
	 *
	 * @param array<string, array{evolve_level?:int, evolves_to?:string, exists?:bool}> $sheetsByFilename
	 */
	public static function resolveEvolvedFilename(array $sheetsByFilename, string $filename, int $level): string
	{
		$current = $filename;
		$visited = [$current => true];

		while (isset($sheetsByFilename[$current]))
		{
			$sheet = $sheetsByFilename[$current];
			$evolveLevel = (int) ($sheet['evolve_level'] ?? 0);
			$target = (string) ($sheet['evolves_to'] ?? '');

			if ($evolveLevel <= 0
				|| $level < $evolveLevel
				|| $target === ''
				|| isset($visited[$target])
				|| !static::isSheetAvailable($sheetsByFilename, $target)
			)
			{
				break;
			}

			$visited[$target] = true;
			$current = $target;
		}

		return $current;
	}

	/**
	 * The evolution steps starting at $filename, for display. level is the level at
	 * which a pet of this sheet reaches the step: a step whose own level is lower than
	 * an earlier one is reached together with that earlier step. The chain ends at a
	 * missing target (included with exists = false) or before a sheet already in it.
	 *
	 * @param array<string, array{title?:string, evolve_level?:int, evolves_to?:string, exists?:bool}> $sheetsByFilename
	 *
	 * @return list<array{filename:string, title:string, level:int, exists:bool}>
	 */
	public static function buildEvolutionChain(array $sheetsByFilename, string $filename): array
	{
		$steps = [];
		$visited = [$filename => true];
		$current = $filename;
		$reachedAt = 0;

		while (isset($sheetsByFilename[$current]))
		{
			$sheet = $sheetsByFilename[$current];
			$evolveLevel = (int) ($sheet['evolve_level'] ?? 0);
			$target = (string) ($sheet['evolves_to'] ?? '');

			if ($evolveLevel <= 0 || $target === '' || isset($visited[$target]))
			{
				break;
			}

			$reachedAt = max($reachedAt, $evolveLevel);
			$exists = static::isSheetAvailable($sheetsByFilename, $target);
			$steps[] = [
				'filename' => $target,
				'title' => (string) ($sheetsByFilename[$target]['title'] ?? $target),
				'level' => $reachedAt,
				'exists' => $exists,
			];

			if (!$exists)
			{
				break;
			}

			$visited[$target] = true;
			$current = $target;
		}

		return $steps;
	}

	/**
	 * Checks an evolution before it is saved: the target is another sheet that exists,
	 * the level is at least MIN_EVOLVE_LEVEL, and the chain from the saved sheet never
	 * comes back to a sheet already in it. $originalFilename is the sheet's name before
	 * this save; when it differs from $filename, references to it count as references
	 * to $filename, as renameSpritesheetReferences() will make them.
	 *
	 * @param array<string, array{evolve_level?:int, evolves_to?:string, exists?:bool}> $sheetsByFilename
	 *
	 * @return list<Phrase> empty when the evolution may be saved
	 */
	public static function getEvolutionErrors(
		array $sheetsByFilename,
		string $filename,
		string $evolvesTo,
		int $evolveLevel,
		string $originalFilename = ''
	): array
	{
		$evolvesTo = trim($evolvesTo);
		if ($evolvesTo === '')
		{
			return [];
		}

		if ($evolvesTo === $filename || ($originalFilename !== '' && $evolvesTo === $originalFilename))
		{
			return [\XF::phrase('sylphian_userpets_error_evolution_self')];
		}

		if (!static::isSheetAvailable($sheetsByFilename, $evolvesTo))
		{
			return [\XF::phrase('sylphian_userpets_error_evolution_target_missing')];
		}

		if ($evolveLevel < self::MIN_EVOLVE_LEVEL)
		{
			return [\XF::phrase('sylphian_userpets_error_evolution_level', ['level' => self::MIN_EVOLVE_LEVEL])];
		}

		$next = [];
		foreach ($sheetsByFilename AS $name => $sheet)
		{
			$name = (string) $name;
			$target = (string) ($sheet['evolves_to'] ?? '');
			if ($name === $originalFilename || $target === '' || (int) ($sheet['evolve_level'] ?? 0) <= 0)
			{
				continue;
			}

			$next[$name] = ($originalFilename !== '' && $target === $originalFilename) ? $filename : $target;
		}
		$next[$filename] = $evolvesTo;

		$visited = [];
		$current = $filename;
		while (isset($next[$current]))
		{
			$visited[$current] = true;
			$current = $next[$current];

			if (isset($visited[$current]))
			{
				return [\XF::phrase('sylphian_userpets_error_evolution_cycle')];
			}
		}

		return [];
	}

	/**
	 * Points pets and other sheets' evolution that use $old at $new, moves the default
	 * spritesheet option along and clears the spritesheet caches. File names compare
	 * byte for byte: the columns' collation would also match other capitalisations.
	 */
	public function renameSpritesheetReferences(string $old, string $new): void
	{
		$old = trim($old);
		$new = trim($new);
		if ($old === '' || $new === '' || $old === $new)
		{
			return;
		}

		$this->replaceReferences($old, $new);
	}

	/**
	 * Points pets that use $filename back to the default appearance, removes evolution
	 * into it, resets the default spritesheet option if it names it and clears the
	 * spritesheet caches.
	 */
	public function clearSpritesheetReferences(string $filename): void
	{
		$filename = trim($filename);
		if ($filename === '')
		{
			return;
		}

		$this->replaceReferences($filename, '');
	}

	/**
	 * @param string $new '' removes the reference
	 */
	protected function replaceReferences(string $old, string $new): void
	{
		$db = $this->db();
		$db->beginTransaction();

		try
		{
			$this->replacePetSpritesheets($old, $new);
			$this->replaceEvolutionTargets($old, $new);

			$db->commit();
		}
		catch (\Throwable $e)
		{
			$db->rollback();
			throw $e;
		}

		$this->replaceDefaultSpritesheetOption($old, $new);
		$this->clearCaches();
	}

	/**
	 * Works through the pets in primary key batches, so no statement scans or locks
	 * more than one batch of rows by an unindexed column.
	 */
	protected function replacePetSpritesheets(string $old, string $new): void
	{
		$db = $this->db();
		$lastPetId = 0;

		do
		{
			$petIds = array_map('intval', $db->fetchAllColumn(
				$db->limit(
					'SELECT pet_id
					FROM xf_user_pets
					WHERE pet_id > ?
						AND CAST(spritesheet AS BINARY) = CAST(? AS BINARY)
					ORDER BY pet_id',
					static::REFERENCE_BATCH_SIZE
				),
				[$lastPetId, $old]
			));
			if (!$petIds)
			{
				break;
			}

			$db->update(
				'xf_user_pets',
				['spritesheet' => $new],
				'pet_id IN (' . $db->quote($petIds) . ') AND CAST(spritesheet AS BINARY) = CAST(? AS BINARY)',
				$old
			);

			foreach ($petIds AS $petId)
			{
				$cached = $this->em->findCached('Sylphian\UserPets:UserPets', $petId);
				if ($cached instanceof UserPets && $cached->spritesheet === $old)
				{
					$cached->setAsSaved('spritesheet', $new);
				}
			}

			$lastPetId = end($petIds);
		}
		while (count($petIds) === static::REFERENCE_BATCH_SIZE);
	}

	protected function replaceEvolutionTargets(string $old, string $new): void
	{
		$db = $this->db();
		$values = $new === '' ? ['evolves_to' => '', 'evolve_level' => 0] : ['evolves_to' => $new];

		$sheetIds = array_map('intval', $db->fetchAllColumn(
			'SELECT spritesheet_id
			FROM xf_user_pets_spritesheet
			WHERE CAST(evolves_to AS BINARY) = CAST(? AS BINARY)',
			$old
		));
		if (!$sheetIds)
		{
			return;
		}

		$db->update(
			'xf_user_pets_spritesheet',
			$values,
			'spritesheet_id IN (' . $db->quote($sheetIds) . ')'
		);

		foreach ($sheetIds AS $sheetId)
		{
			$cached = $this->em->findCached('Sylphian\UserPets:UserPetsSpritesheet', $sheetId);
			if ($cached instanceof UserPetsSpritesheet)
			{
				foreach ($values AS $column => $value)
				{
					$cached->setAsSaved($column, $value);
				}
			}
		}
	}

	/**
	 * The new value is a sheet that was just saved, or '' (the default), so the
	 * option is written without running its validation again. The option cache is
	 * only rebuilt at the end of the request, so the loaded options are updated too.
	 */
	protected function replaceDefaultSpritesheetOption(string $old, string $new): void
	{
		$options = $this->options();
		if (!isset($options[self::DEFAULT_SPRITESHEET_OPTION])
			|| (string) $options[self::DEFAULT_SPRITESHEET_OPTION] !== $old
		)
		{
			return;
		}

		try
		{
			/** @var OptionRepository $optionRepo */
			$optionRepo = $this->repository('XF:Option');
			$optionRepo->updateOptionSkipVerify(self::DEFAULT_SPRITESHEET_OPTION, $new);
			$options[self::DEFAULT_SPRITESHEET_OPTION] = $new;
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets default spritesheet option update failed: ');
		}
	}

	/**
	 * A failure here must not turn a completed file operation into an error page; the
	 * caches are cleared either way.
	 */
	protected function updateReferencesSafely(\Closure $update): void
	{
		try
		{
			$update();
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets spritesheet reference update failed: ');
			$this->clearCaches();
		}
	}

	protected function clearCaches(): void
	{
		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $this->repository('Sylphian\UserPets:UserPets');
		$petsRepo->clearSpritesheetCaches();
	}

	/**
	 * @param array<string, array{exists?:bool}> $sheetsByFilename
	 */
	protected static function isSheetAvailable(array $sheetsByFilename, string $filename): bool
	{
		return isset($sheetsByFilename[$filename]) && ($sheetsByFilename[$filename]['exists'] ?? true);
	}

	protected function errors(Phrase $error): array
	{
		return ['errors' => [$error]];
	}
}

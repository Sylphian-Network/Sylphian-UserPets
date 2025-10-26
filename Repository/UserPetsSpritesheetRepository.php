<?php

namespace Sylphian\UserPets\Repository;

use Random\RandomException;
use Sylphian\Library\Repository\UserFieldRepository;
use Sylphian\UserPets\Entity\UserPetsSpritesheet;
use Sylphian\UserPets\Repository\Traits\FileSystemTrait;
use Sylphian\UserPets\Repository\Traits\MirrorOperationsTrait;
use Sylphian\UserPets\Repository\Traits\SpritesheetFinderTrait;
use XF\Http\Upload;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use XF\Util\File;

class UserPetsSpritesheetRepository extends Repository
{
	use FileSystemTrait;
	use MirrorOperationsTrait;
	use SpritesheetFinderTrait;

	/**
	 * Returns health info for the directories this repo uses.
	 *
	 * @return array{dirs: array<int, array{key:string,label:string,path:string,exists:bool,readable:bool,writable:bool}>, hasIssues: bool}
	 */
	public function getPathHealth(): array
	{
		$targets = [
			[
				'key'   => 'primary',
				'label' => 'Primary spritesheet storage (external data)',
				'path'  => $this->getBasePath(),
			],
		];

		if ($this->isMirroringEnabled())
		{
			$targets[] = [
				'key'   => 'mirror',
				'label' => 'Dev mirror (add-on _files data)',
				'path'  => $this->getDevMirrorPath(),
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
				'label'    => $t['label'],
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

	/**
	 * Get the absolute path to the primary spritesheet storage directory.
	 *
	 * @return string Absolute path under external data storage.
	 */
	protected function primaryPath(): string
	{
		return $this->getBasePath();
	}

	/**
	 * Get the absolute path to the development mirror directory.
	 *
	 * @return string Absolute path to the dev mirror.
	 */
	protected function mirrorPath(): string
	{
		return $this->getDevMirrorPath();
	}

	/**
	 * Build an index list combining database and filesystem entries.
	 * Each entry includes:
	 *   - name: string
	 *   - url: string
	 *   - size: int
	 *   - mtime: int
	 *   - entity: ?UserPetsSpritesheet
	 *   - exists: bool
	 *
	 * @return array<int,array<string,mixed>> List of spritesheet data for admin UI.
	 */
	public function buildIndexList(): array
	{
		$basePath = $this->getBasePath();
		$baseUrl  = $this->getBaseUrl();

		$dbRows = $this->finder('Sylphian\UserPets:UserPetsSpritesheet')->order(['title', 'filename'])->fetch();
		$dbByFile = [];
		/** @var UserPetsSpritesheet $row */
		foreach ($dbRows AS $row)
		{
			$dbByFile[$row->filename] = $row;
		}

		$files = [];
		$make = function (string $name, ?UserPetsSpritesheet $entity) use ($basePath, $baseUrl): array
		{
			$full = $basePath . DIRECTORY_SEPARATOR . $name;
			$exists = is_file($full);
			$size  = $exists ? filesize($full) : 0;
			$mtime = $exists ? filemtime($full) : 0;
			return [
				'name'   => $name,
				'url'    => rtrim($baseUrl, '/') . '/' . rawurlencode($name),
				'size'   => $size,
				'mtime'  => $mtime,
				'entity' => $entity,
				'exists' => $exists,
			];
		};

		foreach ($dbByFile AS $filename => $entity)
		{
			$files[$filename] = $make($filename, $entity);
		}

		if (is_dir($basePath))
		{
			$allow = ['png', 'gif', 'jpg', 'jpeg', 'webp'];
			try
			{
				$it = new \DirectoryIterator($basePath);
				foreach ($it AS $fi)
				{
					if ($fi->isDot() || !$fi->isFile())
					{
						continue;
					}
					$ext = strtolower($fi->getExtension());
					if (!in_array($ext, $allow, true))
					{
						continue;
					}
					$name = $fi->getFilename();
					if (!isset($files[$name]))
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
	 * Prepare spritesheet edit data for the admin form.
	 *
	 * @param string $filename File name being edited.
	 * @return array{
	 *     entity: UserPetsSpritesheet,
	 *     exists: bool,
	 *     fileUrl: string
	 * }
	 */
	public function prepareEditData(string $filename): array
	{
		$filename = trim($filename);
		$basePath = $this->getBasePath();
		$baseUrl  = $this->getBaseUrl();
		$full     = $basePath . DIRECTORY_SEPARATOR . $filename;
		$exists   = is_file($full);

		$entity = $this->findByFilename($filename);
		if (!$entity)
		{
			/** @var UserPetsSpritesheet $entity */
			$entity = $this->em->create('Sylphian\UserPets:UserPetsSpritesheet');
			$entity->filename = $filename;
			$entity->title = pathinfo($filename, PATHINFO_FILENAME);
			$entity->frame_width = 192;
			$entity->frame_height = 192;
			$entity->frames_per_animation = 4;
			$entity->fps = 4;
			$entity->last_modified = $exists ? (filemtime($full) ?: 0) : 0;
		}

		return [
			'entity'  => $entity,
			'exists'  => $exists,
			'fileUrl' => rtrim($baseUrl, '/') . '/' . rawurlencode($filename),
		];
	}

	/**
	 * Save or update a spritesheet entity and its corresponding file.
	 *
	 * Handles:
	 *   - Renaming files.
	 *   - Uploading replacements.
	 *   - Validating extensions and filenames.
	 *   - Syncing mirrors.
	 *
	 * Returns an array containing either:
	 *   - `entity` => UserPetsSpritesheet on success
	 *   - `error`  => string on failure
	 *
	 * @param int $id Existing entity ID (0 if new).
	 * @param string $origFilename Previous filename (from form).
	 * @param array<string,mixed> $input Form input fields.
	 * @param Upload|null $upload Uploaded file instance or null.
	 * @return array{entity?:UserPetsSpritesheet,error?:string}
	 */
	public function saveSpritesheet(int $id, string $origFilename, array $input, ?Upload $upload): array
	{
		$allowedExts = ['png','gif','jpg','jpeg','webp'];
		$sanitize = static function (string $name) use ($allowedExts): string
		{
			$name = trim($name);
			$name = basename($name);
			$name = preg_replace('/\s+/', ' ', $name);
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if ($name === '' || !in_array($ext, $allowedExts, true))
			{
				return '';
			}
			return $name;
		};

		$basePath = $this->getBasePath();
		File::createDirectory($basePath, false);

		$newFilename = $sanitize($input['filename'] ?? $origFilename);
		if ($newFilename === '')
		{
			return ['error' => 'Please provide a valid filename with an allowed image extension.'];
		}

		$origFull = $basePath . DIRECTORY_SEPARATOR . $origFilename;
		$newFull  = $basePath . DIRECTORY_SEPARATOR . $newFilename;

		if ($origFilename !== '')
		{
			$realBase = realpath($basePath) ?: $basePath;
			$realOrig = file_exists($origFull) ? realpath($origFull) : $origFull;
			$isUnder = $realOrig && str_starts_with($realOrig, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
			if (!$isUnder)
			{
				return ['error' => 'Invalid file path.'];
			}
		}

		$entity = $this->findById($id);
		if (!$entity && $origFilename !== '')
		{
			$entity = $this->findByFilename($origFilename);
		}
		if (!$entity)
		{
			/** @var UserPetsSpritesheet $entity */
			$entity = $this->em->create('Sylphian\UserPets:UserPetsSpritesheet');
		}

		$hasUpload = ($upload instanceof Upload) && $upload->isValid();
		if ($upload && !$upload->isValid())
		{
			return ['error' => 'Uploaded file is not valid.'];
		}
		if ($hasUpload)
		{
			$clientName = $upload->getFileName();
			$ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
			if (!in_array($ext, $allowedExts, true))
			{
				return ['error' => 'Uploaded file type is not allowed.'];
			}
		}

		$renaming  = ($newFilename !== $origFilename && $origFilename !== '');
		$origExists = (file_exists($origFull));

		if ($renaming && !$upload && !$origExists)
		{
			return ['error' => 'Cannot rename: original file not found on disk.'];
		}

		$hasUpload = ($upload instanceof Upload) && $upload->isValid();
		if ($upload && !$upload->isValid())
		{
			return ['error' => 'Uploaded file is not valid.'];
		}

		if ($renaming && !$hasUpload && file_exists($newFull))
		{
			return ['error' => 'A file with the new filename already exists.'];
		}

		if ($renaming && !$hasUpload)
		{
			if (!@rename($origFull, $newFull))
			{
				return ['error' => 'Failed to rename the file on disk.'];
			}

			$this->mirrorRename($origFilename, $newFilename);
		}

		if ($hasUpload)
		{
			$tmp = $upload->getTempFile();

			try
			{
				$randSuffix = bin2hex(random_bytes(6));
			}
			catch (RandomException)
			{
				$randSuffix = uniqid('fallback_', true);
			}

			$tmpTarget = $newFull . '.tmp-' . $randSuffix;

			try
			{
				if (file_exists($tmpTarget))
				{
					@unlink($tmpTarget);
				}
				File::copyFile($tmp, $tmpTarget);
				if (file_exists($newFull))
				{
					@unlink($newFull);
				}
				if (!@rename($tmpTarget, $newFull))
				{
					// Cleanup temp file if rename failed
					@unlink($tmpTarget);
					return ['error' => 'Failed to write uploaded image atomically.'];
				}
			}
			catch (\Throwable $e)
			{
				@unlink($tmpTarget);
				return ['error' => 'Failed to save uploaded image: ' . $e->getMessage()];
			}

			if ($renaming && $origExists && $origFull !== $newFull)
			{
				@unlink($origFull);
			}
		}

		if (file_exists($newFull))
		{
			$this->mirrorCopyFromPrimary($newFilename);
			if ($renaming && $origFilename !== $newFilename)
			{
				$this->mirrorDelete($origFilename);
			}
		}

		$existing = $this->findByFilename($newFilename);
		if ($existing && (!$entity->exists() || $existing->spritesheet_id !== $entity->spritesheet_id))
		{
			return ['error' => 'A spritesheet with that filename already exists.'];
		}

		$entity->filename = $newFilename;
		$entity->title = (string) ($input['title'] ?? '');
		$entity->frame_width = max(1, (int) ($input['frame_width'] ?? 1));
		$entity->frame_height = max(1, (int) ($input['frame_height'] ?? 1));
		$entity->frames_per_animation = max(1, (int) ($input['frames_per_animation'] ?? 1));
		$entity->fps = max(1, (int) ($input['fps'] ?? 1));
		$entity->last_modified = file_exists($newFull) ? (filemtime($newFull) ?: 0) : 0;

		try
		{
			$entity->save(true, false);
			try
			{
				$this->syncUserFieldOptions();
			}
			catch (\Throwable $e)
			{
				\XF::logException($e);
			}
		}
		catch (PrintableException|\Exception $e)
		{
			return ['error' => 'Could not save spritesheet: ' . $e->getMessage()];
		}

		return ['entity' => $entity];
	}

	/**
	 * Delete a spritesheet by filename from both disk and database.
	 *
	 * @param string $filename File name (with extension).
	 * @return true|string True on success, or error message on failure.
	 */
	public function deleteByFilename(string $filename): true|string
	{
		$filename = trim($filename);
		if ($filename === '')
		{
			return 'No file specified.';
		}

		$basePath = $this->getBasePath();
		$fullPath = $basePath . DIRECTORY_SEPARATOR . $filename;

		$realBase = realpath($basePath) ?: $basePath;
		$realFile = realpath($fullPath);
		$isUnderBase = $realFile && str_starts_with($realFile, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

		$entity = $this->findByFilename($filename);

		$errors = [];
		if ($isUnderBase && is_file($fullPath))
		{
			try
			{
				@unlink($fullPath);
			}
			catch (\Throwable $e)
			{
				$errors[] = 'Could not delete file from disk: ' . $e->getMessage();
			}
		}

		if ($entity)
		{
			try
			{
				$entity->delete();
			}
			catch (\Throwable $e)
			{
				$errors[] = 'Could not delete database record: ' . $e->getMessage();
			}
		}

		$this->mirrorDelete($filename);

		if ($errors)
		{
			return implode("\n", $errors);
		}

		try
		{
			$this->syncUserFieldOptions();
		}
		catch (\Throwable $e)
		{
			\XF::logException($e);
		}

		return true;
	}

	public function syncUserFieldOptions(): array
	{
		$choices = $this->buildSpritesheetChoices();
		/** @var UserFieldRepository $sylRepo */
		$sylRepo = $this->repository('Sylphian\\Library:UserFieldRepository');
		return $sylRepo->updateChoicesIfChanged('syl_userpets_spritesheet', $choices);
	}

	protected function buildSpritesheetChoices(): array
	{
		$choices = [];
		$used = [];

		$rows = $this->finder('Sylphian\\UserPets:UserPetsSpritesheet')
			->order(['title', 'filename'])
			->fetch();

		foreach ($rows AS $row)
		{
			$label = $row->title ?: pathinfo($row->filename, PATHINFO_FILENAME);
			$key = $this->makeSafeChoiceKey($row->filename, $used);
			$choices[$key] = $label;
		}

		return $choices;
	}

	protected function makeSafeChoiceKey(string $filename, array &$used): string
	{
		$base = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', pathinfo($filename, PATHINFO_FILENAME)));
		$base = trim($base, '_') ?: 'sprite';

		$key = $base;
		$i = 1;
		while (isset($used[$key]))
		{
			$key = $base . '_' . $i++;
		}

		$used[$key] = true;
		return $key;
	}

}

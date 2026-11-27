<?php

namespace Sylphian\UserPets\Repository\Traits;

use XF\Util\File;

trait FileSystemTrait
{
	public function getBasePath(): string
	{
		$dataRoot = rtrim(\XF::app()->config('externalDataPath'), DIRECTORY_SEPARATOR);
		return File::canonicalizePath("$dataRoot/assets/sylphian/userpets/spritesheets");
	}

	public function getBaseUrl(): string
	{
		return \XF::app()->applyExternalDataUrl('assets/sylphian/userpets/spritesheets');
	}

	public function getDevMirrorPath(): string
	{
		$addonsRoot = rtrim(\XF::getAddOnDirectory(), DIRECTORY_SEPARATOR);
		return File::canonicalizePath(
			$addonsRoot . DIRECTORY_SEPARATOR
			. 'Sylphian' . DIRECTORY_SEPARATOR
			. 'UserPets' . DIRECTORY_SEPARATOR
			. '_files' . DIRECTORY_SEPARATOR
			. 'data' . DIRECTORY_SEPARATOR
			. 'assets' . DIRECTORY_SEPARATOR
			. 'sylphian' . DIRECTORY_SEPARATOR
			. 'userpets' . DIRECTORY_SEPARATOR
			. 'spritesheets'
		);
	}

	/**
	 * @return list<string>
	 */
	public function getAllowedExtensions(): array
	{
		return ['png', 'gif', 'jpg', 'jpeg', 'webp'];
	}

	/**
	 * Returns the name unchanged when it is a plain image file name, otherwise null.
	 * Anything that could address another directory is refused rather than cleaned
	 * up: path separators, control characters, names starting with a dot (".", "..",
	 * hidden files) and names whose extension is not an allowed image type.
	 */
	public function normalizeFilename(string $filename): ?string
	{
		$filename = trim($filename);

		if ($filename === '' || strlen($filename) > 255)
		{
			return null;
		}

		if (preg_match('#[/\\\\\x00-\x1F\x7F]#', $filename) || basename($filename) !== $filename)
		{
			return null;
		}

		if ($filename[0] === '.')
		{
			return null;
		}

		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($extension, $this->getAllowedExtensions(), true))
		{
			return null;
		}

		return $filename;
	}

	/**
	 * Full path of $filename directly inside $directory, or null when the name is not
	 * a plain file name, the directory does not exist, or an existing entry resolves
	 * (through a symlink) to anything but a regular file in that same directory.
	 */
	public function resolvePathInDirectory(string $directory, string $filename): ?string
	{
		$filename = $this->normalizeFilename($filename);
		if ($filename === null)
		{
			return null;
		}

		$realDirectory = realpath($directory);
		if ($realDirectory === false || !is_dir($realDirectory))
		{
			return null;
		}

		$path = $realDirectory . DIRECTORY_SEPARATOR . $filename;

		if (is_link($path) || file_exists($path))
		{
			$realPath = realpath($path);
			if ($realPath === false || dirname($realPath) !== $realDirectory || !is_file($realPath))
			{
				return null;
			}
		}

		return $path;
	}
}

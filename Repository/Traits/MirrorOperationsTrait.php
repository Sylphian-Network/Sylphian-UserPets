<?php

namespace Sylphian\UserPets\Repository\Traits;

use Random\RandomException;
use XF\Util\File;

trait MirrorOperationsTrait
{
	/**
	 * Whether mirroring to `_files` should occur.
	 */
	protected function isMirroringEnabled(): bool
	{
		$cfg = \XF::app()->config();
		return (bool) ($cfg['development']['enabled'] ?? false);
	}

	/**
	 * Copy a spritesheet from the primary directory to the mirror (atomically if possible).
	 *
	 * @param string $filename File name (with extension).
	 * @return void
	 */
	protected function mirrorCopyFromPrimary(string $filename): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$src = $this->primaryPath() . DIRECTORY_SEPARATOR . $filename;
		$dstDir = $this->mirrorPath();
		$dst = $dstDir . DIRECTORY_SEPARATOR . $filename;

		try
		{
			File::createDirectory($dstDir, false);

			try
			{
				$randSuffix = bin2hex(random_bytes(6));
			}
			catch (RandomException)
			{
				$randSuffix = uniqid('fallback_', true);
			}

			$tmp = $dst . '.tmp-' . $randSuffix;

			if (file_exists($tmp))
			{
				@unlink($tmp);
			}
			File::copyFile($src, $tmp);
			if (file_exists($dst))
			{
				@unlink($dst);
			}
			@rename($tmp, $dst);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror copy failed: ');
		}
	}

	/**
	 * Rename a spritesheet file within the mirror directory.
	 * If the old file doesn’t exist, this is a no-op.
	 *
	 * @param string $old Original filename.
	 * @param string $new New filename.
	 * @return void
	 */
	protected function mirrorRename(string $old, string $new): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$dir = $this->mirrorPath();
		$oldFull = $dir . DIRECTORY_SEPARATOR . $old;
		$newFull = $dir . DIRECTORY_SEPARATOR . $new;

		try
		{
			File::createDirectory($dir, false);
			if (is_file($oldFull))
			{
				if (file_exists($newFull))
				{
					@unlink($newFull);
				}
				@rename($oldFull, $newFull);
			}
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror rename failed: ');
		}
	}

	/**
	 * Delete a spritesheet file from the mirror directory (if it exists).
	 *
	 * @param string $filename File name to delete.
	 * @return void
	 */
	protected function mirrorDelete(string $filename): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$dir = $this->mirrorPath();
		$full = $dir . DIRECTORY_SEPARATOR . $filename;
		try
		{
			if (is_file($full))
			{
				@unlink($full);
			}
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror delete failed: ');
		}
	}
}

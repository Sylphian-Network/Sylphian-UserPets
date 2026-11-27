<?php

namespace Sylphian\UserPets\Repository\Traits;

use Random\RandomException;
use XF\Util\File;

/**
 * Keeps the add-on's _files copy of the spritesheets in step with the primary
 * directory in development mode. Every name goes through resolvePathInDirectory()
 * (FileSystemTrait), so nothing outside the two spritesheet directories is touched.
 */
trait MirrorOperationsTrait
{
	protected function isMirroringEnabled(): bool
	{
		$cfg = \XF::app()->config();
		return (bool) ($cfg['development']['enabled'] ?? false);
	}

	protected function mirrorCopyFromPrimary(string $filename): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$src = $this->resolvePathInDirectory($this->primaryPath(), $filename);
		if ($src === null || !is_file($src))
		{
			return;
		}

		try
		{
			File::createDirectory($this->mirrorPath(), false);

			$dst = $this->resolvePathInDirectory($this->mirrorPath(), $filename);
			if ($dst === null)
			{
				return;
			}

			$tmp = $dst . '.tmp-' . $this->getTemporarySuffix();
			File::copyFile($src, $tmp, false);
			if (!@rename($tmp, $dst))
			{
				@unlink($tmp);
			}
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror copy failed: ');
		}
	}

	protected function mirrorRename(string $old, string $new): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$oldFull = $this->resolvePathInDirectory($this->mirrorPath(), $old);
		$newFull = $this->resolvePathInDirectory($this->mirrorPath(), $new);
		if ($oldFull === null || $newFull === null || !is_file($oldFull))
		{
			return;
		}

		try
		{
			@rename($oldFull, $newFull);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror rename failed: ');
		}
	}

	protected function mirrorDelete(string $filename): void
	{
		if (!$this->isMirroringEnabled())
		{
			return;
		}

		$full = $this->resolvePathInDirectory($this->mirrorPath(), $filename);
		if ($full === null || !is_file($full))
		{
			return;
		}

		try
		{
			@unlink($full);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'UserPets mirror delete failed: ');
		}
	}

	protected function getTemporarySuffix(): string
	{
		try
		{
			return bin2hex(random_bytes(6));
		}
		catch (RandomException)
		{
			return uniqid('fallback_', true);
		}
	}
}

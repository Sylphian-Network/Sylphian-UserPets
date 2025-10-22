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
}

<?php

namespace Sylphian\UserPets\Option;

use Sylphian\UserPets\Service\DuelAlgorithms\DuelAlgorithmInterface;
use XF\Entity\Option;
use XF\Option\AbstractOption;

class DuelAlgorithm extends AbstractOption
{
	public static function renderOption(Option $option, array $htmlParams): string
	{
		$data = static::getSelectData($option, $htmlParams);

		return static::getTemplater()->formSelectRow(
			$data['controlOptions'],
			$data['choices'],
			$data['rowOptions']
		);
	}

	protected static function getSelectData(Option $option, array $htmlParams): array
	{
		$choices = self::getChoices();

		return [
			'choices' => $choices,
			'controlOptions' => static::getControlOptions($option, $htmlParams),
			'rowOptions' => static::getRowOptions($option, $htmlParams),
		];
	}

	/**
	 * Returns [FQCN => human label] for all classes implementing DuelAlgorithmInterface.
	 */
	public static function getChoices(): array
	{
		$choices = [];
		$addonsRoot = \XF::getAddOnDirectory();

		$duelPath = $addonsRoot . '/Sylphian/UserPets/Service/DuelAlgorithms';
		$choices += self::scanAlgorithmDir($duelPath, 'Sylphian\\UserPets\\Service\\DuelAlgorithms');

		foreach (glob($addonsRoot . '/*/*', GLOB_ONLYDIR) AS $addonDir)
		{
			if (str_ends_with($addonDir, 'Sylphian/UserPets'))
			{
				continue;
			}

			$namespaceBase = basename(dirname($addonDir)) . '\\' . basename($addonDir);
			$duelPath = $addonDir . '/Sylphian/UserPets/Service/DuelAlgorithms';

			if (!is_dir($duelPath))
			{
				continue;
			}

			$choices += self::scanAlgorithmDir($duelPath, "$namespaceBase\\Sylphian\\UserPets\\Service\\DuelAlgorithms");
		}

		uasort($choices, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
		return $choices;
	}

	/**
	 * Scans a directory for classes implementing DuelAlgorithmInterface.
	 */
	protected static function scanAlgorithmDir(string $path, string $namespace): array
	{
		$list = [];

		foreach (glob($path . '/*.php') AS $file)
		{
			$base = basename($file, '.php');
			$class = "$namespace\\$base";

			if (!class_exists($class))
			{
				continue;
			}

			if (!is_subclass_of($class, DuelAlgorithmInterface::class))
			{
				continue;
			}

			$list[$class] = [
				'value' => $class,
				'label' => trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $base)),
			];
		}

		return $list;
	}
}

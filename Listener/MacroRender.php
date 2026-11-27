<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Service\PetLeveling;
use XF\Template\Templater;

class MacroRender
{
	public const CHART_MAX_LEVEL = 30;

	/**
	 * Swaps the add-on's option group for a template with the level curve chart.
	 * Registered for admin:option_macros:option_form_block only.
	 */
	public static function preRender(Templater $templater, &$type, &$template, &$name, array &$arguments, array &$globalVars): void
	{
		if ($template !== 'option_macros'
			|| $name !== 'option_form_block'
			|| empty($arguments['group'])
			|| $arguments['group']->group_id !== 'sylphian_userpets'
		)
		{
			return;
		}

		$template = 'sylphian_userpets_option_macros';

		$arguments['preCalculatedExpData'] = self::getLevelChartData(new PetLeveling());
		$arguments['experienceLabels'] = [
			'experience' => \XF::phrase('sylphian_userpets_required_experience'),
		];
	}

	/**
	 * @return array<int,array{label:string,values:array{experience:int},averages:array{experience:int}}>
	 */
	public static function getLevelChartData(PetLeveling $petLeveling, int $maxLevel = self::CHART_MAX_LEVEL): array
	{
		$expData = [];

		for ($level = 1; $level <= $maxLevel; $level++)
		{
			$exp = $petLeveling->getExperienceRequiredForLevel($level);

			$expData[] = [
				'label' => (string) \XF::phrase('sylphian_userpets_level_chart_label', ['level' => $level]),
				'values' => ['experience' => $exp],
				'averages' => ['experience' => $exp],
			];
		}

		return $expData;
	}
}

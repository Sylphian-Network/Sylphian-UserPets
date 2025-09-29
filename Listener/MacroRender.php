<?php

namespace Sylphian\UserPets\Listener;

use Sylphian\UserPets\Service\PetLeveling;
use XF\Template\Templater;

class MacroRender
{
	public static function preRender(Templater $templater, &$type, &$template, &$name, array &$arguments, array &$globalVars): void
	{
		if ($template == 'option_macros' && $name == 'option_form_block' && !empty($arguments['group']) && $arguments['group']->group_id == 'sylphian_userpets')
		{
			$template = 'sylphian_userpets_option_macros';

			$options = \XF::options();
			$baseCoefficient = (float) $options->sylphian_userpets_base_coefficient;
			$polynomialPower = (float) $options->sylphian_userpets_polynomial_power;

			$expData = [];
			$maxLevel = 30;

			$petLevelingService = new PetLeveling($baseCoefficient, $polynomialPower);

			for ($level = 1; $level <= $maxLevel; $level++)
			{
				$exp = $petLevelingService->getExperienceRequiredForLevel($level);

				$expData[] = [
					'label' => 'Level ' . $level,
					'values' => ['experience' => $exp],
					'averages' => ['experience' => $exp],
				];
			}

			$arguments['preCalculatedExpData'] = $expData;
			$arguments['experienceLabels'] = [
				'experience' => \XF::phrase('sylphian_userpets_required_experience'),
			];
		}
	}
}

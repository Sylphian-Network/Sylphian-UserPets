<?php

namespace Sylphian\UserPets\Duel;

use XF\Phrase;

class DurationFormatter
{
	/**
	 * Remaining wait as a phrase that is never empty: seconds under a minute, otherwise whole
	 * minutes rounded up so the wait is never understated.
	 */
	public static function format(int $seconds): Phrase
	{
		$seconds = max(0, $seconds);
		$language = \XF::language();

		if ($seconds < 60)
		{
			return \XF::phrase('x_seconds', ['count' => $language->numberFormat($seconds)]);
		}

		$totalMinutes = (int) ceil($seconds / 60);
		$hours = intdiv($totalMinutes, 60);
		$minutes = $totalMinutes % 60;

		$minutesPhrase = \XF::phrase('x_minutes', ['count' => $language->numberFormat($minutes)]);
		if (!$hours)
		{
			return $minutesPhrase;
		}

		$hoursPhrase = \XF::phrase('x_hours', ['count' => $language->numberFormat($hours)]);
		if (!$minutes)
		{
			return $hoursPhrase;
		}

		return \XF::phrase('sylphian_userpets_duration_hours_minutes', [
			'hours' => $hoursPhrase,
			'minutes' => $minutesPhrase,
		]);
	}
}

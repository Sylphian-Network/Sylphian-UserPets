<?php

namespace Sylphian\UserPets\Helper;

class DisplayName
{
	public static function decode(?string $username): string
	{
		$username = (string) $username;

		if ($username === '')
		{
			return '';
		}

		$decoded = $username;

		// Some usernames arrive double-escaped (for example &amp;#039;).
		for ($i = 0; $i < 3; $i++)
		{
			$next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($next === $decoded)
			{
				break;
			}

			$decoded = $next;
		}

		return $decoded;
	}

	public static function renderUsernamePhrase(string $phraseName, ?string $username): string
	{
		$placeholder = '__SYL_USERNAME__';
		$phraseText = (string) \XF::phrase($phraseName, ['username' => $placeholder]);

		return str_replace($placeholder, self::decode($username), $phraseText);
	}
}

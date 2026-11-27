<?php

namespace Sylphian\UserPets\Helper;

/**
 * The stored pet state is a comma separated list of state IDs ("hungry,tired").
 */
class PetState
{
	public const DEFAULT_STATE = 'idle';

	public const STATE_PHRASES = [
		'idle' => 'sylphian_userpets_state_idle',
		'hungry' => 'sylphian_userpets_state_hungry',
		'unhappy' => 'sylphian_userpets_state_unhappy',
		'tired' => 'sylphian_userpets_state_tired',
	];

	/**
	 * @return string[] Lower-case state IDs, never empty
	 */
	public static function getStateIds(?string $state): array
	{
		$parts = preg_split('/\s*,\s*/', strtolower(trim((string) $state)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

		return $parts ? array_values(array_unique($parts)) : [self::DEFAULT_STATE];
	}

	/**
	 * The single state used to pick the sprite animation row.
	 */
	public static function getPrimaryState(?string $state): string
	{
		return self::getStateIds($state)[0];
	}

	/**
	 * Translated, comma separated state labels; unknown IDs show the core "Unknown" phrase.
	 */
	public static function getDisplay(?string $state): string
	{
		$labels = [];
		foreach (self::getStateIds($state) AS $stateId)
		{
			$labels[] = (string) \XF::phrase(self::STATE_PHRASES[$stateId] ?? 'unknown');
		}

		return implode(', ', array_unique($labels));
	}
}

<?php

namespace Sylphian\UserPets;

/**
 * Where an XP change came from. Stored in xf_user_pets_xp_log.source and shown through the
 * phrase "sylphian_userpets_xp_source_{source}".
 */
final class XpSource
{
	public const ACTION = 'action';
	public const THREAD = 'thread';
	public const POST = 'post';
	public const SOLUTION = 'solution';
	public const RECOUNT = 'recount';
	public const DUEL = 'duel';
	public const TOURNAMENT = 'tournament';
	public const TUTORIAL = 'tutorial';
	public const ADMIN = 'admin';
	public const LEGACY = 'legacy';
	public const OTHER = 'other';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [
			self::ACTION,
			self::THREAD,
			self::POST,
			self::SOLUTION,
			self::RECOUNT,
			self::DUEL,
			self::TOURNAMENT,
			self::TUTORIAL,
			self::ADMIN,
			self::LEGACY,
			self::OTHER,
		];
	}
}

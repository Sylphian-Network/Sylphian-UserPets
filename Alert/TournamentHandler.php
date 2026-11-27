<?php

namespace Sylphian\UserPets\Alert;

use Sylphian\UserPets\Entity\UserPetsTournament;
use XF\Alert\AbstractHandler;
use XF\Mvc\Entity\Entity;

/**
 * Alerts about tournaments a member's pet takes part in. The content is the tournament;
 * the alert is sent by the system, so it has no sending user.
 *
 * @extends AbstractHandler<UserPetsTournament>
 */
class TournamentHandler extends AbstractHandler
{
	public const CONTENT_TYPE = 'syl_userpet_tournament';

	public const ACTION_STARTED = 'started';
	public const ACTION_MATCH_WON = 'match_won';
	public const ACTION_MATCH_LOST = 'match_lost';
	public const ACTION_CHAMPION = 'champion';
	public const ACTION_CANCELLED = 'cancelled';

	public function getOptOutActions(): array
	{
		return [
			self::ACTION_STARTED,
			self::ACTION_MATCH_WON,
			self::ACTION_MATCH_LOST,
			self::ACTION_CHAMPION,
			self::ACTION_CANCELLED,
		];
	}

	public function getOptOutDisplayOrder(): int
	{
		return 510;
	}

	public function canViewContent(Entity $entity, &$error = null): bool
	{
		return $entity instanceof UserPetsTournament && $entity->canView();
	}
}

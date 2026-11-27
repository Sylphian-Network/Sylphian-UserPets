<?php

namespace Sylphian\UserPets\Job;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Service\LegacyXpCompensation;
use XF\Job\AbstractRebuildJob;
use XF\Phrase;

/**
 * One-time correction of the XP that pets missed before 1.5.0 (see LegacyXpCompensation).
 * Started from the admin tool; never decreases XP, sends no alerts, skips opted-out owners.
 *
 * The correction is not idempotent, so a finished run leaves a marker in the data registry
 * and a later run does nothing unless "confirm_rerun" is set.
 */
class LegacyXpCorrection extends AbstractRebuildJob
{
	public const UNIQUE_KEY = 'sylUserPetsLegacyXpCorrection';

	/**
	 * xf_data_registry.data_key holds at most 25 bytes.
	 */
	public const MARKER_KEY = 'sylUserPetsLegacyXp';

	protected $defaultData = [
		'not_before' => 0,
		'not_after' => 0,
		'confirm_rerun' => false,
		'pets' => 0,
		'xp' => 0,
		'skipped' => 0,
		'failed' => 0,
	];

	protected ?LegacyXpCompensation $compensation = null;

	public function run($maxRunTime)
	{
		if (!$this->data['steps'] && !$this->data['confirm_rerun'] && $this->getMarker())
		{
			return $this->complete();
		}

		$result = parent::run($maxRunTime);

		if ($result->completed)
		{
			$this->app->registry()->set(self::MARKER_KEY, [
				'date' => \XF::$time,
				'pets' => (int) $this->data['pets'],
				'xp' => (int) $this->data['xp'],
				'not_before' => (int) $this->data['not_before'],
				'not_after' => (int) $this->data['not_after'],
				'skipped' => (int) $this->data['skipped'],
				'failed' => (int) $this->data['failed'],
			]);
		}

		return $result;
	}

	/**
	 * @return array{date:int,pets:int,xp:int,not_before:int,not_after:int,skipped:int,failed:int}|null
	 */
	public function getMarker(): ?array
	{
		$marker = $this->app->registry()->get(self::MARKER_KEY);

		return is_array($marker) ? $marker : null;
	}

	protected function getNextIds($start, $batch): array
	{
		$db = $this->app->db();

		return $db->fetchAllColumn(
			$db->limit(
				'
					SELECT pet_id
					FROM xf_user_pets
					WHERE pet_id > ?
					ORDER BY pet_id
				',
				$batch
			),
			[$start]
		);
	}

	protected function rebuildById($id): void
	{
		try
		{
			/** @var UserPets|null $pet */
			$pet = $this->app->em()->find('Sylphian\UserPets:UserPets', $id);
			if (!$pet)
			{
				return;
			}

			if (UserPetOptOut::isDisabledByUserId((int) $pet->user_id))
			{
				$this->data['skipped']++;
				return;
			}

			$added = $this->getCompensation()->apply(
				$pet,
				(int) $this->data['not_before'],
				false,
				(int) $this->data['not_after']
			);

			if ($added > 0)
			{
				$this->data['pets']++;
				$this->data['xp'] += $added;
			}
		}
		catch (\Throwable $e)
		{
			$this->data['failed']++;

			Logger::error('Legacy pet XP correction failed for a pet: ' . $e->getMessage(), [
				'pet_id' => $id,
			]);
		}
	}

	protected function getStatusType(): Phrase
	{
		return \XF::phrase('sylphian_userpets_legacy_xp_job');
	}

	public function canTriggerByChoice()
	{
		return false;
	}

	protected function getCompensation(): LegacyXpCompensation
	{
		if (!$this->compensation)
		{
			/** @var LegacyXpCompensation $compensation */
			$compensation = $this->app->service('Sylphian\UserPets:LegacyXpCompensation');
			$this->compensation = $compensation;
		}

		return $this->compensation;
	}
}

<?php

namespace Sylphian\UserPets\Repository;

use Sylphian\Library\Logger\Logger;
use Sylphian\UserPets\Entity\UserPets;
use Sylphian\UserPets\Helper\UserPetOptOut;
use Sylphian\UserPets\Service\PetLeveling;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;
use XF\PrintableException;
use XF\Repository\UserAlertRepository;

class UserPetsRepository extends Repository
{
	/**
	 * Get a user's pet
	 *
	 * @param int $userId The user ID
	 * @return UserPets|null The pet entity or null if not found
	 */
	public function getUserPet(int $userId): ?UserPets
	{
		/** @var UserPets|null $pet */
		$pet = $this->finder('Sylphian\UserPets:UserPets')
			->where('user_id', $userId)
			->fetchOne();

		return $pet;
	}

	/**
	 * Create a new pet for a user
	 *
	 * @param int $userId The user ID
	 * @return UserPets The newly created pet
	 */
	public function createPet(int $userId): UserPets
	{
		/** @var UserPets $pet */
		$pet = $this->em->create('Sylphian\UserPets:UserPets');
		$pet->user_id = $userId;
		$pet->level = 1;
		$pet->experience = 0;
		$pet->hunger = 100;
		$pet->sleepiness = 100;
		$pet->happiness = 100;
		$pet->state = 'idle';
		$pet->last_update = \XF::$time;
		$pet->last_action_time = 0;
		$pet->last_duel_time = 0;
		$pet->created_at = \XF::$time;

		try
		{
			$pet->save();
		}
		catch (\Exception $e)
		{
			Logger::error(
				"Failed to create pet for user_id {$userId}.",
				['error' => $e->getMessage()]
			);
		}

		return $pet;
	}

	/**
	 * Get available sprite sheet options
	 *
	 * @return array List of available sprite sheets as field_choices array
	 */
	public function getAvailableSpriteSheets(): array
	{
		$rootDir = \XF::getRootDirectory();
		$spritesheetDir = $rootDir . '/data/assets/sylphian/userpets/spritesheets';

		$options = [];

		if (is_dir($spritesheetDir))
		{
			$files = array_diff(scandir($spritesheetDir), ['.', '..']);
			foreach ($files AS $file)
			{
				if (preg_match('/.png$/i', $file))
				{
					$identifier = strtolower(preg_replace('/[^a-z0-9_]/i', '_', pathinfo($file, PATHINFO_FILENAME)));

					$displayLabel = ucwords(str_replace('_', ' ', pathinfo($file, PATHINFO_FILENAME)));

					if (!isset($options[$identifier]))
					{
						$options[$identifier] = $displayLabel;
					}
					else
					{
						$count = 1;
						while (isset($options["{$identifier}_{$count}"]))
						{
							$count++;
						}
						$options["{$identifier}_{$count}"] = $displayLabel . " {$count}";
					}
				}
			}
		}
		else
		{
			Logger::error('Spritesheet directory not found', [
				'spritesheetDir' => $spritesheetDir,
			]);
		}

		if (empty($options))
		{
			$options['slime'] = 'Slime';
		}

		return $options;
	}

	/**
	 * Award experience to a user's pet
	 *
	 * @param int $userId The user ID to award experience to
	 * @param bool $updateActionTime Whether to update the last_action_time field
	 */
	public function awardPetExperience(int $userId, int $amountOfExp, bool $updateActionTime = true): void
	{
		if ($amountOfExp <= 0)
		{
			return;
		}

		if (UserPetOptOut::isDisabledByUserId($userId))
		{
			return;
		}

		$app = \XF::app();

		/** @var UserPetsRepository $petsRepo */
		$petsRepo = $app->repository('Sylphian\UserPets:UserPets');
		$pet = $petsRepo->getUserPet($userId);

		if (!$pet)
		{
			return; // User doesn't have a pet
		}

		$petLevelingService = new PetLeveling();
		$oldLevel = $pet->level;
		$leveledUp = $petLevelingService->addExperience($pet, $amountOfExp);

		if ($updateActionTime)
		{
			$pet->last_action_time = \XF::$time;
		}

		try
		{
			$pet->save();

			if ($leveledUp)
			{
				/** @var UserAlertRepository $alertRepo */
				$alertRepo = $app->repository('XF:UserAlert');

				/** @var User $user */
				$user = $app->em()->find('XF:User', $userId);

				if ($user)
				{
					$alertRepo->alertFromUser(
						$user,
						$user,
						'syl_userpet',
						$pet->pet_id,
						'levelup',
						[
							'old_level' => $oldLevel,
							'new_level' => $pet->level,
							'exp_amount' => $amountOfExp,
						],
						['autoRead' => true]
					);
				}
			}
		}
		catch (PrintableException|\Exception $e)
		{
			Logger::error('Could not award pet exp: ' . $e->getMessage(), [
				'exception' => $e->getMessage(),
				'trace' => $e->getTrace(),
			]);
		}
	}
}

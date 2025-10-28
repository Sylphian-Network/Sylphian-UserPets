# Sylphian/UserPets
Give users of your Xenforo forum their own virtual pet.

## Default Features

### Duel Algorithms
This add-on comes with four duel algorithms that determine the winner of a pet duel by default:
- [Default Algorithm](Service/DuelAlgorithms/DefaultAlgorithm.php)
- [Care Based Algorithm](Service/DuelAlgorithms/CareBasedAlgorithm.php)
- [Exp Based Algorithm](Service/DuelAlgorithms/ExpBasedAlgorithm.php)
- [Weighted Algorithm](Service/DuelAlgorithms/WeightedAlgorithm.php)

Each algorithm implements the DuelAlgorithmInterface and must define:
```php
public function calculateWinner(array $petA, array $petB): array;
```

- Parameters
  - `$petA`: The first pet’s data as an array
  - `$petB`: The second pet’s data as an array
- Returns
  - The winning pet’s data as an array

## Custom Bits

### Creating a Custom Algorithm (in another add-on)
Other add-ons can easily extend this system by defining their own algorithm classes.
To do this, create the following directory structure inside your own add-on:
```
src/addons/Vendor/Addon/Sylphian/UserPets/Service/DuelAlgorithms/
```

Then create a PHP file, for example `NewAlgorithm.php:`
```php
<?php

namespace Vendor\Addon\Sylphian\UserPets\Service\DuelAlgorithms;

use Sylphian\UserPets\Service\DuelAlgorithms\DuelAlgorithmInterface;

class CheatAlgorithm implements DuelAlgorithmInterface
{
    public function calculateWinner(array $petA, array $petB): array
    {
        return $petA; // petA always wins
    }
}
```

## Leaderboard Support
Sylphian/UserPets now includes Leaderboard support through integration with the [Sylphian/Leaderboard](https://github.com/Sylphian-Network/Sylphian-Leaderboard) add-on.  
This allows you to showcase the top-performing pets across your forum.

Current leaderboards include:
- [Highest experience](Leaderboard/Provider/ExperienceProvider.php)
- [Duel wins](Leaderboard/Provider/DuelWinsProvider.php)

## Requirements
- Xenforo 2.3.7
- PHP 8.3
- [Sylphian/Library 1.0.6](https://github.com/Sylphian-Network/Sylphian-Library)
- (Optional) [Sylphian/Leaderboard 1.0.0](https://github.com/Sylphian-Network/Sylphian-Leaderboard)

## Credit
- [KoiRoylers for the level up audio.](https://pixabay.com/users/koiroylers-44305058/)
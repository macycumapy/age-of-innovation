<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PowerAction: string
{
    /** Отдать 3 силы и построить мост. */
    case BuildBridge = 'build_bridge';
    /** Отдать 3 силы и получить учёного. */
    case GainScholar = 'gain_scholar';
    /** Отдать 4 силы и получить 2 инструмента. */
    case GainTools = 'gain_tools';
    /** Отдать 4 силы и получить 7 монет. */
    case GainCoins = 'gain_coins';
    /** Отдать 4 силы и преобразовать с 1 бесплатной лопатой. */
    case TerraformOneSpade = 'terraform_one_spade';
    /** Отдать 6 силы и преобразовать с 2 бесплатными лопатами. */
    case TerraformTwoSpades = 'terraform_two_spades';

    public function cost(?Faction $faction = null): int
    {
        $cost = match ($this) {
            self::BuildBridge, self::GainScholar => 3,
            self::GainTools, self::GainCoins, self::TerraformOneSpade => 4,
            self::TerraformTwoSpades => 6,
        };

        return $faction === Faction::Illusionists ? $cost - 1 : $cost;
    }

    public function description(?Faction $faction = null): string
    {
        $cost = $this->cost($faction);

        return match ($this) {
            self::BuildBridge => "Потратить {$cost} силы, чтобы построить мост.",
            self::GainScholar => "Потратить {$cost} силы, чтобы получить учёного.",
            self::GainTools => "Потратить {$cost} силы, чтобы получить 2 инструмента.",
            self::GainCoins => "Потратить {$cost} силы, чтобы получить 7 золота.",
            self::TerraformOneSpade => "Потратить {$cost} силы, чтобы получить 1 лопату.",
            self::TerraformTwoSpades => "Потратить {$cost} силы, чтобы получить 2 лопаты.",
        };
    }

    public function victoryPoints(Faction $faction, int $playerCount): int
    {
        if ($faction !== Faction::Illusionists) {
            return 0;
        }

        return $playerCount >= 4 ? 2 : 1;
    }
}

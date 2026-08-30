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

    public function cost(): int
    {
        return match ($this) {
            self::BuildBridge, self::GainScholar => 3,
            self::GainTools, self::GainCoins, self::TerraformOneSpade => 4,
            self::TerraformTwoSpades => 6,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BuildBridge => 'Потратить 3 силы, чтобы построить мост.',
            self::GainScholar => 'Потратить 3 силы, чтобы получить учёного.',
            self::GainTools => 'Потратить 4 силы, чтобы получить 2 инструмента.',
            self::GainCoins => 'Потратить 4 силы, чтобы получить 7 золота.',
            self::TerraformOneSpade => 'Потратить 4 силы, чтобы получить 1 лопату.',
            self::TerraformTwoSpades => 'Потратить 6 силы, чтобы получить 2 лопаты.',
        };
    }
}

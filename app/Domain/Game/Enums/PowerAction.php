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
}

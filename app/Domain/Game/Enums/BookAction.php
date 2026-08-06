<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum BookAction: string
{
    /** Отдать 1 любую книгу и получить 5 силы. */
    case GainPower = 'gain_power';
    /** Отдать 1 любую книгу и сделать 2 шага в выбранной дисциплине. */
    case AdvanceKnowledge = 'advance_knowledge';
    /** Отдать 2 любые книги и получить 6 монет. */
    case GainCoins = 'gain_coins';
    /** Отдать 2 любые книги и бесплатно улучшить мастерскую до гильдии. */
    case UpgradeToGuild = 'upgrade_to_guild';
    /** Отдать 2 любые книги и получить по 2 ПО за свою гильдию. */
    case ScoreGuilds = 'score_guilds';
    /** Отдать 3 любые книги и преобразовать с 3 бесплатными лопатами. */
    case TerraformThreeSpades = 'terraform_three_spades';
}

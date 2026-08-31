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

    public function cost(): int
    {
        return match ($this) {
            self::GainPower, self::AdvanceKnowledge => 1,
            self::GainCoins, self::UpgradeToGuild, self::ScoreGuilds => 2,
            self::TerraformThreeSpades => 3,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GainPower => 'Потратить любую книгу и получить 5 силы.',
            self::AdvanceKnowledge => 'Потратить любую книгу и сделать 2 шага в выбранной дисциплине.',
            self::GainCoins => 'Потратить 2 любые книги и получить 6 золота.',
            self::UpgradeToGuild => 'Потратить 2 любые книги и бесплатно улучшить мастерскую до гильдии.',
            self::ScoreGuilds => 'Потратить 2 любые книги и получить по 2 ПО за каждую свою гильдию.',
            self::TerraformThreeSpades => 'Потратить 3 любые книги и получить 3 лопаты.',
        };
    }
}

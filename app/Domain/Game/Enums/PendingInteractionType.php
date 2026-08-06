<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PendingInteractionType: string
{
    /** Выбор дисциплины стартовой книги и распределение стартовых шагов знаний. */
    case ChooseStartingResources = 'choose_starting_resources';
    case PowerOffer = 'power_offer';
    case ChooseTown = 'choose_town';
    case ChoosePalace = 'choose_palace';
    case ChooseCompetency = 'choose_competency';
    case SpendSpades = 'spend_spades';
}

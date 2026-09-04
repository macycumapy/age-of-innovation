<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PendingInteractionType: string
{
    /** Выбор дисциплины стартовой книги и распределение стартовых шагов знаний. */
    case ChooseStartingResources = 'choose_starting_resources';
    case PowerOffer = 'power_offer';
    case ChooseTown = 'choose_town';
    case ChooseTownBooks = 'choose_town_books';
    case OfferPalaceWaterTown = 'offer_palace_water_town';
    case ChoosePalace = 'choose_palace';
    case PlacePalaceGuild = 'place_palace_guild';
    case ChooseCompetency = 'choose_competency';
    case SpendSpades = 'spend_spades';
    case BuildWorkshopAfterTerraforming = 'build_workshop_after_terraforming';
    case PlaceBridge = 'place_bridge';
    case ChooseScienceBonusBooks = 'choose_science_bonus_books';
    case ChooseInnovationBooks = 'choose_innovation_books';
}

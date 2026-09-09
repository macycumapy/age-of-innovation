<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PendingInteractionType: string
{
    /** Выбор дисциплины стартовой книги и распределение стартовых шагов знаний. */
    case ChooseStartingResources = 'choose_starting_resources';
    case PowerOffer = 'power_offer';
    case ChooseTown = 'choose_town';
    /** Распределение книг, полученных с жетона города. */
    case ChooseTownBooks = 'choose_town_books';
    /** Распределение книги и шагов знаний за основание города Кошачьими. */
    case ChooseFelineTownBonus = 'choose_feline_town_bonus';
    case OfferPalaceWaterTown = 'offer_palace_water_town';
    case ChoosePalace = 'choose_palace';
    case PlacePalaceGuild = 'place_palace_guild';
    case ChooseCompetency = 'choose_competency';
    case SpendSpades = 'spend_spades';
    case BuildWorkshopAfterTerraforming = 'build_workshop_after_terraforming';
    case PlaceBridge = 'place_bridge';
    case ChooseScienceBonusBooks = 'choose_science_bonus_books';
    case ChooseInnovationBooks = 'choose_innovation_books';
    case ChooseShippingBooks = 'choose_shipping_books';
    case ChooseTerraformingBooks = 'choose_terraforming_books';
    case ChoosePalaceBooks = 'choose_palace_books';
    case ChooseRoundBonus = 'choose_round_bonus';
    case PlaceNeutralBuilding = 'place_neutral_building';
}

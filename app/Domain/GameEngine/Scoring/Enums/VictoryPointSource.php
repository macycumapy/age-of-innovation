<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Scoring\Enums;

enum VictoryPointSource: string
{
    case Starting = 'starting';
    case RoundScoring = 'round_scoring';
    case Town = 'town';
    case Innovation = 'innovation';
    case Competency = 'competency';
    case FinalObjectives = 'final_objectives';
    case FinalKnowledge = 'final_knowledge';
    case RoundBonus = 'round_bonus';
    case Faction = 'faction';
    case Palace = 'palace';
    case Development = 'development';
    case KnowledgeIncome = 'knowledge_income';
    case BookActions = 'book_actions';
    case EndGameResources = 'end_game_resources';
    case PowerOffers = 'power_offers';
    case Untracked = 'untracked';
}

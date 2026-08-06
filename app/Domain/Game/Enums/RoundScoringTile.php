<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum RoundScoringTile: string
{
    /** 2 ПО за мастерскую; за каждые 3 уровня права — 1 учёный. */
    case WorkshopLaw = 'workshop_law';
    /** 2 ПО за мастерскую; за каждые 3 уровня банковского дела — 4 силы. */
    case WorkshopBanking = 'workshop_banking';
    /** 3 ПО за гильдию; за каждые 3 уровня права — выбранная книга. */
    case GuildLaw = 'guild_law';
    /** 3 ПО за гильдию; за каждые 4 уровня медицины — 1 лопата. */
    case GuildMedicine = 'guild_medicine';
    /** 4 ПО за школу; за каждый уровень банковского дела — 1 монета. */
    case SchoolBanking = 'school_banking';
    /** 5 ПО за дворец или университет; за каждые 2 уровня медицины — инструмент. */
    case PalaceUniversityMedicine = 'palace_university_medicine';
    /** 5 ПО за дворец или университет; за каждые 2 уровня банковского дела — инструмент. */
    case PalaceUniversityBanking = 'palace_university_banking';
    /** 2 ПО за лопату; за каждый уровень инженерного дела — монета. */
    case SpadeEngineering = 'spade_engineering';
    /** 1 ПО за шаг знания; за каждые 3 уровня медицины — выбранная книга. */
    case KnowledgeMedicine = 'knowledge_medicine';
    /** 5 ПО за жетон города; за каждые 4 уровня инженерного дела — лопата. */
    case TownEngineering = 'town_engineering';
    /** 3 ПО за шаг судоходства или преобразования; за каждые 3 уровня инженерии — учёный. */
    case TrackEngineering = 'track_engineering';
    /** 5 ПО за изобретение; за каждые 2 уровня права — 3 силы. */
    case InnovationLaw = 'innovation_law';
}

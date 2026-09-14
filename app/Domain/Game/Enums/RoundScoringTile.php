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

    public function goal(): RoundScoringGoal
    {
        return match ($this) {
            self::WorkshopLaw, self::WorkshopBanking => RoundScoringGoal::Workshop,
            self::GuildLaw, self::GuildMedicine => RoundScoringGoal::Guild,
            self::SchoolBanking => RoundScoringGoal::School,
            self::PalaceUniversityMedicine,
            self::PalaceUniversityBanking => RoundScoringGoal::PalaceOrUniversity,
            self::SpadeEngineering => RoundScoringGoal::Spade,
            self::KnowledgeMedicine => RoundScoringGoal::Knowledge,
            self::TownEngineering => RoundScoringGoal::Town,
            self::TrackEngineering => RoundScoringGoal::ShippingOrTerraforming,
            self::InnovationLaw => RoundScoringGoal::Innovation,
        };
    }

    public function buildingVictoryPoints(BuildingType $buildingType): int
    {
        return match ($this->goal()) {
            RoundScoringGoal::Workshop => $buildingType === BuildingType::Workshop ? 2 : 0,
            RoundScoringGoal::Guild => $buildingType === BuildingType::Guild ? 3 : 0,
            RoundScoringGoal::School => $buildingType === BuildingType::School ? 4 : 0,
            RoundScoringGoal::PalaceOrUniversity => in_array(
                $buildingType,
                [BuildingType::Palace, BuildingType::University],
                true,
            ) ? 5 : 0,
            default => 0,
        };
    }

    public function knowledgeDiscipline(): KnowledgeDiscipline
    {
        return match ($this) {
            self::WorkshopLaw, self::GuildLaw, self::InnovationLaw => KnowledgeDiscipline::Law,
            self::WorkshopBanking,
            self::SchoolBanking,
            self::PalaceUniversityBanking => KnowledgeDiscipline::Banking,
            self::GuildMedicine,
            self::PalaceUniversityMedicine,
            self::KnowledgeMedicine => KnowledgeDiscipline::Medicine,
            self::SpadeEngineering,
            self::TownEngineering,
            self::TrackEngineering => KnowledgeDiscipline::Engineering,
        };
    }

    public function scienceBonusLevelInterval(): int
    {
        return match ($this) {
            self::SchoolBanking, self::SpadeEngineering => 1,
            self::PalaceUniversityMedicine,
            self::PalaceUniversityBanking,
            self::InnovationLaw => 2,
            self::WorkshopLaw,
            self::WorkshopBanking,
            self::GuildLaw,
            self::KnowledgeMedicine,
            self::TrackEngineering => 3,
            self::GuildMedicine, self::TownEngineering => 4,
        };
    }

    /** @return array{scholars: int, power: int, books: int, spades: int, coins: int, tools: int} */
    public function scienceBonus(int $knowledgeLevel): array
    {
        $reward = ['scholars' => 0, 'power' => 0, 'books' => 0, 'spades' => 0, 'coins' => 0, 'tools' => 0];

        match ($this) {
            self::WorkshopLaw => $reward['scholars'] = intdiv($knowledgeLevel, 3),
            self::WorkshopBanking => $reward['power'] = intdiv($knowledgeLevel, 3) * 4,
            self::GuildLaw, self::KnowledgeMedicine => $reward['books'] = intdiv($knowledgeLevel, 3),
            self::GuildMedicine, self::TownEngineering => $reward['spades'] = intdiv($knowledgeLevel, 4),
            self::SchoolBanking, self::SpadeEngineering => $reward['coins'] = $knowledgeLevel,
            self::PalaceUniversityMedicine,
            self::PalaceUniversityBanking => $reward['tools'] = intdiv($knowledgeLevel, 2),
            self::TrackEngineering => $reward['scholars'] = intdiv($knowledgeLevel, 3),
            self::InnovationLaw => $reward['power'] = intdiv($knowledgeLevel, 2) * 3,
        };

        return $reward;
    }
}

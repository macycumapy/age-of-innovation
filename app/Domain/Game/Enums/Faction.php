<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum Faction: string
{
    /** Благословенные: бонус знаний рассчитывается как при уровне на 3 выше. */
    case Blessed = 'blessed';
    /** Кошачьи: город даёт 3 распределяемых шага знаний и выбранную книгу. */
    case Felines = 'felines';
    /** Гоблины: каждая использованная лопата приносит 2 монеты. */
    case Goblins = 'goblins';
    /** Иллюзионисты: действия силы дешевле и приносят победные очки. */
    case Illusionists = 'illusionists';
    /** Изобретатели: начинают с выбранной компетенцией и бонусами её стопки. */
    case Inventors = 'inventors';
    /** Ящеры: после основания города бесплатно преобразуют и строят мастерскую. */
    case Lizards = 'lizards';
    /** Кроты: прокладывают тоннели через поле и строят мосты за инструменты. */
    case Moles = 'moles';
    /** Монахи: начинают с университетом и компетенцией вместо мастерских. */
    case Monks = 'monks';
    /** Навигаторы: мастерская рядом с рекой приносит 2 ПО и монету. */
    case Navigators = 'navigators';
    /** Омар: начинает с нейтральной башней; доход фракции — 2 монеты и 2 силы. */
    case Omar = 'omar';
    /** Философы: компетенции дают книги; особое действие даёт книгу. */
    case Philosophers = 'philosophers';
    /** Провидцы: особое действие даёт 5 силы и дополнительное действие. */
    case Psychics = 'psychics';

    public function description(): string
    {
        return match ($this) {
            self::Blessed => 'Старт: по 1 шагу во всех дисциплинах. Бонус знаний рассчитывается как при уровне на 3 выше.',
            self::Felines => 'Старт: по 1 шагу в банковском деле и медицине. Город даёт 3 распределяемых шага знаний и выбранную книгу.',
            self::Goblins => 'Старт: дополнительный инструмент, по 1 шагу в банковском и инженерном деле. Каждая лопата приносит 2 монеты.',
            self::Illusionists => 'Старт: 2 шага в медицине. Действия силы стоят на 1 силу меньше и приносят 1 ПО при 2–3 игроках или 2 ПО при 4–5 игроках.',
            self::Inventors => 'Начинают с выбранной компетенцией и получают указанные у её стопки шаги знаний и книги.',
            self::Lizards => 'Старт: 2 распределяемых шага знаний. После основания города бесплатно преобразуют и строят мастерскую.',
            self::Moles => 'Старт: 2 шага в инженерном деле. Прокладывают тоннели через поле и строят мосты за инструменты.',
            self::Monks => 'Старт: 1 шаг в праве; университет и компетенция вместо мастерских.',
            self::Navigators => 'Старт: 3 шага в праве. Мастерская рядом с рекой приносит 2 ПО и монету.',
            self::Omar => 'Старт: по 1 шагу в банковском и инженерном деле; нейтральная башня. Доход фракции: 2 монеты и 2 силы.',
            self::Philosophers => 'Старт: 2 шага в банковском деле. Компетенции дают книги; особое действие даёт книгу.',
            self::Psychics => 'Старт: дополнительный инструмент, по 1 шагу в банковском деле и медицине. Особое действие даёт 5 силы и дополнительное действие.',
        };
    }

    /** @return array{victoryPoints: int, coins: int} */
    public function buildingRewards(BuildingType $buildingType, bool $isRiverBank): array
    {
        if ($this === self::Navigators
            && $buildingType === BuildingType::Workshop
            && $isRiverBank) {
            return ['victoryPoints' => 2, 'coins' => 1];
        }

        return ['victoryPoints' => 0, 'coins' => 0];
    }

    public function hasSpecialAction(): bool
    {
        return match ($this) {
            self::Moles, self::Philosophers, self::Psychics => true,
            default => false,
        };
    }

    public function specialActionId(): string
    {
        return "faction:{$this->value}";
    }

    public function scienceBonusKnowledgeLevel(int $knowledgeLevel): int
    {
        return $knowledgeLevel + ($this === self::Blessed ? 3 : 0);
    }
}

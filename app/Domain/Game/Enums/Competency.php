<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum Competency: string
{
    /** Доход: инструмент и бесплатный шаг выбранной дисциплины. */
    case Competency01 = 'competency_01';
    /** Доход: 3 ПО и 2 монеты. */
    case Competency02 = 'competency_02';
    /** Доход: выбранная книга и 1 сила. */
    case Competency03 = 'competency_03';
    /** Немедленно получить инструмент, 5 ПО и 2 монеты. */
    case Competency04 = 'competency_04';
    /** Немедленно преобразовать и построить с 2 бесплатными лопатами. */
    case Competency05 = 'competency_05';
    /** Получить 2 пристройки, повышающие силу и размер здания для города. */
    case Competency06 = 'competency_06';
    /** Особое действие: получить 4 силы. */
    case Competency07 = 'competency_07';
    /** При пасе получить по 2 ПО за каждый жетон города. */
    case Competency08 = 'competency_08';
    /** При отправке учёного получить 2 ПО. */
    case Competency09 = 'competency_09';
    /** Поставить башню силы 2; доход — 2 монеты и 2 силы. */
    case Competency10 = 'competency_10';
    /** Получать 3 ПО за мастерскую на краевом поле карты. */
    case Competency11 = 'competency_11';
    /** При пасе получить ПО по уровню слабейшей дисциплины. */
    case Competency12 = 'competency_12';

    public function description(): string
    {
        return match ($this) {
            self::Competency01 => 'Доход: инструмент и бесплатный шаг выбранной дисциплины.',
            self::Competency02 => 'Доход: 3 ПО и 2 монеты.',
            self::Competency03 => 'Доход: выбранная книга и 1 сила.',
            self::Competency04 => 'Немедленно получить инструмент, 5 ПО и 2 монеты.',
            self::Competency05 => 'Немедленно преобразовать и построить с 2 бесплатными лопатами.',
            self::Competency06 => 'Получить 2 пристройки, повышающие силу и размер здания для города.',
            self::Competency07 => 'Особое действие: получить 4 силы.',
            self::Competency08 => 'При пасе получить по 2 ПО за каждый жетон города.',
            self::Competency09 => 'При отправке учёного получить 2 ПО.',
            self::Competency10 => 'Поставить башню силы 2; доход — 2 монеты и 2 силы.',
            self::Competency11 => 'Получать 3 ПО за мастерскую на краевом поле карты.',
            self::Competency12 => 'При пасе получить ПО по уровню слабейшей дисциплины.',
        };
    }
}

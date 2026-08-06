<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum BuildingType: string
{
    /** Мастерская силы 1 для расширения территории и получения дохода. */
    case Workshop = 'workshop';
    /** Гильдия силы 2, улучшаемая до дворца или школы. */
    case Guild = 'guild';
    /** Школа силы 2, при строительстве дающая компетенцию. */
    case School = 'school';
    /** Университет силы 3, дающий компетенцию и снижающий размер города. */
    case University = 'university';
    /** Дворец силы 3 с выбранным уникальным свойством. */
    case Palace = 'palace';
    /** Нейтральная башня силы 2. */
    case Tower = 'tower';
    /** Нейтральный монумент силы 4, позволяющий город из двух зданий. */
    case Monument = 'monument';
}

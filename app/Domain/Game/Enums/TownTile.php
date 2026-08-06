<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum TownTile: string
{
    /** При получении: 4 ПО и 3 инструмента. */
    case Tools = 'tools';
    /** При получении: 5 ПО и преобразование с 2 бесплатными лопатами. */
    case Terraform = 'terraform';
    /** При получении: 5 ПО и 2 выбранные книги. */
    case Books = 'books';
    /** При получении: 6 ПО и 6 монет. */
    case Coins = 'coins';
    /** При получении: 7 ПО и по 1 шагу во всех дисциплинах. */
    case Knowledge = 'knowledge';
    /** При получении: 8 ПО и 8 силы. */
    case Power = 'power';
    /** При получении: 8 ПО и 1 учёный. */
    case Scholar = 'scholar';
}

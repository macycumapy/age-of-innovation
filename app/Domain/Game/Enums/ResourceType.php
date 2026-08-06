<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum ResourceType: string
{
    /** Деньги для оплаты строительства, улучшений и других действий. */
    case Coin = 'coin';
    /** Инструменты для оплаты зданий и покупки лопат. */
    case Tool = 'tool';
    /** Учёные для развития знаний, судоходства и преобразования. */
    case Scholar = 'scholar';
    /** Книги дисциплин для изобретений и общих книжных действий. */
    case Book = 'book';
    /** Сила, циркулирующая между тремя чашами и оплачивающая действия. */
    case Power = 'power';
    /** Временный ресурс для преобразования местности. */
    case Spade = 'spade';
    /** Победные очки, определяющие результат партии. */
    case VictoryPoint = 'victory_point';
}

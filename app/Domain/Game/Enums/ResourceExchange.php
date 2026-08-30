<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum ResourceExchange: string
{
    case PowerToScholar = 'power_to_scholar';
    case PowerToTool = 'power_to_tool';
    case PowerToCoin = 'power_to_coin';
    case ScholarToTool = 'scholar_to_tool';
    case ToolToCoin = 'tool_to_coin';
    case PowerToBook = 'power_to_book';
    case BookToCoin = 'book_to_coin';
}

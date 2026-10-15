<?php

declare(strict_types=1);

namespace App\Domain\User\Data;

final readonly class IssueTokenData
{
    /** @param list<string> $abilities */
    public function __construct(
        public string $name,
        public array $abilities,
    ) {
    }
}

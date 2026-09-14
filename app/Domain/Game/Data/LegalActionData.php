<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/** A command which is valid for at least one payload described by $parameters. */
final class LegalActionData extends Data
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $type,
        public array $parameters = [],
    ) {
    }
}

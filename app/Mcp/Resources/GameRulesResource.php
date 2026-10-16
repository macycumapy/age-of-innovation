<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('game://rules')]
#[MimeType('text/markdown')]
#[Description('Implemented game rules, scoring, economy, town connectivity and strategic planning.')]
final class GameRulesResource extends Resource
{
    public function handle(): Response
    {
        return Response::text(File::get(base_path('.agents/skills/age-of-innovation-player/references/rules-and-strategy.md')));
    }
}

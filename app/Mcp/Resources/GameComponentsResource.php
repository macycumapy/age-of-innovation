<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('game://components')]
#[MimeType('text/markdown')]
#[Description('Effects and identifiers of factions, competencies, innovations, palaces, round bonuses and town tiles.')]
final class GameComponentsResource extends Resource
{
    public function handle(): Response
    {
        return Response::text(File::get(base_path('.agents/skills/age-of-innovation-player/references/components.md')));
    }
}

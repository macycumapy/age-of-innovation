<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('game://play-guide')]
#[MimeType('text/markdown')]
#[Description('Optional full play guide. Start with game://quick-start; read this only when more detailed gameplay guidance is needed.')]
final class GamePlayGuideResource extends Resource
{
    public function handle(): Response
    {
        return Response::text(File::get(base_path('.agents/skills/age-of-innovation-player/SKILL.md')));
    }
}

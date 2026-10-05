<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Settings\Services\SettingsService;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class AdminController extends Controller
{
    public function __invoke(SettingsService $settings): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => $settings->get()->toArray(),
            'difficultyOptions' => array_map(
                fn (GameBotDifficulty $difficulty): array => ['value' => $difficulty->value, 'label' => $difficulty->title()],
                GameBotDifficulty::cases(),
            ),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\Actions\UpdateSettingsAction;
use App\Http\Requests\UpdateAdminSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class AdminSettingsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateAdminSettingsRequest $request, UpdateSettingsAction $updateSettings): RedirectResponse
    {
        $updateSettings->execute($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Настройки сохранены.']);

        return to_route('admin.settings.edit');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\User\Actions\UpdateUserPasswordAction;
use App\Http\Requests\UpdateAdminUserPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class AdminUserPasswordController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        UpdateAdminUserPasswordRequest $request,
        User $user,
        UpdateUserPasswordAction $updateUserPassword,
    ): RedirectResponse {
        $updateUserPassword->execute($user, $request->validated('password'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Пароль пользователя изменён.']);

        return back();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

final class AdminUserController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => AdminUserResource::collection(User::query()
                ->select(['id', 'name', 'email', 'created_at'])
                ->latest('id')
                ->paginate(20)),
        ]);
    }
}

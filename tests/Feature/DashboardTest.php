<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertInertia(
            fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('sidebarOpen', false)
            ->where('board.variant', 'three_to_five_players')
            ->has('board.hexes', 81)
        );
    }

    public function test_sidebar_can_be_opened_by_default_from_the_saved_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withCookie('sidebar_state', 'true')
            ->get(route('dashboard'));

        $response->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('sidebarOpen', true),
        );
    }
}

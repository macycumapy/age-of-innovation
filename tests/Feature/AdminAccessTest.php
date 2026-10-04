<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    public function test_configured_user_can_access_admin(): void
    {
        $admin = User::factory()->create();
        config(['auth.admin_user_id' => (string) $admin->id]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Index')
                ->where('horizonUrl', route('horizon.index'))
                ->where('auth.canAccessAdmin', true));
    }

    public function test_other_user_cannot_access_admin(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        config(['auth.admin_user_id' => (string) $admin->id]);

        $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
        $this->get(route('games.index'))->assertInertia(fn (Assert $page) => $page
            ->where('horizonUrl', null)
            ->where('auth.canAccessAdmin', false));
    }

    public function test_admin_access_is_denied_when_configuration_is_empty_or_invalid(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ([null, '', '0', 'invalid', $user->id.'invalid'] as $adminUserId) {
            config(['auth.admin_user_id' => $adminUserId]);

            $this->get(route('admin.index'))->assertForbidden();
        }
    }

    public function test_changing_configuration_revokes_previous_admin_access(): void
    {
        $previousAdmin = User::factory()->create();
        $newAdmin = User::factory()->create();
        config(['auth.admin_user_id' => (string) $previousAdmin->id]);
        $this->actingAs($previousAdmin)->get(route('admin.index'))->assertOk();

        config(['auth.admin_user_id' => (string) $newAdmin->id]);

        $this->get(route('admin.index'))->assertForbidden();
        $this->actingAs($newAdmin)->get(route('admin.index'))->assertOk();
    }

    public function test_only_admin_can_access_horizon_including_local_environment(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        config(['auth.admin_user_id' => (string) $admin->id]);

        foreach (['local', 'production'] as $environment) {
            $this->app->instance('env', $environment);

            $this->get(route('horizon.index'))->assertForbidden();
            $this->actingAs($user)->get(route('horizon.index'))->assertForbidden();
            $this->actingAs($user)->get(route('horizon.stats.index'))->assertForbidden();
            $this->actingAs($admin)->get(route('horizon.index'))->assertOk();
            $this->assertTrue($admin->can('viewHorizon'));
            $this->assertFalse($user->can('viewHorizon'));

            config(['auth.admin_user_id' => null]);
            $this->get(route('horizon.index'))->assertForbidden();
            config(['auth.admin_user_id' => (string) $admin->id]);
            $this->app['auth']->forgetGuards();
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_games(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('games.index'));
    }

    public function test_dashboard_is_hidden(): void
    {
        $this->get('/dashboard')->assertNotFound();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Jobs\PlayAutomatedTurnJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HorizonConfigurationTest extends TestCase
{
    #[Test]
    public function it_runs_default_and_bot_turn_queues_in_separate_supervisors(): void
    {
        $this->assertSame(['default'], config('horizon.defaults.default.queue'));
        $this->assertSame([PlayAutomatedTurnJob::QUEUE], config('horizon.defaults.bot-turns.queue'));
        $this->assertSame('redis', config('horizon.defaults.default.connection'));
        $this->assertSame('redis', config('horizon.defaults.bot-turns.connection'));
        $this->assertSame(1, config('horizon.environments.production.default.maxProcesses'));
        $this->assertSame(1, config('horizon.environments.production.bot-turns.maxProcesses'));
    }
}

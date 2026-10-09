<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\GameTreeSearchContext;
use App\Domain\Automation\Enums\GameActionSearchLimit;
use PHPUnit\Framework\TestCase;

class GameTreeSearchContextTest extends TestCase
{
    public function test_it_records_the_node_limit(): void
    {
        $context = new GameTreeSearchContext(2, 60_000);
        $context->visitedNodes = 2;
        $this->assertTrue($context->isExhausted());
        $this->assertSame(GameActionSearchLimit::Nodes, $context->timings->exhaustedBy);
    }

    public function test_it_records_the_time_limit_and_preserves_the_first_reason(): void
    {
        $context = new GameTreeSearchContext(2, 0);
        $this->assertTrue($context->isExhausted());
        $this->assertSame(GameActionSearchLimit::Time, $context->timings->exhaustedBy);
        $context->visitedNodes = 2;
        $context->isExhausted();
        $this->assertSame(GameActionSearchLimit::Time, $context->timings->exhaustedBy);
    }

    public function test_it_records_both_limits_if_both_are_reached_at_first_check(): void
    {
        $context = new GameTreeSearchContext(2, 0);
        $context->visitedNodes = 2;
        $this->assertTrue($context->isExhausted());
        $this->assertSame(GameActionSearchLimit::NodesAndTime, $context->timings->exhaustedBy);
    }

    public function test_it_does_not_report_a_limit_for_an_unexhausted_context(): void
    {
        $context = new GameTreeSearchContext(2, 60_000);
        $this->assertFalse($context->isExhausted());
        $this->assertNull($context->timings->exhaustedBy);
    }
}

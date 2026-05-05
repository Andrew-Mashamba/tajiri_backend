<?php

namespace Tests\Unit;

use App\Services\MultiplierEngine;
use PHPUnit\Framework\TestCase;

class MultiplierEngineTest extends TestCase
{
    public function test_watch_completion_branches(): void
    {
        $this->assertSame(0.5, MultiplierEngine::watchCompletion(0.10, 'view'));
        $this->assertSame(0.5, MultiplierEngine::watchCompletion(0.24, 'view'));
        $this->assertSame(1.0, MultiplierEngine::watchCompletion(0.25, 'view'));
        $this->assertSame(1.0, MultiplierEngine::watchCompletion(0.49, 'view'));
        $this->assertSame(1.5, MultiplierEngine::watchCompletion(0.50, 'view'));
        $this->assertSame(1.5, MultiplierEngine::watchCompletion(0.69, 'view'));
        $this->assertSame(2.0, MultiplierEngine::watchCompletion(0.70, 'view'));
        $this->assertSame(2.0, MultiplierEngine::watchCompletion(0.89, 'view'));
        $this->assertSame(2.5, MultiplierEngine::watchCompletion(0.90, 'view'));
        $this->assertSame(2.5, MultiplierEngine::watchCompletion(1.00, 'view'));
    }

    public function test_watch_completion_only_applies_to_video_metrics(): void
    {
        $this->assertNull(MultiplierEngine::watchCompletion(0.95, 'reaction'));
        $this->assertNull(MultiplierEngine::watchCompletion(0.95, 'comment'));
        $this->assertSame(2.5, MultiplierEngine::watchCompletion(0.95, 'view'));
        $this->assertSame(2.5, MultiplierEngine::watchCompletion(0.95, 'watch_second'));
    }

    public function test_originality_flag_branches(): void
    {
        $this->assertSame(1.0, MultiplierEngine::originality('original'));
        $this->assertSame(0.7, MultiplierEngine::originality('derivative_substantial'));
        $this->assertSame(0.4, MultiplierEngine::originality('derivative_minimal'));
        $this->assertSame(0.0, MultiplierEngine::originality('reused'));
        $this->assertSame(1.0, MultiplierEngine::originality('unknown_flag'));
    }

    public function test_discovery_mode(): void
    {
        $this->assertSame(0.70, MultiplierEngine::discoveryMode(true));
        $this->assertNull(MultiplierEngine::discoveryMode(false));
    }

    public function test_tier_boost_only_for_partner(): void
    {
        $this->assertNull(MultiplierEngine::tierBoost('mwanzo'));
        $this->assertNull(MultiplierEngine::tierBoost('standard'));
        $this->assertNull(MultiplierEngine::tierBoost('verified'));
        $this->assertSame(1.05, MultiplierEngine::tierBoost('partner'));
    }

    public function test_combined_multiplies_all_factors(): void
    {
        $this->assertEquals(
            1.0 * 2.0 * 1.5 * 1.10,
            MultiplierEngine::combined([
                'a' => 1.0,
                'b' => 2.0,
                'c' => 1.5,
                'd' => 1.10,
            ]),
            '',
            0.0001
        );
    }

    public function test_combined_treats_null_as_one(): void
    {
        $this->assertEquals(
            2.0,
            MultiplierEngine::combined([
                'a' => 2.0,
                'b' => null,
                'c' => null,
            ]),
            '',
            0.0001
        );
    }
}

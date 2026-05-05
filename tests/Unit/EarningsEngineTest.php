<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarningsEngineTest extends TestCase
{
    use RefreshDatabase;

    /** @test (skeleton — full assertions in Task 78) */
    public function it_loads_the_engine_class(): void
    {
        $this->assertTrue(class_exists(\App\Services\EarningsEngine::class));
        $this->assertTrue(class_exists(\App\Services\Earnings\EarningEventDto::class));
        $this->assertTrue(class_exists(\App\Services\AbuseGuard::class));
        $this->assertTrue(class_exists(\App\Services\MultiplierEngine::class));
        $this->assertTrue(class_exists(\App\Services\CreatorEarningsRateRegistry::class));
    }
}

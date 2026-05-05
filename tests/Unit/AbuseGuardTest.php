<?php

namespace Tests\Unit;

use App\Services\AbuseGuard;
use App\Services\Earnings\EarningEventDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbuseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_action_excluded(): void
    {
        $dto = new EarningEventDto();
        $dto->actorUserId = 1;
        $dto->stream = 'engagement';
        $dto->metric = 'view';

        $result = AbuseGuard::check($dto, ['target_user_id' => 1]);
        $this->assertFalse($result['is_chargeable']);
        $this->assertSame('self_action', $result['charge_reason']);
    }

    public function test_cross_user_view_passes(): void
    {
        $dto = new EarningEventDto();
        $dto->actorUserId = 1;
        $dto->postId = 100;
        $dto->stream = 'engagement';
        $dto->metric = 'view';

        $result = AbuseGuard::check($dto, ['target_user_id' => 2]);
        $this->assertTrue($result['is_chargeable']);
        $this->assertNull($result['charge_reason']);
    }
}

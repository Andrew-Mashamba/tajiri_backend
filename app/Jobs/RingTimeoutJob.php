<?php

namespace App\Jobs;

use App\Events\CallEnded;
use App\Models\Call;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RingTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $callId
    ) {}

    public function handle(): void
    {
        $call = Call::find($this->callId);

        if (!$call) {
            return;
        }

        // Only timeout if still ringing
        if ($call->status !== Call::STATUS_RINGING) {
            return;
        }

        $call->miss();

        broadcast(new CallEnded($call->call_id, $call->caller_id, 'no_answer'));

        // Send FCM notification for missed call
        SendCallNotification::dispatch($call->fresh(), 'call_missed');
    }
}

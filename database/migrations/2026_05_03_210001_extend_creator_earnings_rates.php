<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_earnings_rates', function (Blueprint $table) {
            // Drop the unique on metric — composite (metric, actor_role, stream, effective_from)
            // becomes the new natural key.
            $table->dropUnique(['metric']);

            $table->string('actor_role')->default('author')->after('metric');
            $table->string('stream')->default('engagement')->after('actor_role');
            $table->timestampTz('effective_from')->default(DB::raw('CURRENT_TIMESTAMP'))->after('rate');
            $table->timestampTz('effective_until')->nullable()->after('effective_from');
            $table->string('tier_minimum')->nullable()->after('effective_until'); // 'mwanzo'|'standard'|'verified'|'partner'
            $table->decimal('max_cap_tsh', 10, 4)->nullable()->after('tier_minimum'); // strategy §10.3 per-event cap

            $table->unique(['metric', 'actor_role', 'stream', 'effective_from'], 'cer_natural_key');
            $table->index(['stream', 'metric', 'is_active']);
        });

        // Re-seed the expanded rate matrix per strategy §2 (B+C attribution) + §10.3 (caps).
        $now = now();
        $rows = [
            // Engagement stream — primary author rates
            ['metric' => 'view',         'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 0.50, 'max_cap_tsh' => 5.00,   'tier_minimum' => 'mwanzo'],
            ['metric' => 'reaction',     'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 2.00, 'max_cap_tsh' => 50.00,  'tier_minimum' => 'mwanzo'],
            ['metric' => 'comment',      'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 2.50, 'max_cap_tsh' => 100.00, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'reply',        'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 2.50, 'max_cap_tsh' => 100.00, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'share',        'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 5.00, 'max_cap_tsh' => 200.00, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'save',         'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 3.00, 'max_cap_tsh' => 50.00,  'tier_minimum' => 'mwanzo'],
            ['metric' => 'watch_second', 'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 0.10, 'max_cap_tsh' => 1.00,   'tier_minimum' => 'mwanzo'],

            // Engagement stream — comment_author secondary credit (§2.1 reaction-on-comment)
            ['metric' => 'comment_reaction', 'actor_role' => 'comment_author', 'stream' => 'engagement', 'rate' => 1.00, 'max_cap_tsh' => 30.00, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'reply',            'actor_role' => 'comment_author', 'stream' => 'engagement', 'rate' => 0.75, 'max_cap_tsh' => 30.00, 'tier_minimum' => 'mwanzo'],

            // Engagement stream — host_share (post author when their comment section earns) (§2.1)
            ['metric' => 'comment_reaction', 'actor_role' => 'host', 'stream' => 'engagement', 'rate' => 0.25, 'max_cap_tsh' => 10.00, 'tier_minimum' => 'mwanzo'],

            // Engagement stream — sharer discovery credits (§2.1)
            ['metric' => 'view',     'actor_role' => 'sharer', 'stream' => 'engagement', 'rate' => 0.10, 'max_cap_tsh' => 2.00,  'tier_minimum' => 'mwanzo'],
            ['metric' => 'reaction', 'actor_role' => 'sharer', 'stream' => 'engagement', 'rate' => 0.40, 'max_cap_tsh' => 10.00, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'share',    'actor_role' => 'sharer', 'stream' => 'engagement', 'rate' => 1.00, 'max_cap_tsh' => 40.00, 'tier_minimum' => 'mwanzo'],

            // Engagement stream — derivative royalty (§2.2)
            ['metric' => 'derivative_royalty', 'actor_role' => 'original_creator_royalty', 'stream' => 'engagement', 'rate' => 0.30, 'max_cap_tsh' => 500.00, 'tier_minimum' => 'mwanzo'],

            // Engagement stream — discovery (§2.1: follow / subscribe from post)
            ['metric' => 'follow_from_post',    'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 10.00, 'max_cap_tsh' => 50.00,  'tier_minimum' => 'mwanzo'],
            ['metric' => 'subscribe_from_post', 'actor_role' => 'author', 'stream' => 'engagement', 'rate' => 50.00, 'max_cap_tsh' => 200.00, 'tier_minimum' => 'mwanzo'],

            // Live gifts stream — 90/10 (§1.1 stream 5)
            ['metric' => 'live_gift',     'actor_role' => 'author', 'stream' => 'live_gifts',  'rate' => 0.90, 'max_cap_tsh' => null,  'tier_minimum' => 'standard'],
            ['metric' => 'super_chat',    'actor_role' => 'author', 'stream' => 'live_gifts',  'rate' => 0.90, 'max_cap_tsh' => null,  'tier_minimum' => 'standard'],
            ['metric' => 'live_reaction', 'actor_role' => 'author', 'stream' => 'engagement',  'rate' => 1.50, 'max_cap_tsh' => 20.00, 'tier_minimum' => 'mwanzo'],

            // Marketplace stream — 100% Mwanzo (first 90 days), 95% after — handled in service via tier check (§1.1 stream 3)
            ['metric' => 'marketplace_sale', 'actor_role' => 'author', 'stream' => 'marketplace', 'rate' => 1.00, 'max_cap_tsh' => null, 'tier_minimum' => 'mwanzo'],

            // Fan-funding stream — 95/5 (§1.1 stream 2)
            ['metric' => 'subscription', 'actor_role' => 'author', 'stream' => 'fan_funding', 'rate' => 0.95, 'max_cap_tsh' => null, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'tip',          'actor_role' => 'author', 'stream' => 'fan_funding', 'rate' => 0.95, 'max_cap_tsh' => null, 'tier_minimum' => 'mwanzo'],
            ['metric' => 'michango',     'actor_role' => 'author', 'stream' => 'fan_funding', 'rate' => 0.95, 'max_cap_tsh' => null, 'tier_minimum' => 'mwanzo'],

            // Brand-deal stream — 90/10 (§1.1 stream 4)
            ['metric' => 'brand_deal', 'actor_role' => 'author', 'stream' => 'brand_deal', 'rate' => 0.90, 'max_cap_tsh' => null, 'tier_minimum' => 'verified'],
        ];

        // Wipe the v0 seed (which only had `metric` populated) and write the new matrix.
        DB::table('creator_earnings_rates')->truncate();
        foreach ($rows as $r) {
            DB::table('creator_earnings_rates')->insert(array_merge($r, [
                'is_active'      => true,
                'effective_from' => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::table('creator_earnings_rates', function (Blueprint $table) {
            $table->dropUnique('cer_natural_key');
            $table->dropIndex(['stream', 'metric', 'is_active']);
            $table->dropColumn(['actor_role', 'stream', 'effective_from', 'effective_until', 'tier_minimum', 'max_cap_tsh']);
            $table->unique('metric');
        });
    }
};

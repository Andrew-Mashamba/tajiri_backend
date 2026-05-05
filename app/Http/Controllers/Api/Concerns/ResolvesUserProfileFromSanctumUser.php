<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ResolvesUserProfileFromSanctumUser
{
    /**
     * Resolve the creator user_id for the request.
     *
     * Order of precedence:
     *   1. ?user_id query/body param (existing pattern in this codebase)
     *   2. authenticated Sanctum user → look up user_profiles.id by phone
     *
     * Returns null if neither is present.
     */
    protected function resolveCreatorUserId(Request $request): ?int
    {
        if ($request->filled('user_id')) {
            return (int) $request->input('user_id');
        }
        if ($request->user()) {
            $profile = DB::table('user_profiles')
                ->where('phone_number', $request->user()->phone)
                ->first();
            return $profile ? (int) $profile->id : null;
        }
        return null;
    }
}

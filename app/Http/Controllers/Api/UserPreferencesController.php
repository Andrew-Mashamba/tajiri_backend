<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPreferencesController extends Controller
{
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'opt_out_sponsored' => 'sometimes|boolean',
            'opt_out_collaboration' => 'sometimes|boolean',
            'opt_out_battles' => 'sometimes|boolean',
            'opt_out_threads' => 'sometimes|boolean',
        ]);

        DB::table('user_profiles')->where('id', $id)->update($validated);

        return response()->json(['data' => ['success' => true]]);
    }
}

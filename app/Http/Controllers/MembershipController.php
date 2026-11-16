<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function plans(): JsonResponse
    {
        $plan = config('membership.vip_plan');

        return response()->json([
            'plans' => [[
                'id' => $plan['id'],
                'name' => $plan['name'],
                'price' => $plan['price'],
                'duration_label' => $plan['duration_label'],
                'benefits' => $plan['benefits'],
            ]],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $membership = Membership::query()->where('user_id', $request->user()->id)->first();
        if (! $membership) {
            return response()->json([
                'membership' => ['tier' => 'free', 'status' => 'active', 'expires_at' => null],
            ]);
        }

        return response()->json([
            'membership' => [
                'tier' => $membership->tier,
                'status' => $membership->expires_at->isFuture() ? 'active' : 'expired',
                'expires_at' => $membership->expires_at->toISOString(),
            ],
        ]);
    }
}

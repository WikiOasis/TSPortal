<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Safety\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->orderBy('username')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'real_name' => $u->real_name,
                'central_id' => $u->mw_central_id,
                'flags' => $u->flags ?? [],
                'granted_flags' => $u->granted_flags ?? [],
                'from_groups' => array_values(array_diff($u->flags ?? [], $u->granted_flags ?? [])),
                'mw_groups' => $u->mw_groups ?? [],
                'active' => $u->active,
                'last_login' => $u->last_login_at?->toIso8601String(),
                'public_label' => $u->publicLabel(),
            ])->all(),
            'available_flags' => User::FLAGS,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'granted_flags' => ['nullable', 'array'],
            'granted_flags.*' => ['string', 'in:'.implode(',', User::FLAGS)],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($user->is($request->user())) {
            return response()->json([
                'error' => 'self',
                'message' => 'You cannot change your own flags. Ask someone else holding user-manager.',
            ], 422);
        }

        if (array_key_exists('granted_flags', $data)) {
            $user->granted_flags = array_values(array_unique($data['granted_flags'] ?? []));
            $user->syncGroupFlags($user->mw_groups ?? []);
        }

        if (array_key_exists('active', $data)) {
            $user->active = (bool) $data['active'];
        }

        $user->save();

        Audit::log('staff.updated', $user, [
            'granted_flags' => $user->granted_flags,
            'active' => $user->active,
        ]);

        return response()->json(['ok' => true, 'flags' => $user->flags]);
    }
}

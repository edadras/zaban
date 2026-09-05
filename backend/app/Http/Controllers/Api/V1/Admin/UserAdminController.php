<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAdminController extends ApiController
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with(['learnerProfile.cefrLevel'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return $this->ok($users->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'status' => $u->status,
            'cefr' => $u->learnerProfile?->cefrLevel?->code,
            'placement_status' => $u->learnerProfile?->placement_status,
            'streak_days' => $u->learnerProfile?->streak_days,
            'last_active_at' => $u->last_active_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ]));
    }

    public function show(Request $request, User $user)
    {
        $user->load(['profile', 'settings', 'learnerProfile.cefrLevel', 'subscriptions.plan']);

        return $this->ok([
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role, 'status' => $user->status,
            ],
            'learner' => [
                'cefr' => $user->learnerProfile?->cefrLevel?->code,
                'ability' => (float) ($user->learnerProfile->ability ?? 0),
                'mastery_score' => (float) ($user->learnerProfile->mastery_score ?? 0),
                'xp' => (int) ($user->learnerProfile->xp ?? 0),
                'streak_days' => (int) ($user->learnerProfile->streak_days ?? 0),
                'concepts_tracked' => DB::table('learner_concepts')->where('user_id', $user->id)->count(),
            ],
            'subscription' => $user->subscriptions->sortByDesc('id')->first(),
            'activity' => [
                'sessions' => DB::table('learning_sessions')->where('user_id', $user->id)->count(),
                'exercise_attempts' => DB::table('exercise_attempts')->where('user_id', $user->id)->count(),
                'speech_attempts' => DB::table('speech_attempts')->where('user_id', $user->id)->count(),
                'ai_cost' => round((float) DB::table('ai_usage')->where('user_id', $user->id)->sum('estimated_cost'), 4),
            ],
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['sometimes', 'in:learner,admin,editor,reviewer'],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        // An admin must not be able to lock themselves out or self-promote
        // without a second pair of hands.
        if ($user->id === $request->user()->id && isset($data['role']) && $data['role'] !== $user->role) {
            return $this->fail('self_role_change', 'You cannot change your own role.', 422);
        }

        $before = $user->only(['role', 'status']);
        $user->update($data);
        if (($data['status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.update',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'before' => $before,
            'after' => $user->only(['role', 'status']),
            'ip_address' => $request->ip(),
        ]);

        return $this->ok(['id' => $user->id, 'role' => $user->role, 'status' => $user->status]);
    }

    public function auditLog(Request $request)
    {
        return $this->ok(AuditLog::with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 50))));
    }
}

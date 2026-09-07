<?php

namespace App\Http\Controllers\Panel;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The platform, as opposed to a school.
 *
 * Only for accounts carrying a platform role. This is the half of the panel a
 * school's owner never sees, and the numbers here are read from the same tables
 * the admin API reads - the page is a second window onto them, not a second
 * source of truth.
 */
class PlatformController extends PanelController
{
    public function overview()
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        return view('panel.platform.overview', [
            'users' => User::count(),
            'learners' => User::where('role', 'learner')->count(),
            'suspended' => User::where('status', 'suspended')->count(),
            'newThisWeek' => User::where('created_at', '>=', now()->subWeek())->count(),
            'activeToday' => User::where('last_active_at', '>=', now()->startOfDay())->count(),
            'schools' => School::count(),
            'sessionsToday' => DB::table('learning_sessions')
                ->where('created_at', '>=', now()->startOfDay())->count(),
            'liveSubscriptions' => DB::table('subscriptions')->where('status', 'active')->count(),
            'pendingPayments' => DB::table('manual_payment_submissions')
                ->where('status', 'pending')->count(),
            'reviewQueue' => DB::table('content_reviews')->where('status', 'pending')->count(),
            'aiCost30' => round((float) DB::table('ai_usage')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('estimated_cost'), 2),
        ]);
    }

    public function users(Request $request)
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        $users = User::with('learnerProfile.cefrLevel')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('panel.platform.users', ['users' => $users]);
    }

    public function updateUser(Request $request, User $user)
    {
        $this->allow($this->me()->role === 'admin', 'تغییر نقش تنها از عهدهٔ مدیر سامانه برمی‌آید.');

        $data = $request->validate([
            'role' => ['required', 'in:learner,admin,editor,reviewer'],
            'status' => ['required', 'in:active,suspended'],
        ]);

        // An admin must not be able to lock themselves out or change their own
        // role without a second pair of hands.
        if ($user->id === $this->me()->id && $data['role'] !== $user->role) {
            return back()->withErrors(['role' => 'نقش خودتان را نمی‌توانید عوض کنید.']);
        }

        $before = $user->only(['role', 'status']);
        $user->update($data);

        if ($data['status'] === 'suspended') {
            $user->tokens()->delete();
        }

        AuditLog::create([
            'user_id' => $this->me()->id,
            'action' => 'user.update',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'before' => $before,
            'after' => $user->only(['role', 'status']),
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', 'حساب به‌روز شد.');
    }

    public function audit()
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        return view('panel.platform.audit', [
            'entries' => AuditLog::with('user:id,name,email')->orderByDesc('id')->paginate(50),
        ]);
    }
}

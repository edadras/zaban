<?php

namespace App\Http\Controllers\Panel;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\SchoolService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The platform, as opposed to a school.
 *
 * Only for accounts carrying a platform role. This is the half of the panel a
 * school's owner never sees, and the numbers here are read from the same tables
 * the admin API reads - the page is a second window onto them, not a second
 * source of truth.
 *
 * School onboarding also lives here: only the site administrator registers a
 * school and its manager; the manager then runs that school from the school
 * half of the panel.
 */
class PlatformController extends PanelController
{
    public function __construct(PanelAccess $access, private readonly SchoolService $schools)
    {
        parent::__construct($access);
    }

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

    public function schools()
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        $schools = School::with('owner')
            ->withCount([
                'members as coach_count' => fn ($q) => $q->where('role', 'coach')->where('status', 'active'),
                'members as student_count' => fn ($q) => $q->where('role', 'student')->where('status', 'active'),
                'classGroups',
            ])
            ->orderBy('name')
            ->get();

        return view('panel.platform.schools', [
            'schools' => $schools,
            'canRegister' => $this->me()->role === 'admin',
        ]);
    }

    public function storeSchool(Request $request)
    {
        $this->allow($this->me()->role === 'admin', 'ثبت آموزشگاه تنها از عهدهٔ مدیر سامانه برمی‌آید.');

        $email = mb_strtolower((string) $request->input('owner_email'));
        $ownerExists = User::where('email', $email)->exists();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
            'owner_password' => [
                Rule::requiredIf(! $ownerExists),
                'nullable',
                'string',
                'confirmed',
                Password::defaults(),
            ],
        ]);

        try {
            $result = $this->schools->registerForPlatform(
                $data['name'],
                $data['owner_name'],
                $data['owner_email'],
                $data['owner_password'] ?? null,
                [
                    'description' => $data['description'] ?? null,
                    'timezone' => $data['timezone'] ?? 'Asia/Tehran',
                ],
            );
        } catch (ClassroomException $e) {
            return back()->withInput()->withErrors(['owner_email' => match ($e->getMessage()) {
                'That account is suspended.' => 'این حساب معلق است.',
                'A password is required when the manager does not have an account yet.' => 'برای مدیر تازه‌کار گذرواژه لازم است.',
                default => $e->getMessage(),
            }]);
        }

        AuditLog::create([
            'user_id' => $this->me()->id,
            'action' => 'school.register',
            'auditable_type' => School::class,
            'auditable_id' => $result['school']->id,
            'before' => null,
            'after' => [
                'school' => $result['school']->name,
                'owner_id' => $result['owner']->id,
                'owner_email' => $result['owner']->email,
                'created_owner' => $result['created_owner'],
            ],
            'ip_address' => $request->ip(),
        ]);

        $status = $result['created_owner']
            ? 'آموزشگاه و حساب مدیر ساخته شد. مدیر می‌تواند وارد پنل شود.'
            : 'آموزشگاه ساخته شد و به حساب موجود مدیر سپرده شد.';

        return redirect()->route('panel.platform.schools')->with('status', $status);
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\LearnerProfile;
use App\Models\Language;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserSetting;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'learner',
                'status' => 'active',
                'timezone' => $data['timezone'] ?? 'UTC',
                'locale' => $data['locale'] ?? 'en',
            ]);

            $target = Language::where('code', $data['target_language'] ?? 'en')->first();
            $native = isset($data['native_language'])
                ? Language::where('code', $data['native_language'])->first()
                : null;

            UserProfile::create([
                'user_id' => $user->id,
                'native_language_id' => $native?->id,
                'target_language_id' => $target?->id,
                'country_code' => $data['country_code'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'learning_objective' => $data['learning_objective'] ?? null,
            ]);

            UserSetting::create([
                'user_id' => $user->id,
                'daily_target_minutes' => $data['daily_target_minutes'] ?? 15,
                'weekly_goal_minutes' => ($data['daily_target_minutes'] ?? 15) * 7,
            ]);

            // The learner profile is created up front so placement has somewhere
            // to write its result the moment the learner starts.
            LearnerProfile::create([
                'user_id' => $user->id,
                'language_id' => $target?->id ?? Language::where('code', 'en')->value('id'),
                'placement_status' => 'not_started',
            ]);

            return $user;
        });

        event(new Registered($user));

        return $this->created([
            'user' => new UserResource($user->load('profile', 'settings', 'learnerProfile')),
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
        ]);
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            // One message for both cases: revealing which half was wrong helps
            // account enumeration.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return $this->fail('account_inactive', 'This account is not active.', 403);
        }

        $user->forceFill(['last_active_at' => now()])->save();

        return $this->ok([
            'user' => new UserResource($user->load('profile', 'settings', 'learnerProfile')),
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(['message' => 'Signed out.']);
    }

    public function me(Request $request)
    {
        return $this->ok(new UserResource(
            $request->user()->load('profile', 'settings', 'learnerProfile.cefrLevel')
        ));
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        // Always report success: a differing response reveals which addresses
        // are registered.
        return $this->ok(['message' => 'If that address exists, a reset link is on its way.'], [
            'status' => $status,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                // Every existing session is invalidated on a password reset.
                $user->tokens()->delete();
            },
        );

        return $status === Password::PASSWORD_RESET
            ? $this->ok(['message' => 'Password updated.'])
            : $this->fail('reset_failed', __($status), 422);
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->fail('invalid_verification', 'This verification link is not valid.', 403);
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $this->ok(['message' => 'Email verified.']);
    }

    public function resendVerification(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->ok(['message' => 'Already verified.']);
        }
        $request->user()->sendEmailVerificationNotification();

        return $this->ok(['message' => 'Verification email sent.']);
    }

    private function deviceName(Request $request): string
    {
        return Str::limit($request->userAgent() ?? 'api', 80, '');
    }
}

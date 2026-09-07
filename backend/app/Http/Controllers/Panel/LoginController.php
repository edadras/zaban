<?php

namespace App\Http\Controllers\Panel;

use App\Support\PanelAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to the panel.
 *
 * Deliberately its own controller and not behind the panel middleware: the
 * login page is the one page a person who is not yet anybody may look at.
 */
class LoginController extends Controller
{
    public function __construct(private readonly PanelAccess $access) {}

    public function show(Request $request)
    {
        if ($request->user() && $this->access->isPanelUser($request->user())) {
            return redirect()->route('panel.home');
        }

        return view('panel.auth.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // The same throttle the API's login carries, keyed on the pair so one
        // person's failures cannot lock a colleague out.
        $key = 'panel-login:'.mb_strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'تلاش‌های ناموفق زیاد بوده است. '
                    .ceil(RateLimiter::availableIn($key) / 60).' دقیقه دیگر دوباره تلاش کنید.',
            ]);
        }

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'email' => 'ایمیل یا گذرواژه درست نیست.',
            ]);
        }

        if (! $this->access->isPanelUser($request->user())) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'این حساب مدیر آموزشگاه یا مربی نیست. برای یادگیری از اپلیکیشن استفاده کنید.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('panel.home'));
    }

    public function destroy(Request $request)
    {
        // The live-room key is issued to a browser session, so it dies with it.
        $request->user()?->tokens()->where('name', 'panel-room')->delete();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }
}

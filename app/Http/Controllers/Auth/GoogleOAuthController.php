<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'google' => 'Google sign-in is not configured yet.',
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'google' => 'Google sign-in is not configured yet.',
            ]);
        }

        $sessionIdBefore = $request->session()->getId();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google OAuth: callback exception', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'session_id_before' => $sessionIdBefore,
                'has_state_in_session' => $request->session()->has('state'),
                'state_in_request'     => $request->get('state') ? 'present' : 'missing',
                'has_error_in_request' => $request->has('error') ? $request->get('error') : 'none',
            ]);

            return redirect()->route('login')->withErrors([
                'google' => 'Google sign-in could not be completed. Please try again.',
            ]);
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'google' => 'Google did not return an email address for this account.',
            ]);
        }

        $googleId = trim((string) $googleUser->getId());
        $displayName = trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: Str::before($email, '@')));
        $avatar = trim((string) $googleUser->getAvatar());

        $user = null;

        if ($googleId !== '') {
            $user = User::query()
                ->where('google_id', $googleId)
                ->first();
        }

        if ($user === null) {
            $user = User::query()
                ->where('email', $email)
                ->first();
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $displayName !== '' ? $displayName : 'Google User',
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(40)),
                'google_id' => $googleId !== '' ? $googleId : null,
                'google_avatar' => $avatar !== '' ? $avatar : null,
            ]);
        } else {
            $updates = [];

            if ($googleId !== '' && $user->google_id !== $googleId) {
                $updates['google_id'] = $googleId;
            }

            if ($avatar !== '' && $user->google_avatar !== $avatar) {
                $updates['google_avatar'] = $avatar;
            }

            if ($user->email_verified_at === null) {
                $updates['email_verified_at'] = now();
            }

            if (trim((string) $user->name) === '' && $displayName !== '') {
                $updates['name'] = $displayName;
            }

            if ($updates !== []) {
                $user->update($updates);
            }
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}

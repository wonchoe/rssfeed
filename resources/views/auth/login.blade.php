@extends('layouts.app')

@section('title', 'Sign In · rss.cursor.style')
@section('body_class', 'page-auth')

@section('content')
    <section class="auth-shell">
        <aside class="auth-stage">
            <div class="auth-stage-grid">
                <div class="grid" style="gap: 14px;">
                    <p class="eyebrow">Sign In</p>
                    <h1 class="section-heading" style="color: #f8fafc;">Step back into your feed pipeline without friction.</h1>
                    <p class="section-copy">Open the dashboard, check previews, link destinations, and keep new article delivery moving in real time.</p>
                </div>

                <div class="auth-highlights">
                    <article class="auth-highlight">
                        <span class="auth-highlight-mark">A</span>
                        <div>
                            <strong>Google sign-in when you want speed</strong>
                            <p>Jump in with one click and let the same verified email power both auth and account recovery.</p>
                        </div>
                    </article>

                    <article class="auth-highlight">
                        <span class="auth-highlight-mark">B</span>
                        <div>
                            <strong>Email login still works in parallel</strong>
                            <p>Your existing credentials stay valid, even after you later connect Google to the same account.</p>
                        </div>
                    </article>
                </div>
            </div>
        </aside>

        <div class="panel auth-card">
            <div class="auth-card-head">
                <h1 class="auth-title">Sign In</h1>
                <p class="muted" style="margin: 0;">Access your ingestion dashboard and keep your Telegram feeds under control.</p>
            </div>

            @include('auth.partials.google-auth-cta')

            @if (filled(config('services.google.client_id')) && filled(config('services.google.client_secret')))
                <div class="oauth-divider" style="margin: 22px 0;">or continue with email</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="auth-form">
                @csrf

                <div>
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@example.com">
                </div>

                <div>
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Your password">
                </div>

                <label style="display:flex; gap:10px; align-items:center; margin:0; color: var(--muted); font-size: 13px; font-weight: 700;">
                    <input style="width:auto;" type="checkbox" name="remember" value="1">
                    <span>Remember me on this device</span>
                </label>

                <button type="submit" class="btn">Login</button>
            </form>

            <div class="auth-links">
                <span>New here?</span>
                <a href="{{ route('register') }}">Create Account</a>
            </div>
        </div>
    </section>
@endsection

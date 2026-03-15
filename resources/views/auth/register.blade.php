@extends('layouts.app')

@section('title', 'Create Account · rss.cursor.style')
@section('body_class', 'page-auth')

@section('content')
    <section class="auth-shell">
        <aside class="auth-stage">
            <div class="auth-stage-grid">
                <div class="auth-brand-block">
                    <div class="auth-logo">
                        <i class="fa-solid fa-rss"></i>
                    </div>
                    <div>
                        <p class="eyebrow">rss.cursor.style</p>
                        <h1 class="section-heading" style="color: #f8fafc; font-size: clamp(1.8rem, 3vw, 3rem);">Your live feed&nbsp;desk starts here.</h1>
                    </div>
                </div>

                <p class="section-copy" style="margin: 0; color: rgba(241,245,249,0.78);">Create a workspace, link Telegram, preview sources before they go live, and route every new story to the right destination.</p>

                <div class="auth-highlights">
                    <article class="auth-highlight">
                        <span class="auth-highlight-mark">
                            <i class="fa-solid fa-plug"></i>
                        </span>
                        <div>
                            <strong>Connect once</strong>
                            <p>Use Google or email to get into the dashboard fast and start building feeds immediately.</p>
                        </div>
                    </article>

                    <article class="auth-highlight">
                        <span class="auth-highlight-mark">
                            <i class="fa-solid fa-eye"></i>
                        </span>
                        <div>
                            <strong>Preview before delivery</strong>
                            <p>Every feed starts with a headline preview. See exactly what will land in Telegram before committing.</p>
                        </div>
                    </article>

                    <article class="auth-highlight">
                        <span class="auth-highlight-mark">
                            <i class="fa-brands fa-telegram"></i>
                        </span>
                        <div>
                            <strong>Ship to groups or channels</strong>
                            <p>Map each source to the destination that fits, with dedupe and queue-backed retries underneath.</p>
                        </div>
                    </article>
                </div>
            </div>
        </aside>

        <div class="panel auth-card">
            <div class="auth-card-head">
                <h1 class="auth-title">Create Account</h1>
                <p class="muted" style="margin: 0;">Set up your feed ingestion workspace with the login flow that feels best for you.</p>
            </div>

            @include('auth.partials.google-auth-cta')

            @if (filled(config('services.google.client_id')) && filled(config('services.google.client_secret')))
                <div class="oauth-divider" style="margin: 22px 0;">or create an account with email</div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="auth-form">
                @csrf

                <div class="input-group">
                    <label for="name"><i class="fa-solid fa-user"></i> Name</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name" placeholder="Your display name">
                    </div>
                </div>

                <div class="input-group">
                    <label for="email"><i class="fa-solid fa-envelope"></i> Email</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@example.com">
                    </div>
                </div>

                <div class="input-group">
                    <label for="password"><i class="fa-solid fa-lock"></i> Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters">
                    </div>
                </div>

                <div class="input-group">
                    <label for="password_confirmation"><i class="fa-solid fa-shield-halved"></i> Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-shield-halved input-icon"></i>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Repeat your password">
                    </div>
                </div>

                <button type="submit" class="btn" style="width: 100%; padding: 15px; margin-top: 4px;">
                    <i class="fa-solid fa-rocket"></i> Create Account
                </button>
            </form>

            <div class="auth-links">
                <span>Already have an account?</span>
                <a href="{{ route('login') }}">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                </a>
            </div>
        </div>
    </section>
@endsection

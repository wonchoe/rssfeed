@if (filled(config('services.google.client_id')) && filled(config('services.google.client_secret')))
    <div style="display: grid; gap: 12px; margin-top: 18px;">
        <div class="google-btn-wrap">
            <a class="btn-google" href="{{ route('auth.google.redirect') }}">
                <span class="google-g-tile" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M21.805 12.23c0-.72-.064-1.412-.184-2.077H12v3.93h5.498a4.703 4.703 0 0 1-2.039 3.084v2.56h3.305c1.934-1.78 3.041-4.403 3.041-7.497Z" fill="#4285F4"/>
                        <path d="M12 22c2.76 0 5.075-.914 6.767-2.473l-3.305-2.56c-.914.613-2.084.976-3.462.976-2.66 0-4.916-1.796-5.722-4.21H2.86v2.642A10 10 0 0 0 12 22Z" fill="#34A853"/>
                        <path d="M6.278 13.733A5.996 5.996 0 0 1 5.96 11.9c0-.635.11-1.252.318-1.833V7.425H2.86A10 10 0 0 0 2 11.9c0 1.612.386 3.138 1.07 4.475l3.208-2.642Z" fill="#FBBC04"/>
                        <path d="M12 5.857c1.5 0 2.848.516 3.91 1.53l2.93-2.93C17.07 2.79 14.757 1.8 12 1.8A10 10 0 0 0 2.86 7.425l3.418 2.642C7.084 7.653 9.34 5.857 12 5.857Z" fill="#EA4335"/>
                    </svg>
                </span>
                <span class="google-btn-body">
                    <strong>Continue with Google</strong>
                    <span>One click &mdash; workspace ready instantly</span>
                </span>
                <span class="google-btn-arrow" aria-hidden="true">
                    <i class="fa-solid fa-arrow-right"></i>
                </span>
            </a>
        </div>
        <p class="google-trust-note">
            <i class="fa-solid fa-shield-halved"></i>
            We sign you in or create your account using the Google email you select.
        </p>
    </div>
@endif

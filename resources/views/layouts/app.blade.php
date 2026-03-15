<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        :root {
            --bg: #07091a;
            --surface: rgba(255, 255, 255, 0.045);
            --surface-mid: rgba(255, 255, 255, 0.07);
            --surface-strong: rgba(255, 255, 255, 0.11);
            --text: #e6eeff;
            --muted: rgba(180, 200, 240, 0.52);
            --brand: #00d4b8;
            --brand-strong: #00b09a;
            --accent: #a78bfa;
            --accent-soft: rgba(167, 139, 250, 0.16);
            --danger: #f87171;
            --ok: #34d399;
            --border: rgba(255, 255, 255, 0.09);
            --border-strong: rgba(255, 255, 255, 0.16);
            --shadow-soft: 0 8px 32px rgba(0, 0, 0, 0.42);
            --shadow-strong: 0 28px 72px rgba(0, 0, 0, 0.60);
            --glass: blur(22px);
        }

        * { box-sizing: border-box; }

        /* ── Orb background ─────────────────────────── */
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Manrope", sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            border-radius: 999px;
            pointer-events: none;
            z-index: 0;
            filter: blur(90px);
        }
        body::before {
            width: min(56vw, 680px);
            height: min(56vw, 680px);
            top: -18%;
            left: -12%;
            background: radial-gradient(circle, rgba(0, 212, 184, 0.18), transparent 70%);
        }
        body::after {
            width: min(50vw, 600px);
            height: min(50vw, 600px);
            bottom: -20%;
            right: -10%;
            background: radial-gradient(circle, rgba(167, 139, 250, 0.22), transparent 70%);
        }

        body > * {
            position: relative;
            z-index: 1;
        }

        h1, h2, h3, h4, h5 {
            font-family: "Space Grotesk", sans-serif;
            letter-spacing: -0.03em;
            color: var(--text);
        }

        a {
            color: inherit;
            transition: color 180ms ease, transform 180ms ease, opacity 180ms ease, border-color 180ms ease, background 180ms ease;
        }

        .container {
            width: min(1180px, 92%);
            margin: 0 auto;
        }

        /* ── Topbar ──────────────────────────────────── */
        .topbar {
            border-bottom: 1px solid var(--border);
            background: rgba(7, 9, 26, 0.72);
            backdrop-filter: var(--glass);
            -webkit-backdrop-filter: var(--glass);
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            gap: 16px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-family: "Space Grotesk", sans-serif;
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--text);
            text-decoration: none;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--brand), #0899ff);
            box-shadow: 0 0 24px rgba(0, 212, 184, 0.4);
            font-size: 14px;
            color: #07091a;
        }

        .brand-copy {
            display: grid;
            gap: 2px;
        }

        .brand-copy strong {
            font-size: 15px;
            line-height: 1;
        }

        .brand-copy span {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .nav {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ── Buttons ─────────────────────────────────── */
        .btn,
        .btn-outline,
        .btn-danger {
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 10px 18px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: -0.01em;
            transition: transform 200ms ease, box-shadow 200ms ease, background 200ms ease, border-color 200ms ease, opacity 200ms ease;
            font-family: "Manrope", sans-serif;
        }

        .btn {
            background: linear-gradient(135deg, var(--brand), #0899ff);
            color: #07091a;
            font-weight: 800;
            box-shadow: 0 0 0 0 rgba(0, 212, 184, 0);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 0 32px rgba(0, 212, 184, 0.36), 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .btn-outline {
            background: var(--surface);
            border-color: var(--border-strong);
            color: var(--text);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .btn-outline:hover {
            transform: translateY(-1px);
            background: var(--surface-strong);
            border-color: rgba(255, 255, 255, 0.26);
        }

        .btn-danger {
            background: rgba(248, 113, 113, 0.12);
            border-color: rgba(248, 113, 113, 0.3);
            color: var(--danger);
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            background: rgba(248, 113, 113, 0.2);
        }

        /* ── Google OAuth button ─────────────────────── */
        .google-btn-wrap {
            position: relative;
            border-radius: 18px;
            padding: 1.5px;
            background: linear-gradient(135deg, #4285F4, #34A853, #FBBC04, #EA4335);
            box-shadow: 0 0 40px rgba(66, 133, 244, 0.2);
            transition: box-shadow 240ms ease, transform 240ms ease;
        }
        .google-btn-wrap:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 60px rgba(66, 133, 244, 0.38);
        }
        .btn-google {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 15px 18px;
            background: rgba(12, 16, 36, 0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 16.5px;
            text-decoration: none;
            border: none;
            position: relative;
            overflow: hidden;
            justify-content: flex-start;
        }
        .btn-google::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 25%, rgba(255,255,255,0.06) 50%, transparent 75%);
            transform: translateX(-120%);
            transition: transform 560ms ease;
            pointer-events: none;
        }
        .google-btn-wrap:hover .btn-google::before {
            transform: translateX(120%);
        }
        .google-g-tile {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.96);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        .google-btn-body {
            flex: 1;
            text-align: left;
            display: grid;
            gap: 3px;
        }
        .google-btn-body strong {
            display: block;
            color: #f0f4ff;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.025em;
            font-family: "Space Grotesk", sans-serif;
        }
        .google-btn-body span {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }
        .google-btn-arrow {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.6);
            font-size: 12px;
            flex-shrink: 0;
            transition: background 220ms ease, color 220ms ease;
        }
        .google-btn-wrap:hover .google-btn-arrow {
            background: rgba(255,255,255,0.16);
            color: #fff;
        }
        .google-trust-note {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }
        .google-trust-note i {
            color: var(--brand);
            font-size: 11px;
        }

        /* ── Divider ─────────────────────────────────── */
        .oauth-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .oauth-divider::before,
        .oauth-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ── Main + Panel ────────────────────────────── */
        main { padding: 36px 0 64px; }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 24px;
            backdrop-filter: var(--glass);
            -webkit-backdrop-filter: var(--glass);
            box-shadow: var(--shadow-soft);
        }

        .grid { display: grid; gap: 18px; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        /* ── Forms ───────────────────────────────────── */
        label {
            font-size: 12.5px;
            color: var(--muted);
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px 14px;
            font-size: 14px;
            font-family: "Manrope", sans-serif;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
            transition: border-color 180ms ease, box-shadow 180ms ease;
        }

        input::placeholder,
        textarea::placeholder {
            color: rgba(180, 200, 240, 0.3);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(0, 212, 184, 0.5);
            box-shadow: 0 0 0 3px rgba(0, 212, 184, 0.12);
            background: rgba(0, 212, 184, 0.03);
        }

        select option {
            background: #111827;
            color: var(--text);
        }

        /* ── Input with icon ─────────────────────────── */
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 13px;
            pointer-events: none;
            z-index: 1;
        }
        .input-wrap input,
        .input-wrap select { padding-left: 40px; }
        .input-group { display: grid; gap: 7px; }
        .input-group label {
            margin-bottom: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .input-group label i { color: var(--brand); font-size: 11px; }

        /* ── Flash messages ──────────────────────────── */
        .flash {
            margin-bottom: 16px;
            border-radius: 14px;
            padding: 13px 16px;
            font-size: 14px;
            border: 1px solid;
            backdrop-filter: blur(12px);
        }
        .flash-ok {
            background: rgba(52, 211, 153, 0.1);
            border-color: rgba(52, 211, 153, 0.3);
            color: var(--ok);
        }
        .flash-error {
            background: rgba(248, 113, 113, 0.1);
            border-color: rgba(248, 113, 113, 0.3);
            color: var(--danger);
        }

        /* ── Table ───────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; }
        th, td {
            text-align: left;
            border-bottom: 1px solid var(--border);
            padding: 10px 8px;
            vertical-align: top;
            font-size: 14px;
        }
        th {
            color: var(--muted);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        /* ── Utilities ───────────────────────────────── */
        .muted { color: var(--muted); }

        .eyebrow {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--brand);
        }
        .eyebrow::before {
            content: "";
            width: 24px;
            height: 1.5px;
            background: currentColor;
            border-radius: 2px;
            opacity: 0.5;
        }

        .section-heading {
            margin: 0;
            font-size: clamp(2rem, 4vw, 4.4rem);
            line-height: 0.97;
        }

        .section-copy {
            margin: 0;
            max-width: 66ch;
            font-size: 16px;
            line-height: 1.74;
            color: var(--muted);
        }

        .badge { border-radius: 999px; padding: 4px 10px; font-size: 11.5px; font-weight: 700; display: inline-block; }
        .badge-ok { background: rgba(52, 211, 153, 0.14); color: var(--ok); border: 1px solid rgba(52, 211, 153, 0.28); }
        .badge-muted { background: var(--surface-mid); color: var(--muted); border: 1px solid var(--border); }

        /* ── Eyebrow badge ───────────────────────────── */
        .eyebrow-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(0, 212, 184, 0.1);
            border: 1px solid rgba(0, 212, 184, 0.22);
            color: var(--brand);
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            width: fit-content;
        }

        /* ── Hero ────────────────────────────────────── */
        .home-hero { display: grid; gap: 20px; }

        .hero-shell {
            padding: clamp(28px, 5vw, 48px);
            border-radius: 28px;
            background: var(--surface);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .hero-shell::before {
            content: "";
            position: absolute;
            top: -60px;
            right: -60px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(0, 212, 184, 0.1), transparent 70%);
            pointer-events: none;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.8fr);
            gap: 28px;
            align-items: stretch;
            position: relative;
        }

        .hero-copy { display: grid; gap: 20px; align-content: start; }

        .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }

        .hero-trust { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; }
        .trust-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }
        .trust-item i { color: var(--brand); }

        /* ── Pipeline stage widget ───────────────────── */
        .hero-stage {
            display: grid;
            gap: 14px;
            padding: 20px;
            border-radius: 22px;
            background: rgba(0, 0, 0, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            color: var(--text);
            position: relative;
            overflow: hidden;
        }
        .hero-stage::before {
            content: "";
            position: absolute;
            bottom: -30px;
            right: -30px;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(167, 139, 250, 0.12), transparent 70%);
            pointer-events: none;
        }

        .hero-stage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .stage-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: "Space Grotesk", sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }

        .stage-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid rgba(52, 211, 153, 0.24);
            color: var(--ok);
            font-size: 11.5px;
            font-weight: 700;
        }
        .stage-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: var(--ok);
            box-shadow: 0 0 12px rgba(52, 211, 153, 0.8);
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .stage-path { display: grid; gap: 10px; }

        .stage-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.07);
            transition: background 180ms ease;
        }
        .stage-row:hover { background: rgba(255, 255, 255, 0.07); }

        .stage-row-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 212, 184, 0.12);
            color: var(--brand);
            font-size: 14px;
            flex-shrink: 0;
        }
        .stage-row-body { flex: 1; min-width: 0; }
        .stage-row-body strong { display: block; font-size: 13.5px; color: var(--text); }
        .stage-row-body span { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }

        .stage-badge {
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            white-space: nowrap;
        }
        .stage-badge-teal { background: rgba(0, 212, 184, 0.12); color: var(--brand); border: 1px solid rgba(0, 212, 184, 0.22); }
        .stage-badge-orange { background: rgba(251, 188, 4, 0.12); color: #fde68a; border: 1px solid rgba(251, 188, 4, 0.22); }
        .stage-badge-green { background: rgba(52, 211, 153, 0.12); color: var(--ok); border: 1px solid rgba(52, 211, 153, 0.22); }

        .stage-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(84, 169, 235, 0.07);
            border: 1px solid rgba(84, 169, 235, 0.16);
        }
        .stage-channel {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text);
        }
        .stage-channel > i { font-size: 24px; color: #54a9eb; flex-shrink: 0; }
        .stage-channel strong { display: block; font-size: 13.5px; }
        .stage-channel span { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }

        /* ── Metrics ─────────────────────────────────── */
        .metric-grid,
        .feature-grid,
        .story-grid { display: grid; gap: 16px; }

        .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .metric-card,
        .feature-card,
        .story-card {
            position: relative;
            overflow: hidden;
            transition: border-color 200ms ease, transform 200ms ease;
        }
        .metric-card:hover,
        .feature-card:hover,
        .story-card:hover {
            border-color: var(--border-strong);
            transform: translateY(-2px);
        }

        .metric-label {
            margin: 0 0 8px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .metric-value {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            font-size: clamp(1.8rem, 3vw, 2.4rem);
            line-height: 1;
            color: var(--text);
        }
        .metric-caption {
            margin: 10px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: var(--muted);
        }
        .metric-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 212, 184, 0.1);
            border: 1px solid rgba(0, 212, 184, 0.18);
            color: var(--brand);
            font-size: 19px;
            margin-bottom: 16px;
        }
        .metric-icon-telegram {
            background: rgba(84, 169, 235, 0.12);
            border-color: rgba(84, 169, 235, 0.2);
            color: #54a9eb;
        }

        /* ── Features ────────────────────────────────── */
        .feature-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(167, 139, 250, 0.1);
            border: 1px solid rgba(167, 139, 250, 0.2);
            color: var(--accent);
            font-size: 20px;
            margin-bottom: 18px;
        }
        .feature-card h3, .story-card h3 { margin: 0 0 10px; font-size: 1.15rem; color: var(--text); }
        .feature-card p, .story-card p { margin: 0; color: var(--muted); line-height: 1.7; font-size: 14px; }

        /* ── Story steps ─────────────────────────────── */
        .story-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .story-step {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(0, 212, 184, 0.1);
            border: 1px solid rgba(0, 212, 184, 0.2);
            color: var(--brand);
            font-family: "Space Grotesk", sans-serif;
            font-weight: 700;
            font-size: 13px;
        }
        .story-step-icon { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .story-step-icon .story-step { margin-bottom: 0; }
        .story-step-icon > i { font-size: 17px; color: var(--accent); opacity: 0.8; }

        /* ── CTA Banner ──────────────────────────────── */
        .cta-banner {
            text-align: center;
            padding: clamp(40px, 6vw, 68px) clamp(24px, 4vw, 60px);
            background: var(--surface);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .cta-banner::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at center, rgba(0, 212, 184, 0.07), transparent 70%);
            pointer-events: none;
        }
        .cta-icon {
            font-size: 2.6rem;
            background: linear-gradient(135deg, var(--brand), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 18px;
            display: block;
            position: relative;
        }
        .cta-title { margin: 0 0 12px; font-size: clamp(1.7rem, 3vw, 2.5rem); line-height: 1.1; position: relative; }
        .cta-banner .section-copy { position: relative; }
        .cta-banner .hero-actions { position: relative; }

        /* ── Auth layout ─────────────────────────────── */
        .auth-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(360px, 0.94fr);
            gap: 20px;
            align-items: stretch;
        }

        .auth-stage {
            position: relative;
            overflow: hidden;
            min-height: 100%;
            padding: clamp(28px, 4vw, 40px);
            border-radius: 24px;
            background: rgba(0, 0, 0, 0.32);
            border: 1px solid var(--border);
            backdrop-filter: var(--glass);
            -webkit-backdrop-filter: var(--glass);
            color: var(--text);
        }
        .auth-stage::before {
            content: "";
            position: absolute;
            top: -80px;
            left: -80px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(0, 212, 184, 0.14), transparent 70%);
            pointer-events: none;
        }
        .auth-stage::after {
            content: "";
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(167, 139, 250, 0.14), transparent 70%);
            pointer-events: none;
        }

        .auth-stage .eyebrow { color: var(--brand); }
        .auth-stage .section-copy,
        .auth-stage .muted { color: var(--muted); }
        .auth-stage h1 { color: var(--text); }

        .auth-stage-grid { display: grid; gap: 24px; position: relative; z-index: 1; }

        .auth-highlights { display: grid; gap: 12px; }

        .auth-highlight {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 14px;
            align-items: start;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            transition: background 180ms ease, border-color 180ms ease;
        }
        .auth-highlight:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--border-strong);
        }

        .auth-highlight-mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 212, 184, 0.1);
            border: 1px solid rgba(0, 212, 184, 0.2);
            color: var(--brand);
            font-size: 15px;
        }

        .auth-highlight strong { display: block; margin-bottom: 5px; font-size: 14.5px; color: var(--text); }
        .auth-highlight p { margin: 0; font-size: 13px; line-height: 1.65; color: var(--muted); }

        .auth-brand-block { display: flex; align-items: flex-start; gap: 16px; position: relative; z-index: 1; }
        .auth-logo {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--brand), #0899ff);
            color: #07091a;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 0 24px rgba(0, 212, 184, 0.36);
        }

        .auth-card { padding: clamp(24px, 4vw, 36px); }
        .auth-card-head { display: grid; gap: 10px; margin-bottom: 22px; }
        .auth-title { margin: 0; font-size: clamp(1.9rem, 3vw, 2.8rem); line-height: 0.98; color: var(--text); }
        .auth-form { display: grid; gap: 16px; }
        .auth-links {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--muted);
        }
        .auth-links a {
            color: var(--brand);
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .auth-links a:hover { opacity: 0.8; }

        /* ── Animations ──────────────────────────────── */
        @keyframes rise-in {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .hero-shell,
        .metric-card,
        .feature-card,
        .story-card,
        .auth-stage,
        .auth-card,
        .cta-banner {
            animation: rise-in 500ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .metric-card:nth-child(2) { animation-delay: 60ms; }
        .metric-card:nth-child(3) { animation-delay: 120ms; }
        .feature-card:nth-child(2) { animation-delay: 80ms; }
        .feature-card:nth-child(3) { animation-delay: 160ms; }
        .story-card:nth-child(2) { animation-delay: 80ms; }
        .story-card:nth-child(3) { animation-delay: 160ms; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .hero-grid,
            .auth-shell,
            .metric-grid,
            .feature-grid,
            .story-grid,
            .grid-2,
            .grid-3 { grid-template-columns: 1fr; }

            .topbar-inner { align-items: flex-start; }
            .nav { width: 100%; }
            .nav form { width: 100%; }
            .nav .btn,
            .nav .btn-outline { width: 100%; }
            .hero-actions,
            .auth-links { flex-direction: column; align-items: stretch; }
        }

        @media (max-width: 640px) {
            .brand-copy span { display: none; }
            .section-heading, .auth-title { font-size: 2rem; }
            .panel, .hero-shell, .auth-stage, .auth-card { border-radius: 18px; }
        }
    </style>

</head>
<body class="@yield('body_class')">
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-mark"><i class="fa-solid fa-rss"></i></span>
            <span class="brand-copy">
                <strong>rss.cursor.style</strong>
                <span>Live feed desk</span>
            </span>
        </a>

        <nav class="nav">
            @auth
                <a class="btn-outline" href="{{ route('dashboard') }}"><i class="fa-solid fa-gauge"></i> Dashboard</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn" type="submit"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
                </form>
            @else
                <a class="btn-outline" href="{{ route('login') }}"><i class="fa-solid fa-arrow-right-to-bracket"></i> Login</a>
                <a class="btn" href="{{ route('register') }}"><i class="fa-solid fa-user-plus"></i> Register</a>
            @endauth
        </nav>
    </div>
</header>

<main>
    <div class="container">
        @if (session('status'))
            <div class="flash flash-ok">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash flash-error">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')
    </div>
</main>
</body>
</html>

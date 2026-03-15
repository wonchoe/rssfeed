<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name').' Workspace')</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap');

        :root {
            --bg: #090f1d;
            --bg-soft: #0f1729;
            --surface: #121d34;
            --surface-2: #172744;
            --text: #e5ecff;
            --muted: #8ea2c9;
            --line: #243a62;
            --brand: #5da8ff;
            --brand-strong: #3d92f0;
            --accent: #ef8f4e;
            --ok: #22c55e;
            --danger: #f87171;
            --shadow: 0 16px 44px rgba(5, 10, 20, 0.35);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Space Grotesk", "Manrope", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 12% -12%, rgba(93, 168, 255, 0.20), transparent 40%),
                radial-gradient(circle at 95% 0%, rgba(239, 143, 78, 0.20), transparent 40%),
                linear-gradient(165deg, #080d19 0%, #0a1325 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 270px 1fr;
        }

        .sidebar {
            border-right: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(18, 29, 52, 0.92) 0%, rgba(13, 23, 43, 0.88) 100%);
            backdrop-filter: blur(8px);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 8px 10px 14px;
            border-bottom: 1px solid var(--line);
        }

        .brand h1 {
            margin: 0;
            font-size: 21px;
            letter-spacing: 0.3px;
            font-family: "Manrope", sans-serif;
        }

        .brand p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .menu {
            display: grid;
            gap: 6px;
            margin-top: 10px;
        }

        .menu-link {
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all .18s ease;
        }

        .menu-link:hover,
        .menu-link.active {
            color: var(--text);
            border-color: var(--line);
            background: rgba(26, 43, 74, 0.72);
        }

        .menu-link .pill {
            font-size: 11px;
            border-radius: 999px;
            padding: 2px 8px;
            border: 1px solid var(--line);
            color: var(--muted);
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 14px 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(18, 29, 52, 0.7);
            display: grid;
            gap: 10px;
        }

        .sidebar-footer .meta {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .btn,
        .btn-secondary,
        .btn-danger {
            border: 1px solid transparent;
            border-radius: 11px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
        }

        .btn {
            color: #06142a;
            background: linear-gradient(135deg, var(--brand) 0%, #7bc3ff 100%);
        }

        .btn:hover { filter: brightness(1.03); }

        .btn-secondary {
            color: var(--text);
            border-color: var(--line);
            background: rgba(23, 39, 68, 0.72);
        }

        .btn-danger {
            color: #ffcdcd;
            border-color: rgba(248, 113, 113, 0.45);
            background: rgba(248, 113, 113, 0.09);
        }

        .content {
            min-width: 0;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 22px 28px 16px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(17, 28, 50, 0.86) 0%, rgba(17, 28, 50, 0.56) 100%);
            position: sticky;
            top: 0;
            backdrop-filter: blur(7px);
            z-index: 20;
        }

        .topbar h2 {
            margin: 0;
            font-size: 28px;
            font-family: "Manrope", sans-serif;
        }

        .topbar p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .main {
            padding: 22px 28px 34px;
        }

        .flash {
            border: 1px solid;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }

        .flash-ok {
            border-color: rgba(34, 197, 94, 0.4);
            background: rgba(34, 197, 94, 0.13);
            color: #97f0b6;
        }

        .flash-error {
            border-color: rgba(248, 113, 113, 0.45);
            background: rgba(248, 113, 113, 0.1);
            color: #ffc2c2;
        }

        .panel {
            background: linear-gradient(180deg, rgba(20, 33, 58, 0.9) 0%, rgba(17, 29, 52, 0.82) 100%);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .muted { color: var(--muted); }

        .field-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            display: block;
        }

        input,
        select,
        textarea {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #29426c;
            background: #0d182f;
            color: var(--text);
            padding: 11px 12px;
            font-family: inherit;
            font-size: 14px;
        }

        input::placeholder,
        textarea::placeholder {
            color: #6f85ad;
        }

        .badge {
            border-radius: 999px;
            border: 1px solid;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-ok {
            border-color: rgba(34, 197, 94, 0.35);
            color: #90efb4;
            background: rgba(34, 197, 94, 0.12);
        }

        .badge-muted {
            border-color: rgba(142, 162, 201, 0.35);
            color: #bac8e4;
            background: rgba(142, 162, 201, 0.1);
        }

        .table-wrap {
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            font-size: 13px;
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        th {
            color: var(--muted);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.55px;
        }

        @media (max-width: 1100px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                border-right: none;
                border-bottom: 1px solid var(--line);
                padding-bottom: 18px;
            }

            .menu {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .brand {
                border-bottom: none;
            }
        }

        @media (max-width: 760px) {
            .topbar,
            .main {
                padding-left: 16px;
                padding-right: 16px;
            }

            .topbar h2 {
                font-size: 22px;
            }

            .menu {
                grid-template-columns: 1fr;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <h1>rss.cursor.style</h1>
            <p>Feed Workspace</p>
        </a>

        <nav class="menu">
            @php($canAccessAdmin = app(\App\Support\AdminAccess::class)->canAccess(auth()->user()))
            <a class="menu-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <span>My Feeds</span>
                <span class="pill">Overview</span>
            </a>
            <a class="menu-link {{ request()->routeIs('feeds.generator') ? 'active' : '' }}" href="{{ route('feeds.generator') }}">
                <span>New Feed</span>
                <span class="pill">Generator</span>
            </a>
            <a class="menu-link {{ request()->routeIs('integrations.*') ? 'active' : '' }}" href="{{ route('integrations.index') }}">
                <span>Integrations</span>
                <span class="pill">Targets</span>
            </a>
            <a class="menu-link" href="{{ route('home') }}">
                <span>Public Home</span>
                <span class="pill">Site</span>
            </a>
            <a class="menu-link" href="/api/v1/health" target="_blank" rel="noopener noreferrer">
                <span>API Health</span>
                <span class="pill">v1</span>
            </a>
            @if ($canAccessAdmin)
                <a class="menu-link {{ request()->routeIs('admin.*') ? 'active' : '' }}" href="{{ route('admin.debug.generations') }}">
                    <span>Admin Debug</span>
                    <span class="pill">Ops</span>
                </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <p class="meta">
                Discovery order: RSS -> Atom -> JSON Feed -> autodiscovery -> deterministic HTML -> AI fallback.
            </p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-secondary" style="width:100%;">Logout</button>
            </form>
        </div>
    </aside>

    <section class="content">
        <header class="topbar">
            <div>
                <h2>@yield('page_title', 'Workspace')</h2>
                <p>@yield('page_subtitle', 'Manage feed ingestion, preview, and delivery targets.')</p>
            </div>
            <div class="topbar-actions">
                @yield('top_actions')
            </div>
        </header>

        <main class="main">
            @if (session('status'))
                <div class="flash flash-ok">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="flash flash-error">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </section>
</div>
@stack('scripts')
</body>
</html>

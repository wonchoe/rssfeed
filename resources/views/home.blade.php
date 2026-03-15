@extends('layouts.app')

@section('title', 'rss.cursor.style · Feed Ingestion Platform')
@section('body_class', 'page-home')

@section('content')
    {{-- HERO --}}
    <section class="home-hero">
        <div class="panel hero-shell">
            <div class="hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow-badge">
                        <i class="fa-solid fa-rss"></i>
                        Feed Ingestion Platform
                    </div>
                    <h1 class="section-heading">Build a live newsroom pipeline. Discover, preview, and deliver every story exactly once.</h1>
                    <p class="section-copy">
                        Turn any news site into a Telegram-ready feed in a few clicks. We detect RSS first, fall back only when needed,
                        deduplicate new articles, and fan them out to your groups or channels without extra scraping glue.
                    </p>

                    <div class="hero-actions">
                        @auth
                            <a class="btn" href="{{ route('feeds.generator') }}">
                                <i class="fa-solid fa-plus"></i> Create New Feed
                            </a>
                            <a class="btn-outline" href="{{ route('dashboard') }}">
                                <i class="fa-solid fa-gauge"></i> Open Dashboard
                            </a>
                        @else
                            <a class="btn" href="{{ route('register') }}">
                                <i class="fa-solid fa-rocket"></i> Get Started Free
                            </a>
                            <a class="btn-outline" href="{{ route('login') }}">
                                <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                            </a>
                        @endauth
                    </div>

                    <div class="hero-trust">
                        <span class="trust-item"><i class="fa-solid fa-circle-check"></i> RSS-first, AI as fallback</span>
                        <span class="trust-item"><i class="fa-solid fa-circle-check"></i> Shared source cache</span>
                        <span class="trust-item"><i class="fa-solid fa-circle-check"></i> Telegram-native delivery</span>
                    </div>
                </div>

                <aside class="hero-stage" aria-label="Pipeline preview">
                    <div class="hero-stage-header">
                        <span class="stage-brand">
                            <i class="fa-solid fa-diagram-project"></i>
                            Live delivery path
                        </span>
                        <span class="stage-pill">Watching new items</span>
                    </div>

                    <div class="stage-path">
                        <div class="stage-row">
                            <div class="stage-row-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                            <div class="stage-row-body">
                                <strong>Source discovery</strong>
                                <span>RSS → Atom → JSON Feed → HTML fallback</span>
                            </div>
                            <span class="stage-badge stage-badge-teal">Cheap first</span>
                        </div>

                        <div class="stage-row">
                            <div class="stage-row-icon"><i class="fa-solid fa-eye"></i></div>
                            <div class="stage-row-body">
                                <strong>Preview + verify</strong>
                                <span>Headlines shown before any delivery starts</span>
                            </div>
                            <span class="stage-badge stage-badge-orange">Human check</span>
                        </div>

                        <div class="stage-row">
                            <div class="stage-row-icon"><i class="fa-solid fa-bolt"></i></div>
                            <div class="stage-row-body">
                                <strong>Fan-out delivery</strong>
                                <span>One fetch, many subscribers, zero duplicates</span>
                            </div>
                            <span class="stage-badge stage-badge-teal">Telegram</span>
                        </div>
                    </div>

                    <div class="stage-footer">
                        <div class="stage-channel">
                            <i class="fa-brands fa-telegram"></i>
                            <div>
                                <strong>@mynews · Telegram channel</strong>
                                <span>Fresh stories land as soon as the source changes</span>
                            </div>
                        </div>
                        <span class="stage-badge stage-badge-green">Active</span>
                    </div>
                </aside>
            </div>
        </div>

        {{-- METRICS --}}
        <div class="metric-grid">
            <article class="panel metric-card">
                <div class="metric-icon"><i class="fa-solid fa-database"></i></div>
                <p class="metric-label">Discovered Sources</p>
                <p class="metric-value">{{ number_format($sourceCount) }}</p>
                <p class="metric-caption">Shared source cache means one fetch powers every subscriber on top of it.</p>
            </article>

            <article class="panel metric-card">
                <div class="metric-icon"><i class="fa-solid fa-users"></i></div>
                <p class="metric-label">Active Subscriptions</p>
                <p class="metric-value">{{ number_format($subscriptionCount) }}</p>
                <p class="metric-caption">Each new article is mapped once and then fanned out to all linked destinations.</p>
            </article>

            <article class="panel metric-card">
                <div class="metric-icon metric-icon-telegram"><i class="fa-brands fa-telegram"></i></div>
                <p class="metric-label">Primary Delivery</p>
                <p class="metric-value" style="font-size: clamp(1.6rem, 2.4vw, 2rem); line-height: 1.1;">Telegram</p>
                <p class="metric-caption">Groups and channels already integrated, with instant sample sends on first setup.</p>
            </article>
        </div>
    </section>

    {{-- FEATURES --}}
    <section class="feature-grid" style="margin-top: 22px;">
        <article class="panel feature-card">
            <div class="feature-icon"><i class="fa-solid fa-filter"></i></div>
            <h3>Discovery order that saves money</h3>
            <p>RSS → Atom → JSON Feed → HTML autodiscovery → deterministic parsing → AI only when the cheap paths fail.</p>
        </article>

        <article class="panel feature-card">
            <div class="feature-icon"><i class="fa-solid fa-layer-group"></i></div>
            <h3>Shared source cache by design</h3>
            <p>We do not refetch the same site per user. One canonical source is polled and reused across all subscriptions.</p>
        </article>

        <article class="panel feature-card">
            <div class="feature-icon"><i class="fa-solid fa-arrows-spin"></i></div>
            <h3>Queue-driven delivery you can trust</h3>
            <p>Ingestion, normalization, deduplication, and Telegram sends all move through separate queues with clear retry boundaries.</p>
        </article>
    </section>

    {{-- HOW IT WORKS --}}
    <section class="story-grid" style="margin-top: 22px;">
        <article class="panel story-card">
            <div class="story-step-icon">
                <span class="story-step">01</span>
                <i class="fa-solid fa-link"></i>
            </div>
            <h3>Paste a news site</h3>
            <p>We discover the strongest feed candidate first, normalize it, and keep the canonical source cached for future use.</p>
        </article>

        <article class="panel story-card">
            <div class="story-step-icon">
                <span class="story-step">02</span>
                <i class="fa-solid fa-newspaper"></i>
            </div>
            <h3>Preview headlines before publishing</h3>
            <p>Users see a sample of parsed stories before they commit, so broken sources do not silently start shipping noise.</p>
        </article>

        <article class="panel story-card">
            <div class="story-step-icon">
                <span class="story-step">03</span>
                <i class="fa-brands fa-telegram"></i>
            </div>
            <h3>Map feeds to Telegram destinations</h3>
            <p>Link a group or channel once, then route each feed to the exact place it belongs without extra manual steps.</p>
        </article>
    </section>

    {{-- CTA BANNER --}}
    @guest
    <div class="cta-banner panel" style="margin-top: 22px;">
        <i class="fa-solid fa-rocket cta-icon"></i>
        <h2 class="cta-title">Ready to build your live newsroom?</h2>
        <p class="section-copy" style="margin: 0 auto 28px; text-align: center; max-width: 56ch;">
            Get started in under 2 minutes. Connect a source, preview the feed, and link your Telegram channel.
        </p>
        <div class="hero-actions" style="justify-content: center;">
            <a class="btn" href="{{ route('register') }}">
                <i class="fa-solid fa-rocket"></i> Create Free Account
            </a>
            <a class="btn-outline" href="{{ route('login') }}">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
            </a>
        </div>
    </div>
    @endguest
@endsection

@extends('layouts.workspace')

@section('title', 'New Feed · rss.cursor.style')
@section('page_title', 'Create New Feed')
@section('page_subtitle', 'Paste a news source, preview the latest items, and map the feed to a Telegram group or channel.')

@section('top_actions')
    <a href="{{ route('dashboard') }}" class="btn-secondary">Back to My Feeds</a>
@endsection

@section('content')
    @include('partials.feed-builder-panel', [
        'feedBuilderRedirectTo' => route('dashboard', absolute: false),
        'feedBuilderCreatedFrom' => 'generator_builder',
        'telegramGroupChats' => $telegramGroupChats,
        'telegramChannelChats' => $telegramChannelChats,
    ])
@endsection

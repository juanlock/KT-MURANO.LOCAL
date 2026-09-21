@extends('layouts.store')

@section('content')
<div class="container py-8 lg:py-12">
  <nav class="text-sm text-fg-muted mb-4 flex items-center gap-1 flex-wrap">
    <a href="{{ route('store.index') }}" class="hover:text-accent-500">{{ __('messages.Home') }}</a>
    <x-store.icon name="chevron-right" class="w-4 h-4" />
    <span class="text-fg-secondary truncate">{{ $page->localized('title') }}</span>
  </nav>

  <article class="max-w-3xl mx-auto">
    <h1 class="text-3xl lg:text-4xl font-bold text-fg-primary mb-6">{{ $page->localized('title') }}</h1>
    <div class="cms-content text-fg-secondary leading-relaxed">
      {!! $page->localized('content') ?: '<p class="text-fg-muted">'.e(__('messages.NoContent')).'</p>' !!}
    </div>
  </article>
</div>

<style>
  .cms-content h1, .cms-content h2, .cms-content h3 { color: rgb(var(--color-fg-primary)); font-weight: 700; margin: 1.2em 0 .5em; }
  .cms-content h1 { font-size: 1.6rem; } .cms-content h2 { font-size: 1.35rem; } .cms-content h3 { font-size: 1.15rem; }
  .cms-content p { margin: 0 0 1em; }
  .cms-content ul, .cms-content ol { margin: 0 0 1em 1.5em; }
  .cms-content ul { list-style: disc; } .cms-content ol { list-style: decimal; }
  .cms-content a { color: rgb(var(--color-accent-500)); text-decoration: underline; }
  .cms-content img { max-width: 100%; height: auto; border-radius: .5rem; margin: 1em 0; }
  .cms-content blockquote { border-inline-start: 3px solid rgb(var(--color-accent-500)); padding-inline-start: 1rem; color: rgb(var(--color-fg-muted)); margin: 0 0 1em; }
</style>
@endsection

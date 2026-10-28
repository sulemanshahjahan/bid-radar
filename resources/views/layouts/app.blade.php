<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
        <nav class="mx-auto flex max-w-3xl items-center gap-1 px-4 py-2 text-sm">
            <a href="{{ route('projects.index') }}" wire:navigate class="mr-auto flex items-center gap-2 font-semibold text-teal-700">
                <img src="/icons/icon.svg" alt="" class="h-6 w-6"> <span @class(['hidden sm:inline' => \App\Services\Opportunity\ShadowRanking::enabled()])>BidRadar</span>
            </a>
            @php($nav = ['projects.index' => 'Feed', 'paste' => 'Paste'] + (\App\Services\Opportunity\ShadowRanking::enabled() ? ['opportunities' => 'Picks'] : []) + ['stats' => 'Stats', 'settings' => 'Settings'])
            @foreach ($nav as $route => $label)
                <a href="{{ route($route) }}" wire:navigate
                   class="rounded-md px-2 py-2 sm:px-3 {{ request()->routeIs($route) ? 'bg-teal-50 font-medium text-teal-800' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
            @endforeach
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-md px-2 py-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Log out" aria-label="Log out">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v2a.75.75 0 0 1-1.5 0v-2a.75.75 0 0 0-.75-.75h-5.5a.75.75 0 0 0-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 0 0 .75-.75v-2a.75.75 0 0 1 1.5 0v2A2.25 2.25 0 0 1 10.75 18h-5.5A2.25 2.25 0 0 1 3 15.75V4.25Z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M19 10a.75.75 0 0 0-.75-.75H8.704l1.048-.943a.75.75 0 1 0-1.004-1.114l-2.5 2.25a.75.75 0 0 0 0 1.114l2.5 2.25a.75.75 0 1 0 1.004-1.114l-1.048-.943h9.546A.75.75 0 0 0 19 10Z" clip-rule="evenodd"/></svg>
                </button>
            </form>
        </nav>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-5 pb-24">
        {{ $slot }}
    </main>

    <div x-data="{ show: false, text: '' }"
         x-on:copied.window="text = $event.detail; show = true; clearTimeout(window.__t); window.__t = setTimeout(() => show = false, 1600)"
         x-show="show" x-transition x-cloak
         class="fixed inset-x-0 bottom-6 z-30 mx-auto w-fit rounded-full bg-slate-900 px-4 py-2 text-sm text-white shadow-lg">
        <span x-text="text"></span>
    </div>
</body>
</html>

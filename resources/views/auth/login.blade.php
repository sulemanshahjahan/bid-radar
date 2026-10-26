<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Log in'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 text-slate-900 antialiased">
    <form method="POST" action="{{ url('/login') }}" class="w-full max-w-sm space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <div class="flex items-center gap-2 text-lg font-semibold text-teal-700">
            <img src="/icons/icon.svg" alt="" class="h-7 w-7"> BidRadar
        </div>

        @error('email')
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
        @enderror

        <label class="block text-sm">
            <span class="font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/20">
        </label>
        <label class="block text-sm">
            <span class="font-medium">Password</span>
            <input type="password" name="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/20">
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-teal-600"> Keep me logged in
        </label>
        <button class="w-full rounded-lg bg-teal-700 px-4 py-2.5 font-medium text-white hover:bg-teal-800">Log in</button>
    </form>
</body>
</html>

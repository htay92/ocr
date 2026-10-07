<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($refresh ?? false)
        {{-- Re-check while documents are still being processed. --}}
        <meta http-equiv="refresh" content="5">
    @endif
    <title>{{ __($title ?? 'Documents') }} · OCR</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <header class="topbar">
        <div class="container">
            <a class="brand" href="{{ route('documents.index') }}">OCR</a>
            <nav class="nav">
                <a href="{{ route('documents.index') }}" @class(['active' => request()->routeIs('documents.index', 'documents.show')])>{{ __('Documents') }}</a>
                <a href="{{ route('documents.create') }}" @class(['active' => request()->routeIs('documents.create')])>{{ __('Upload') }}</a>
                @include('partials.locale')
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="linkbutton" type="submit">{{ __('Log out') }}</button>
                </form>
            </nav>
        </div>
    </header>
@endauth
<main class="container">
    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
@stack('scripts')
</body>
</html>

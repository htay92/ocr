<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($refresh ?? false)
        {{-- Re-check while documents are still being processed. --}}
        <meta http-equiv="refresh" content="5">
    @endif
    <title>{{ $title ?? 'Documents' }} · OCR</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <header class="topbar">
        <div class="container">
            <a class="brand" href="{{ route('documents.index') }}">OCR</a>
            <nav class="nav">
                <a href="{{ route('documents.index') }}" @class(['active' => request()->routeIs('documents.index', 'documents.show')])>Documents</a>
                <a href="{{ route('documents.create') }}" @class(['active' => request()->routeIs('documents.create')])>Upload</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="linkbutton" type="submit">Log out</button>
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

@extends('layouts.app', ['title' => 'Log in'])

@section('content')
    <div class="auth">
        <h1>Log in</h1>
        <form method="POST" action="{{ url('login') }}" class="card stack">
            @csrf
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                @error('email') <div class="error-text">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <label class="muted small"><input type="checkbox" name="remember" value="1"> Keep me logged in</label>
            <button class="button" type="submit">Log in</button>
        </form>
    </div>
@endsection

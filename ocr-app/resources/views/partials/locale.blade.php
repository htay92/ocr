{{-- 日本語 / English switch --}}
<span class="locale">
    @foreach (['ja' => '日本語', 'en' => 'English'] as $code => $name)
        @if (app()->getLocale() === $code)
            <strong>{{ $name }}</strong>
        @else
            <a href="{{ route('locale', $code) }}" lang="{{ $code }}">{{ $name }}</a>
        @endif
    @endforeach
</span>

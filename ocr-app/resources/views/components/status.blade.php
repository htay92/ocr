@props(['status'])
<span {{ $attributes->class(['badge', $status]) }}>{{ \App\Support\Labels::status($status) }}</span>

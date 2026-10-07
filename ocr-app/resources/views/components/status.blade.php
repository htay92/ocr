@props(['status'])
<span {{ $attributes->class(['badge', $status]) }}>{{ str_replace('_', ' ', $status) }}</span>

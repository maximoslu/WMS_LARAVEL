@props([
    'name' => null,
    'class' => '',
])

@switch($name)
    @case('stock')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M3.75 7.5 12 3.75l8.25 3.75L12 11.25 3.75 7.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M3.75 7.5v9L12 20.25l8.25-3.75v-9" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M12 11.25v9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('booking')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M7.5 3.75v3M16.5 3.75v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M4.5 8.25h15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <rect x="4.5" y="5.25" width="15" height="14.25" rx="2.25" stroke="currentColor" stroke-width="1.5"/>
            <path d="m9 13.125 1.5 1.5 4.5-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break

    @case('orders')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M6.75 4.5h7.5l3 3v12H6.75V4.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M14.25 4.5v3h3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M9 12h6M9 15h4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M7.5 19.5h9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break
@endswitch

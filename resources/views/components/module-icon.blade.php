@props([
    'name' => null,
    'class' => '',
])

@switch($name)
    @case('dashboard')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <rect x="4.5" y="4.5" width="6.75" height="6.75" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="12.75" y="4.5" width="6.75" height="4.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="12.75" y="10.5" width="6.75" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="4.5" y="12.75" width="6.75" height="6.75" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        @break

    @case('stock')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M3.75 7.5 12 3.75l8.25 3.75L12 11.25 3.75 7.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M3.75 7.5v9L12 20.25l8.25-3.75v-9" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M12 11.25v9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('items')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M7.5 7.5h9M7.5 12h6.75M7.5 16.5h4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M5.25 5.25h13.5v13.5H5.25z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M15.75 5.25V3.75H8.25v1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break

    @case('pallets')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M4.5 9.75h15M6 14.25h12M7.5 18.75h9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M5.25 6h13.5v3.75H5.25zM6.75 14.25h10.5v4.5H6.75z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
        @break

    @case('locations')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M12 20.25s5.25-4.31 5.25-9a5.25 5.25 0 1 0-10.5 0c0 4.69 5.25 9 5.25 9Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <circle cx="12" cy="11.25" r="1.875" stroke="currentColor" stroke-width="1.5"/>
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

    @case('booking-calendar')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M7.5 3.75v3M16.5 3.75v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <rect x="4.5" y="5.25" width="15" height="14.25" rx="2.25" stroke="currentColor" stroke-width="1.5"/>
            <path d="M4.5 8.25h15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M8.25 11.25h1.5M12 11.25h1.5M15.75 11.25h1.5M8.25 15h1.5M12 15h1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
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

    @case('receipts')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M12 4.5v10.5M8.25 8.25 12 4.5l3.75 3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M5.25 14.25h13.5v5.25H5.25z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
        @break

    @case('dispatches')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M12 19.5V9M15.75 12.75 12 9l-3.75 3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M5.25 4.5h13.5v5.25H5.25z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
        @break

    @case('operations')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M8.25 7.5h9M8.25 12h9M8.25 16.5h9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="m5.25 7.5.75.75 1.5-1.5M5.25 12l.75.75 1.5-1.5M5.25 16.5l.75.75 1.5-1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break

    @case('clients')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M7.875 11.25a2.625 2.625 0 1 0 0-5.25 2.625 2.625 0 0 0 0 5.25ZM16.125 12.75a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M4.5 18.75a4.5 4.5 0 0 1 6.75-3.9M13.5 18.75a3.75 3.75 0 0 1 6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('warehouses')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M4.5 19.5V9l7.5-4.5L19.5 9v10.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M9 19.5v-4.5h6v4.5M8.25 10.5h1.5M11.25 10.5h1.5M14.25 10.5h1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('suppliers')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M4.5 7.5h10.5v7.5H4.5zM15 10.5h2.25l2.25 2.25v2.25H15z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <circle cx="8.25" cy="17.25" r="1.5" stroke="currentColor" stroke-width="1.5"/>
            <circle cx="17.25" cy="17.25" r="1.5" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        @break

    @case('users')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M9 11.25a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16.5 12.75a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M4.5 18.75a4.5 4.5 0 0 1 9 0M13.5 18.75a3.75 3.75 0 0 1 6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('access')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <circle cx="8.25" cy="12" r="2.25" stroke="currentColor" stroke-width="1.5"/>
            <path d="M10.5 12h8.25M15.75 12v2.25M18 12v1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M5.25 18.75a4.35 4.35 0 0 1 6-4.05" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('audit')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M6 5.25h8.25l3.75 3.75v9.75H6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M14.25 5.25V9h3.75" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <circle cx="10.875" cy="14.625" r="2.25" stroke="currentColor" stroke-width="1.5"/>
            <path d="m12.75 16.5 1.875 1.875" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('backups')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <ellipse cx="10.5" cy="6.75" rx="4.5" ry="2.25" stroke="currentColor" stroke-width="1.5"/>
            <path d="M6 6.75v6.75c0 1.24 2.01 2.25 4.5 2.25s4.5-1.01 4.5-2.25V6.75" stroke="currentColor" stroke-width="1.5"/>
            <path d="M15 17.25a3.75 3.75 0 1 0 7.5 0c0-1.6-1.01-2.72-2.36-3.65a5.9 5.9 0 0 1-1.39-1.35 5.9 5.9 0 0 1-1.39 1.35C16.01 14.53 15 15.65 15 17.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
        @break

    @case('notifications')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M12 4.5a4.5 4.5 0 0 0-4.5 4.5v2.44c0 .7-.23 1.38-.66 1.94L5.25 15.75h13.5l-1.59-2.37a3.5 3.5 0 0 1-.66-1.94V9A4.5 4.5 0 0 0 12 4.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M10.5 18a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('profile')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <circle cx="12" cy="8.25" r="3" stroke="currentColor" stroke-width="1.5"/>
            <path d="M6 18.75a6 6 0 0 1 12 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        @break

    @case('logout')
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <path d="M9 5.25H6.75A2.25 2.25 0 0 0 4.5 7.5v9a2.25 2.25 0 0 0 2.25 2.25H9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M13.5 8.25 18 12l-4.5 3.75M18 12H9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break

    @default
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => trim('module-link-icon-svg '.$class)]) }}>
            <rect x="4.5" y="4.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="13.5" y="4.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="4.5" y="13.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <rect x="13.5" y="13.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
        </svg>
@endswitch


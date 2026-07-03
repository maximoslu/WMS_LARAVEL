@props([
    'items' => [],
    'label' => 'Ruta de navegacion',
])

@php
    $items = collect($items)
        ->filter(fn ($item) => filled($item['label'] ?? null))
        ->values();
@endphp

@if ($items->isNotEmpty())
    <nav class="ops-breadcrumb" aria-label="{{ $label }}">
        <ol class="ops-breadcrumb-list">
            @foreach ($items as $item)
                @php($href = $loop->last ? null : ($item['href'] ?? null))

                <li class="ops-breadcrumb-item">
                    @unless ($loop->first)
                        <span class="ops-breadcrumb-separator" aria-hidden="true">â€º</span>
                    @endunless

                    @if ($href)
                        <a href="{{ $href }}" class="ops-breadcrumb-link">
                            @if (! empty($item['icon']))
                                <span class="ops-breadcrumb-icon" aria-hidden="true">
                                    <x-module-icon :name="$item['icon']" />
                                </span>
                            @endif
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @else
                        <span class="ops-breadcrumb-current" @if($loop->last) aria-current="page" @endif>
                            @if (! empty($item['icon']))
                                <span class="ops-breadcrumb-icon" aria-hidden="true">
                                    <x-module-icon :name="$item['icon']" />
                                </span>
                            @endif
                            <span>{{ $item['label'] }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif


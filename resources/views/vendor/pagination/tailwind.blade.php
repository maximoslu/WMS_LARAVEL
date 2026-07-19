@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navegacion de paginas" class="wms-pagination">
        <div class="wms-pagination-mobile">
            @if ($paginator->onFirstPage())
                <span class="wms-pagination-link wms-pagination-link--disabled">
                    Anterior
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="wms-pagination-link">
                    Anterior
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="wms-pagination-link">
                    Siguiente
                </a>
            @else
                <span class="wms-pagination-link wms-pagination-link--disabled">
                    Siguiente
                </span>
            @endif
        </div>

        <div class="wms-pagination-desktop">
            <p class="wms-pagination-summary">
                Mostrando
                @if ($paginator->firstItem())
                    <strong>{{ $paginator->firstItem() }}</strong>
                    a
                    <strong>{{ $paginator->lastItem() }}</strong>
                @else
                    {{ $paginator->count() }}
                @endif
                de
                <strong>{{ $paginator->total() }}</strong>
                registros
            </p>

            <div class="wms-pagination-controls">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="Anterior">
                        <span class="wms-pagination-link wms-pagination-link--disabled" aria-hidden="true">
                            <svg class="wms-pagination-icon" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="wms-pagination-link" aria-label="Anterior">
                        <svg class="wms-pagination-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true">
                            <span class="wms-pagination-link wms-pagination-link--disabled">{{ $element }}</span>
                        </span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page">
                                    <span class="wms-pagination-link wms-pagination-link--active">{{ $page }}</span>
                                </span>
                            @else
                                <a href="{{ $url }}" class="wms-pagination-link" aria-label="Ir a la pagina {{ $page }}">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="wms-pagination-link" aria-label="Siguiente">
                        <svg class="wms-pagination-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </a>
                @else
                    <span aria-disabled="true" aria-label="Siguiente">
                        <span class="wms-pagination-link wms-pagination-link--disabled" aria-hidden="true">
                            <svg class="wms-pagination-icon" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif

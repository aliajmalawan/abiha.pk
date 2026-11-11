@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="btn btn--outline btn--sm" aria-disabled="true"><x-icon name="chevron-left" :size="16" /> Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn--outline btn--sm"><x-icon name="chevron-left" :size="16" /> Prev</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))<span class="px-2 text-grey-text" aria-hidden="true">{{ $element }}</span>@endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="btn btn--sm !px-0 w-10">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="btn btn--outline btn--sm !px-0 w-10" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn--outline btn--sm">Next <x-icon name="chevron-right" :size="16" /></a>
        @else
            <span class="btn btn--outline btn--sm" aria-disabled="true">Next <x-icon name="chevron-right" :size="16" /></span>
        @endif
    </nav>
@endif

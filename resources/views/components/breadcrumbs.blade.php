{{-- Visible breadcrumb trail, fed by the same data as the BreadcrumbList structured data (so the two can never disagree). --}}
@php $crumbs = app(\App\Support\Seo::class)->crumbs(); @endphp
@if(count($crumbs) > 1)
    <nav aria-label="Breadcrumb" {{ $attributes->class('text-xs text-grey-text') }}>
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
            @foreach($crumbs as $c)
                <li class="inline-flex items-center gap-1.5">
                    @if(! $loop->last && $c['url'])<a href="{{ $c['url'] }}" class="hover:underline inline-block py-1.5">{{ $c['name'] }}</a><span aria-hidden="true">/</span>
                    @else<span class="text-ink py-1.5" aria-current="page">{{ $c['name'] }}</span>@endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif

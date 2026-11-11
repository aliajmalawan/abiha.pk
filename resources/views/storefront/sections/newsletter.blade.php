<section class="section {{ $toneClass ?? '' }}">
    <div class="container max-w-2xl text-center" x-data="newsletter('{{ route('newsletter') }}')">
        <p class="eyebrow">Stay in touch</p>
        <h2>{{ $section->title }}</h2>
        @if($section->subtitle)<p class="lead mx-auto">{{ $section->subtitle }}</p>@endif
        <form method="POST" action="{{ route('newsletter') }}" @submit.prevent="submit()" class="mt-6" novalidate>
            @csrf
            <div class="input-group">
                <label for="nl-email" class="sr-only">Email address</label>
                <input id="nl-email" type="email" name="email" x-model="email" class="input !bg-white" placeholder="Your email address" required autocomplete="email">
                <button class="btn" :class="state === 'busy' && 'is-loading'">Subscribe</button>
            </div>
            <p class="mt-3 text-sm font-semibold" x-show="message" :class="state === 'error' ? 'text-danger' : 'text-success'" x-text="message" role="status"></p>
            @if(session('newsletter'))<p class="mt-3 text-sm text-success" role="status">{{ session('newsletter') }}</p>@endif
            @error('email')<p class="mt-3 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        </form>
    </div>
</section>

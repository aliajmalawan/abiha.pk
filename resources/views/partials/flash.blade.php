@if(session('status') || $errors->has('cart') || $errors->has('wishlist') || $errors->has('order'))
    <div class="container pt-4">
        @if(session('status'))<div class="alert alert--success" role="status">{{ session('status') }}</div>@endif
        @foreach(['cart', 'wishlist', 'order'] as $k)
            @error($k)<div class="alert alert--danger mt-2" role="alert">{{ $message }}</div>@enderror
        @endforeach
    </div>
@endif

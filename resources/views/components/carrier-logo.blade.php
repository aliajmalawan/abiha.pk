@props(['code', 'height' => 28])
@php $file = 'images/carriers/'.$code; @endphp
@if(in_array($code, ['dhl', 'fedex', 'ups'], true) && file_exists(public_path($file.'.png')))
    <picture class="carrier-logo"><source srcset="{{ asset($file.'.webp') }}" type="image/webp"><img src="{{ asset($file.'.png') }}" alt="{{ strtoupper($code === 'fedex' ? 'FedEx' : $code) }}" style="height:{{ (int) $height }}px;width:auto" loading="lazy" decoding="async"></picture>
@endif

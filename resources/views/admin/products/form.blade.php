@extends('admin.layout')
@section('title', $product->exists ? 'Edit product' : 'New product')

@section('content')
@php
    $f = fn ($k, $d = null) => old($k, $product->{$k} ?? $d);
    $attrData = $attributes->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'values' => $a->values->map(fn ($v) => ['id' => $v->id, 'value' => $v->value])->all()])->all();
@endphp
<a href="{{ route('admin.products.index') }}" class="link text-sm inline-flex items-center gap-1 mb-3"><x-icon name="chevron-left" :size="14" /> Products</a>
<header class="pf-head">
    <div>
        <h1 class="!text-2xl !mb-1">{{ $product->exists ? 'Edit product' : 'Add a new product' }}</h1>
        <p class="help">{{ $product->exists ? 'Update the details, photos and stock. Changes go live as soon as you save.' : 'Fill in the details, add photos, set the price and stock, then save. Fields marked * are required.' }}</p>
    </div>
    @if($product->exists)<a href="{{ route('product', $product) }}" target="_blank" rel="noopener" class="btn btn--sm btn--outline">View on website</a>@endif
</header>
@if($errors->any() && ! $errors->has('inventory'))<div class="alert alert--danger mb-4" role="alert"><strong>Please fix {{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }} below.</strong></div>@endif

<form method="POST" enctype="multipart/form-data" novalidate class="pf pb-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] items-start"
      action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
      x-data="{
          name: @js((string) $f('name', '')),
          sku: @js((string) $f('sku', '')),
          slug: @js((string) $f('slug', '')),
          skuAuto: {{ $f('sku') ? 'false' : 'true' }},
          slugAuto: {{ $f('slug') ? 'false' : 'true' }},
          slugify(t) { return t.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120); },
          makeSku(t) {
              const w = t.toUpperCase().normalize('NFKD').replace(/[^A-Z0-9 ]/g, '').split(/\s+/).filter(Boolean);
              if (!w.length) return '';
              const code = w.length > 1 ? w.slice(0, 3).map(x => x.slice(0, 3)).join('') : w[0].slice(0, 6);
              return 'ABH-' + code + '-' + (this.skuTail ??= String(Math.floor(1000 + Math.random() * 9000)));
          },
          onName() {
              if (this.slugAuto) this.slug = this.slugify(this.name);
              if (this.skuAuto) this.sku = this.makeSku(this.name);
          },
          hasVariants: {{ $f('has_variants') ? 'true' : 'false' }},
          attrs: @js($attrData),
          rows: @js($variantRows).map(r => ({...r, sel: {}})),
          init() { this.rows.forEach(r => this.attrs.forEach(a => { const hit = (r.options || []).find(id => a.values.some(v => v.id == id)); if (hit) r.sel[a.id] = hit; })); },
          add() { this.rows.push({ id: null, sku: '', price: '', compare_price: '', stock: 0, is_active: true, options: [], sel: {} }); },
          remove(i) { this.rows.splice(i, 1); },
          chosen(r) { return Object.values(r.sel).filter(Boolean); }
      }">
    @csrf
    @if($product->exists) @method('PUT') @endif

    <div class="space-y-6">
        <section class="card pf-card space-y-4">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="package" :size="18" /></span><div><h2 class="!text-lg !mb-0">Basic details</h2><p class="help">Name, code, category and the text customers read.</p></div></div>
            <x-field name="name" label="Product name" :value="$f('name')" required x-model="name" @input="onName()" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sku" label="SKU" :value="$f('sku')" required help="Made from the name automatically. You can change it; it must be unique." x-model="sku" @input="skuAuto = sku.trim() === ''" />
                <x-field name="slug" label="URL slug" :value="$f('slug')" help="Made from the name automatically. You can change it (letters, numbers and hyphens)." x-model="slug" @input="slugAuto = slug.trim() === ''" />
            </div>
            <div class="field"><label class="label" for="category_id">Category</label>
                <select class="select" id="category_id" name="category_id"><option value="">None</option>
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected($f('category_id') == $c->id)>{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>@endforeach</select></div>
            <x-field name="short_description" label="Short description" :value="$f('short_description')" />
            <div class="field"><label class="label" for="description">Description (HTML: p, ul, li, strong, em, br, h3, h4, a)</label>
                <textarea class="textarea !min-h-[180px]" id="description" name="description">{{ $f('description') }}</textarea>@error('description')<span class="error-text">{{ $message }}</span>@enderror</div>
        </section>

        <section class="card pf-card space-y-4" x-data="{ rows: @js(array_values((array) old('specifications', $product->specifications ?? []))) }">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="sliders-horizontal" :size="18" /></span><div class="flex-1"><h2 class="!text-lg !mb-0">Specifications</h2><p class="help">Optional facts shown as a table on the product page, e.g. fabric, care, fit.</p></div>
                <button type="button" class="btn btn--sm btn--outline" @click="if (rows.length < 25) rows.push({ label: '', value: '' })"><x-icon name="plus" :size="14" /> Add row</button></div>
            <template x-for="(row, i) in rows" :key="i">
                <div class="pf-spec">
                    <input class="input" type="text" maxlength="60" x-model="row.label" :name="`specifications[${i}][label]`" placeholder="Label, e.g. Fabric" :aria-label="`Specification ${i + 1} label`">
                    <input class="input" type="text" maxlength="300" x-model="row.value" :name="`specifications[${i}][value]`" placeholder="Value" :aria-label="`Specification ${i + 1} value`">
                    <button type="button" class="btn btn--sm btn--ghost" @click="rows.splice(i, 1)" :aria-label="`Remove specification ${i + 1}`">Remove</button>
                </div>
            </template>
            <p class="text-sm text-grey-text" x-show="rows.length === 0">No specifications yet.</p>
        </section>

        <section class="card pf-card space-y-4">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="tag" :size="18" /></span><div><h2 class="!text-lg !mb-0">Pricing {{ '&' }} stock</h2><p class="help">Prices are whole PKR. Customers are always charged the price saved here.</p></div></div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="price" label="Price (PKR)" type="number" min="0" :value="$f('price')" required />
                <x-field name="compare_price" label="Original price (optional)" type="number" min="0" :value="$f('compare_price')" help="Must be higher than the price. Shown struck through." />
                <x-field name="low_stock_threshold" label="Low-stock alert at" type="number" min="0" :value="$f('low_stock_threshold', 3)" />
            </div>
            <label class="check"><input type="checkbox" name="has_variants" value="1" x-model="hasVariants"> This product has variants (size / colour…)</label>
            <div x-show="!hasVariants"><x-field name="stock" label="Stock on hand" type="number" min="0" :value="$f('stock', 0)" help="Changes are recorded in the inventory ledger." /></div>
        </section>

        <section class="card pf-card space-y-4" x-show="hasVariants" x-cloak>
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="layout-dashboard" :size="18" /></span><div class="flex-1"><h2 class="!text-lg !mb-0">Variants</h2><p class="help">Each variant has its own SKU, stock and optional price (blank = product price).</p></div>
                <button type="button" class="btn btn--sm btn--outline" @click="add()"><x-icon name="plus" :size="14" /> Add variant</button></div>
            <p class="help">Manage the size / colour options under <a class="link" href="{{ route('admin.attributes.index') }}">Attributes</a>.</p>
            @error('variants')<p class="error-text">{{ $message }}</p>@enderror
            <template x-for="(r, i) in rows" :key="i">
                <div class="pf-variant">
                    <input type="hidden" :name="`variants[${i}][id]`" :value="r.id">
                    <template x-for="id in chosen(r)" :key="id"><input type="hidden" :name="`variants[${i}][options][]`" :value="id"></template>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <template x-for="a in attrs" :key="a.id">
                            <div class="field"><label class="label text-xs" x-text="a.name"></label>
                                <select class="select !min-h-[40px]" x-model="r.sel[a.id]"><option value="">None</option>
                                    <template x-for="v in a.values" :key="v.id"><option :value="v.id" x-text="v.value" :selected="r.sel[a.id] == v.id"></option></template></select></div>
                        </template>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="field"><label class="label text-xs">SKU</label><input class="input !min-h-[40px]" :name="`variants[${i}][sku]`" x-model="r.sku" required></div>
                        <div class="field"><label class="label text-xs">Price (PKR, optional)</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][price]`" x-model="r.price"></div>
                        <div class="field"><label class="label text-xs">Original price (optional)</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][compare_price]`" x-model="r.compare_price"></div>
                        <div class="field"><label class="label text-xs">Stock</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][stock]`" x-model="r.stock" required></div>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="check"><input type="checkbox" value="1" :name="`variants[${i}][is_active]`" x-model="r.is_active"> Available to customers</label>
                        <button type="button" class="text-sm underline text-danger" @click="remove(i)">Remove variant</button>
                    </div>
                </div>
            </template>
            <p class="muted text-sm" x-show="rows.length === 0">No variants yet. Add at least one.</p>
            @foreach($errors->getMessages() as $key => $msgs) @if(str_starts_with($key, 'variants.'))<p class="error-text">{{ $key }}: {{ $msgs[0] }}</p>@endif @endforeach
        </section>

        <section class="card pf-card space-y-4">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="eye" :size="18" /></span><div><h2 class="!text-lg !mb-0">Photos</h2><p class="help">The first photo is the main one shown in the shop.</p></div></div>
            @if($product->exists && $product->images->isNotEmpty())
                <div x-data="imageOrder(@js($product->images->map(fn ($i) => ['id' => $i->id, 'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($i->path), 'alt' => old('image_alts.'.$i->id, $i->alt), 'remove' => in_array($i->id, array_map('intval', (array) old('remove_images', [])), true)])->values()))">
                    <p class="help mb-2">The <strong>first</strong> photo is the main one shown in the shop; the second shows on hover. Use the arrows to reorder, then save.</p>
                    <ol class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3" x-ref="list">
                        <template x-for="(img, idx) in items" :key="img.id">
                            <li class="text-center text-xs rounded border border-line p-2 bg-white">
                                <input type="hidden" name="image_order[]" :value="img.id">
                                <div class="relative">
                                    <img :src="img.url" :alt="img.alt" class="aspect-square object-cover rounded border border-line w-full" :class="img.remove ? 'opacity-30' : ''">
                                    <span class="badge badge--gold absolute left-1 top-1" x-show="idx === 0 && !img.remove">Main</span>
                                    <span class="badge badge--ivory absolute left-1 top-1" x-show="idx !== 0" x-text="idx + 1"></span>
                                </div>
                                <input class="input !min-h-9 !text-xs mt-2" type="text" :name="`image_alts[${img.id}]`" x-model="img.alt" maxlength="160" :aria-label="`Alt text for photo ${idx + 1}`" placeholder="Describe the image">
                                <div class="flex flex-wrap items-center justify-center gap-1 mt-2">
                                    <button type="button" class="btn btn--sm btn--outline !px-2" data-focus-move @click="move(idx, -1)" :disabled="idx === 0" :aria-label="`Move photo ${idx + 1} earlier`">&larr;</button>
                                    <button type="button" class="btn btn--sm btn--outline !px-2" data-focus-move @click="move(idx, 1)" :disabled="idx === items.length - 1" :aria-label="`Move photo ${idx + 1} later`">&rarr;</button>
                                    <button type="button" class="btn btn--sm btn--ghost !px-2 underline" @click="makeMain(idx)" x-show="idx !== 0" :aria-label="`Make photo ${idx + 1} the main photo`">Make main</button>
                                </div>
                                <label class="check justify-center mt-2"><input type="checkbox" name="remove_images[]" :value="img.id" x-model="img.remove"> Remove</label>
                            </li>
                        </template>
                    </ol>
                </div>
            @endif
            <div class="field" x-data="uploadCheck(4, 12)">
                <label class="pf-drop" for="images" :class="over && 'pf-drop--over'" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="over = false; $refs.file.files = $event.dataTransfer.files; check({ target: $refs.file })">
                    <span class="pf-drop__icon"><x-icon name="plus" :size="22" /></span>
                    <span class="pf-drop__title">Click to choose photos or drag them here</span>
                    <span class="pf-drop__hint">JPG, PNG or WebP · up to 4 MB each · at most 12 photos</span>
                    <input class="sr-only" type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp" x-ref="file" @change="check($event)">
                </label>
                <div class="pf-size" role="note">
                    <x-icon name="info" :size="18" />
                    <div>
                        <p><strong>Recommended size: 1200 × 1500 px</strong> (portrait, ratio 4:5, the same shape as the shop cards and product page).</p>
                        <p>Smallest that still looks sharp: 800 × 1000 px. Very large photos are reduced automatically. Keep the whole item inside the frame and use a plain background; the full photo is always shown, nothing is cropped. Use the same size for every photo of a product.</p>
                    </div>
                </div>
                <ul class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 mt-3" x-show="files.length" x-cloak>
                    <template x-for="f in files" :key="f.name + f.mb">
                        <li class="text-xs rounded border p-2" :class="f.problem ? 'border-danger' : 'border-line'">
                            <img x-show="f.url" :src="f.url" alt="" class="aspect-square object-cover rounded w-full">
                            <span class="block truncate mt-1" x-text="f.name"></span><span class="text-grey-text" x-text="f.mb + ' MB'"></span>
                            <span class="block text-danger" x-show="f.problem" x-text="f.problem" role="alert"></span>
                        </li>
                    </template>
                </ul>
                <p class="error-text" x-show="tooMany" x-cloak role="alert">Choose at most 12 photos.</p>
                @error('images')<span class="error-text">{{ $message }}</span>@enderror @error('images.*')<span class="error-text">{{ $message }}</span>@enderror</div>
        </section>
    </div>

    <aside class="space-y-6">
        <section class="card pf-card space-y-3">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="eye" :size="18" /></span><div><h2 class="!text-lg !mb-0">Visibility</h2><p class="help">Where this product appears.</p></div></div>
            @foreach(['is_active' => 'Visible in the shop', 'is_featured' => 'Featured', 'is_new' => 'New arrival', 'is_bestseller' => 'Best seller', 'is_trending' => 'Trending'] as $k => $l)
                <label class="check pf-toggle"><input type="checkbox" name="{{ $k }}" value="1" @checked($f($k, $k === 'is_active'))> {{ $l }}</label>
            @endforeach
            <x-field name="badge" label="Custom badge (optional)" :value="$f('badge')" help="e.g. Limited" />
        </section>
        <section class="card pf-card space-y-3">
            <div class="pf-card__head"><span class="pf-card__icon"><x-icon name="search" :size="18" /></span><div><h2 class="!text-lg !mb-0">Search engines</h2><p class="help">Optional. Blank = made from the name and description.</p></div></div>
            <x-field name="meta_title" label="SEO title" :value="$f('meta_title')" />
            <div class="field"><label class="label" for="meta_description">SEO description</label><textarea class="textarea !min-h-[80px]" id="meta_description" name="meta_description">{{ $f('meta_description') }}</textarea></div>
        </section>
    </aside>

    {{-- The Save button stays at the bottom of the screen, so it is always reachable (on a laptop the right column is taller than the screen). --}}
    <div class="pf-bar lg:col-span-2 no-print">
        <div class="pf-bar__info">
            <span class="pf-bar__dot" aria-hidden="true"></span>
            <p>@if($product->exists)<span class="pf-bar__label">Editing</span><strong>{{ $product->name }}</strong>@else<span class="pf-bar__label">New product</span><strong>Not saved yet</strong>@endif</p>
        </div>
        <div class="pf-bar__actions">
            <a href="{{ route('admin.products.index') }}" class="pf-bar__cancel"><x-icon name="x" :size="16" /> Cancel</a>
            <button type="submit" class="pf-bar__save"><x-icon name="check" :size="18" /> {{ $product->exists ? 'Save changes' : 'Save product' }}</button>
        </div>
    </div>
</form>
@endsection

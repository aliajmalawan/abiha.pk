<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ABIHA® Design System</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/brand/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('assets/brand/apple-touch-icon.png') }}">
<style>
  .ds-nav{position:sticky;top:0;z-index:var(--z-header);background:rgba(255,255,255,.94);border-bottom:1px solid var(--border)}
  .ds-nav .container{display:flex;align-items:center;gap:var(--sp-6);overflow-x:auto;min-height:64px}
  .ds-nav a{white-space:nowrap}
  .ds-section h2{margin-bottom:var(--sp-2)}
  .swatches{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:var(--sp-4)}
  .sw{border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;background:#fff}
  .sw i{display:block;height:96px;background:var(--c)}
  .sw div{padding:var(--sp-3) var(--sp-4);font-size:var(--fs-sm)}
  .sw b{display:block;font-family:var(--font-display)} .sw code{color:var(--text-secondary)}
  table{width:100%;border-collapse:collapse;font-size:var(--fs-sm)}
  th,td{text-align:left;padding:var(--sp-3);border-bottom:1px solid var(--border)} th{font-family:var(--font-display)}
  .pass{color:var(--success);font-weight:700} .fail{color:var(--danger);font-weight:700}
  .tbl-wrap{overflow-x:auto}
  .demo{border:1px solid var(--border);border-radius:var(--radius-lg);padding:var(--sp-8);background:#fff}
  .demo.on-dark{background:var(--abiha-black);border-color:var(--abiha-black)}
  .bar{display:block;height:16px;background:var(--abiha-gold);border-radius:2px}
  .box{display:grid;place-content:center;height:96px;background:var(--abiha-ivory);border:1px solid var(--border);font-size:var(--fs-sm)}
  .logo-cell{display:grid;place-content:center;border:1px solid var(--border);border-radius:var(--radius-lg);padding:var(--sp-6);min-height:200px}
  .clear-demo{outline:1px dashed var(--accent);outline-offset:-1px;position:relative}
  .rules li{margin-bottom:var(--sp-2)}
  .x{color:var(--danger);font-weight:700}
</style>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<nav class="ds-nav" aria-label="Design system sections"><div class="container">
  <strong style="font-family:var(--font-display);letter-spacing:.14em;white-space:nowrap">ABIHA® DESIGN SYSTEM</strong>
  <a class="nav-link" href="#logo">Logo</a><a class="nav-link" href="#color">Colour</a><a class="nav-link" href="#type">Type</a>
  <a class="nav-link" href="#space">Space</a><a class="nav-link" href="#buttons">Buttons</a><a class="nav-link" href="#forms">Inputs</a>
  <a class="nav-link" href="#cards">Cards</a><a class="nav-link" href="#responsive">Breakpoints</a>
</div></nav>

<main id="main">
<header class="section section--ivory"><div class="container">
  <p class="eyebrow">Phase 1 · Brand identity</p>
  <h1>ABIHA® design system</h1>
  <p class="lead">Premium, elegant, modern, minimal. Black leads, white and ivory carry the product, and gold is a selective highlight. These tokens are the only source of colour, type and spacing for the storefront and admin.</p>
</div></header>

<!-- LOGO -->
<section class="section ds-section" id="logo"><div class="container">
  <p class="eyebrow">01</p><h2>Logo</h2>
  <p class="lead">The supplied ABIHA® artwork is used as-is. It is cropped and resized only, never redrawn or re-typeset. Width controls height, so proportions cannot distort.</p>
  <div class="grid grid--3" style="margin-top:var(--sp-8)">
    <div class="logo-cell"><span class="logo clear-demo" style="--logo-w:220px"><img src="{{ asset('assets/brand/abiha-logo-full-600.png') }}" alt="ABIHA®"></span></div>
    <div class="logo-cell section--ivory"><span class="logo" style="--logo-w:220px"><img src="{{ asset('assets/brand/abiha-logo-full-600.png') }}" alt="ABIHA®"></span></div>
    <div class="logo-cell" style="background:var(--abiha-white)"><span class="logo logo--mono" style="--logo-w:140px"><img src="{{ asset('assets/brand/abiha-monogram-512.png') }}" alt="ABIHA AB monogram"></span></div>
  </div>
  <p class="help" style="margin-top:var(--sp-3)">Left: full logo with clear-space guide (dashed). Centre: on Warm Ivory (multiply blend removes the white field). Right: AB monogram for favicon, app icon and compact spaces.</p>
  <ul class="rules" style="margin-top:var(--sp-6)">
    <li><b>Clear space:</b> 12% of logo width on every side (<code>--logo-clear</code>).</li>
    <li><b>Minimum size:</b> full logo 120px wide; monogram 32px.</li>
    <li><b>Backgrounds:</b> white or ivory only. The master has a white field, and a dark-background version needs a transparent or reversed asset from the brand owner (<span class="sample-flag">ASSET NEEDED</span>).</li>
    <li class="x">Never stretch, recolour, add effects, crop the swoosh, or retype the wordmark in a font.</li>
  </ul>
  <p class="muted" style="font-size:var(--fs-sm)">Files: <code>assets/brand/</code> → master PNG/PDF (untouched), <code>abiha-logo-full*.png</code>, <code>abiha-monogram-512.png</code>, <code>apple-touch-icon.png</code>, <code>favicon-32/64.png</code>.</p>
</div></section>

<!-- COLOUR -->
<section class="section section--ivory ds-section" id="color"><div class="container">
  <p class="eyebrow">02</p><h2>Colour</h2>
  <p class="lead">Official palette below. Gold is a highlight: rules, icons, focus rings, badges, and a hover or accent CTA. It is never body-text colour on light backgrounds.</p>
  <div class="swatches" style="margin-top:var(--sp-8)">
    <div class="sw"><i style="--c:#101010"></i><div><b>Primary Black</b><code>#101010</code><br><small>CTAs, nav, headings</small></div></div>
    <div class="sw"><i style="--c:#C99A35"></i><div><b>Luxury Gold</b><code>#C99A35</code><br><small>Accents, rules, focus</small></div></div>
    <div class="sw"><i style="--c:#E5C77B"></i><div><b>Champagne Gold</b><code>#E5C77B</code><br><small>Gold on black, hovers</small></div></div>
    <div class="sw"><i style="--c:#FFFFFF"></i><div><b>Pure White</b><code>#FFFFFF</code><br><small>Page, product canvas</small></div></div>
    <div class="sw"><i style="--c:#F8F6F1"></i><div><b>Warm Ivory</b><code>#F8F6F1</code><br><small>Alternate sections</small></div></div>
    <div class="sw"><i style="--c:#777777"></i><div><b>Premium Grey</b><code>#777777</code><br><small>Large/secondary only</small></div></div>
    <div class="sw"><i style="--c:#EAEAEA"></i><div><b>Border Grey</b><code>#EAEAEA</code><br><small>Dividers, card borders</small></div></div>
  </div>

  <h3 style="margin-top:var(--sp-12)">Accessible derivatives</h3>
  <p class="muted">Two official colours fall below WCAG 2.1 AA for text on light backgrounds, so tinted variants are added. The brand colours themselves are unchanged.</p>
  <div class="swatches">
    <div class="sw"><i style="--c:#8A6513"></i><div><b>Gold Text</b><code>#8A6513</code><br><small>--gold-text · eyebrows, links</small></div></div>
    <div class="sw"><i style="--c:#6B6B6B"></i><div><b>Grey Text</b><code>#6B6B6B</code><br><small>--grey-text · muted copy</small></div></div>
    <div class="sw"><i style="--c:#8C8C8C"></i><div><b>Input Border</b><code>#8C8C8C</code><br><small>--border-input · 3:1 UI</small></div></div>
  </div>

  <h3 style="margin-top:var(--sp-12)">Contrast audit (WCAG 2.1)</h3>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Foreground</th><th>Background</th><th>Ratio</th><th>Use</th><th>Result</th></tr></thead>
    <tbody>
      <tr><td>Black #101010</td><td>White</td><td>18.9:1</td><td>Body text</td><td class="pass">AAA</td></tr>
      <tr><td>Black #101010</td><td>Ivory</td><td>17.6:1</td><td>Body text</td><td class="pass">AAA</td></tr>
      <tr><td>Black #101010</td><td>Gold #C99A35</td><td>7.4:1</td><td>Gold button label</td><td class="pass">AAA</td></tr>
      <tr><td>Champagne #E5C77B</td><td>Black</td><td>11.6:1</td><td>Gold text on dark</td><td class="pass">AAA</td></tr>
      <tr><td>Gold Text #8A6513</td><td>White / Ivory</td><td>5.3 / 4.9:1</td><td>Eyebrows, links</td><td class="pass">AA</td></tr>
      <tr><td>Grey Text #6B6B6B</td><td>Ivory</td><td>4.9:1</td><td>Muted copy</td><td class="pass">AA</td></tr>
      <tr><td>Gold #C99A35</td><td>White</td><td>2.6:1</td><td>Text</td><td class="fail">Fails: decorative only</td></tr>
      <tr><td>Premium Grey #777777</td><td>Ivory</td><td>4.2:1</td><td>Small text</td><td class="fail">Fails: use Grey Text</td></tr>
      <tr><td>Border Grey #EAEAEA</td><td>White</td><td>1.2:1</td><td>Input outline</td><td class="fail">Dividers only</td></tr>
    </tbody></table></div>
</div></section>

<!-- TYPE -->
<section class="section ds-section" id="type"><div class="container">
  <p class="eyebrow">03</p><h2>Typography</h2>
  <p class="lead"><b>Cormorant Garamond</b> for headings. <b>Inter</b> (SemiBold for buttons) for navigation, prices and body copy. Both are self-hosted variable fonts (no third-party requests, GDPR-friendly, fast on cPanel).</p>
  <div class="demo" style="margin-top:var(--sp-8)">
    <p class="eyebrow">New season · Eyebrow</p>
    <h1>Display 4XL — Quiet luxury</h1>
    <h2>Heading 3XL — Crafted to last</h2>
    <h3>Heading 2XL — The signature collection</h3>
    <h4>Heading LG — Product title</h4>
    <p class="lead">Lead — Short, confident introductions set in Inter, never longer than about 62 characters per line.</p>
    <p>Body — Inter 16/1.6. Considered spacing and generous margins let each product breathe, keeping the page calm and the checkout effortless.</p>
    <p class="muted" style="font-size:var(--fs-sm)">Small — Delivery details, helper text and legal copy.</p>
    <p class="price" style="font-size:var(--fs-xl)">PKR 0,000 <s>PKR 0,000</s> <span class="sample-flag">SAMPLE PRICE</span></p>
  </div>
  <div class="tbl-wrap" style="margin-top:var(--sp-6)"><table>
    <thead><tr><th>Token</th><th>Value</th><th>Token</th><th>Value</th></tr></thead><tbody>
    <tr><td>--fs-xs</td><td>12px</td><td>--fs-xl</td><td>24px</td></tr>
    <tr><td>--fs-sm</td><td>14px</td><td>--fs-2xl</td><td>28→36px fluid</td></tr>
    <tr><td>--fs-base</td><td>16px</td><td>--fs-3xl</td><td>34→48px fluid</td></tr>
    <tr><td>--fs-md / lg</td><td>18 / 20px</td><td>--fs-4xl</td><td>42→68px fluid</td></tr></tbody></table></div>
</div></section>

<!-- SPACE, RADIUS, SHADOW -->
<section class="section section--ivory ds-section" id="space"><div class="container">
  <p class="eyebrow">04</p><h2>Spacing, radius &amp; shadow</h2>
  <p class="lead">4px base grid. Corners stay restrained (2–8px), because sharp edges read as luxury, and pills are reserved for chips and tags.</p>
  <div class="stack" style="--stack:var(--sp-2);margin:var(--sp-8) 0">
    <div class="cluster"><code style="width:70px">sp-1 · 4</code><span class="bar" style="width:4px"></span></div>
    <div class="cluster"><code style="width:70px">sp-2 · 8</code><span class="bar" style="width:8px"></span></div>
    <div class="cluster"><code style="width:70px">sp-4 · 16</code><span class="bar" style="width:16px"></span></div>
    <div class="cluster"><code style="width:70px">sp-6 · 24</code><span class="bar" style="width:24px"></span></div>
    <div class="cluster"><code style="width:70px">sp-8 · 32</code><span class="bar" style="width:32px"></span></div>
    <div class="cluster"><code style="width:70px">sp-12 · 48</code><span class="bar" style="width:48px"></span></div>
    <div class="cluster"><code style="width:70px">sp-16 · 64</code><span class="bar" style="width:64px"></span></div>
    <div class="cluster"><code style="width:70px">sp-24 · 96</code><span class="bar" style="width:96px"></span></div>
  </div>
  <div class="grid grid--4" style="--gap:var(--sp-4)">
    <div class="box" style="border-radius:var(--radius-sm)">radius-sm 2</div>
    <div class="box" style="border-radius:var(--radius-md)">radius-md 4</div>
    <div class="box" style="border-radius:var(--radius-lg)">radius-lg 8</div>
    <div class="box" style="border-radius:var(--radius-xl)">radius-xl 16</div>
  </div>
  <div class="grid grid--4" style="--gap:var(--sp-6);margin-top:var(--sp-8)">
    <div class="box" style="background:#fff;box-shadow:var(--shadow-xs)">shadow-xs</div>
    <div class="box" style="background:#fff;box-shadow:var(--shadow-sm)">shadow-sm</div>
    <div class="box" style="background:#fff;box-shadow:var(--shadow-md)">shadow-md</div>
    <div class="box" style="background:#fff;box-shadow:var(--shadow-lg)">shadow-lg</div>
  </div>
</div></section>

<!-- BUTTONS -->
<section class="section ds-section" id="buttons"><div class="container">
  <p class="eyebrow">05</p><h2>Buttons</h2>
  <p class="lead">Black is the primary action (one per view). Gold is the highlight CTA. Outline and ghost are secondary. All controls are at least 44px tall.</p>
  <div class="demo stack" style="--stack:var(--sp-6);margin-top:var(--sp-8)">
    <div class="cluster"><button class="btn">Add to bag</button><button class="btn btn--gold">Checkout</button><button class="btn btn--outline">View details</button><button class="btn btn--ghost">Continue shopping</button></div>
    <div class="cluster"><button class="btn btn--sm">Small</button><button class="btn">Default</button><button class="btn btn--lg">Large</button><button class="btn" disabled>Disabled</button><button class="btn is-loading">Loading</button></div>
  </div>
  <div class="demo on-dark stack" style="margin-top:var(--sp-4)"><div class="cluster"><button class="btn btn--light">Shop now</button><button class="btn btn--gold">Explore</button></div></div>
</div></section>

<!-- FORMS -->
<section class="section section--ivory ds-section" id="forms"><div class="container">
  <p class="eyebrow">06</p><h2>Inputs</h2>
  <p class="lead">Visible labels, 3:1 outlines, gold focus halo, inline errors with text (not colour alone).</p>
  <form class="demo grid grid--2" style="margin-top:var(--sp-8)" novalidate x-data @submit.prevent>
    <div class="field"><label class="label" for="f1">Full name <span class="req" aria-hidden="true">*</span></label><input class="input" id="f1" placeholder="Sample name" autocomplete="name"></div>
    <div class="field"><label class="label" for="f2">Email</label><input class="input" id="f2" type="email" placeholder="name@example.com" aria-invalid="true" aria-describedby="e2"><span class="error-text" id="e2">Enter a valid email address.</span></div>
    <div class="field"><label class="label" for="f3">City</label><select class="select" id="f3"><option>Select city (sample)</option></select></div>
    <div class="field"><label class="label" for="f4">Phone</label><input class="input" id="f4" type="tel" placeholder="03XX XXXXXXX"><span class="help">We only use this for delivery updates.</span></div>
    <div class="field" style="grid-column:1/-1"><label class="label" for="f5">Order notes</label><textarea class="textarea" id="f5"></textarea></div>
    <label class="check"><input type="checkbox" checked> Save this address</label>
    <div class="cluster"><label class="check"><input type="radio" name="r" checked> Option A</label><label class="check"><input type="radio" name="r"> Option B</label></div>
    <div class="field"><span class="label" id="sz">Size (sample)</span><div class="cluster" role="radiogroup" aria-labelledby="sz"><button class="chip" role="radio" aria-checked="false">S</button><button class="chip" role="radio" aria-checked="true">M</button><button class="chip" role="radio" aria-checked="false">L</button><button class="chip" role="radio" aria-checked="false" disabled>XL</button></div></div>
    <div class="field"><span class="label" id="cl">Colour (sample)</span><div class="cluster" role="radiogroup" aria-labelledby="cl"><button class="swatch" role="radio" aria-label="Black" aria-checked="true" style="--c:#101010"></button><button class="swatch" role="radio" aria-label="Ivory" aria-checked="false" style="--c:#F8F6F1"></button><button class="swatch" role="radio" aria-label="Gold" aria-checked="false" style="--c:#C99A35"></button></div></div>
    <div class="field" style="grid-column:1/-1"><label class="label" for="nl">Newsletter</label><div class="input-group"><input class="input" id="nl" type="email" placeholder="Your email"><button class="btn" type="button">Subscribe</button></div></div>
  </form>
</div></section>

<!-- CARDS -->
<section class="section ds-section" id="cards"><div class="container">
  <p class="eyebrow">07</p><h2>Cards &amp; feedback</h2>
  <p class="lead">Product images sit on white or ivory. Product data below is a clearly labelled placeholder.</p>
  <div class="grid grid--4 grid--products" style="margin-top:var(--sp-8)">
    <article class="product-card"><div class="product-card__media"><span class="badge badge--gold product-card__badge">New</span><button class="product-card__wish" aria-label="Add to wishlist"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-4.6-9.3-9.2C1 8.4 3 5 6.4 5c2 0 3.4 1.1 4.1 2.3h3c.7-1.2 2.100-2.300 4.100-2.300C21 5 23 8.400 21.300 11.800 19 16.400 12 21 12 21z"/></svg></button></div>
      <div><span class="product-card__cat">Category</span><h3 class="product-card__title"><a href="#cards">Sample Product Name</a></h3><p class="price" style="margin:0">PKR 0,000</p></div></article>
    <article class="product-card"><div class="product-card__media"><span class="badge product-card__badge">Sale</span></div>
      <div><span class="product-card__cat">Category</span><h3 class="product-card__title"><a href="#cards">Sample Product Name</a></h3><p class="price" style="margin:0">PKR 0,000 <s>PKR 0,000</s></p></div></article>
    <div class="card card--hover"><p class="eyebrow">Card</p><h4>Standard card</h4><p class="muted">White surface, 1px Border Grey, champagne border on hover.</p></div>
    <div class="card card--ivory"><p class="eyebrow">Card · ivory</p><h4>Ivory card</h4><p class="muted">For callouts and policy highlights.</p></div>
  </div>
  <div class="stack" style="margin-top:var(--sp-8)">
    <div class="alert alert--success"><strong>Order placed.</strong> A confirmation was sent to your email.</div>
    <div class="alert alert--warning"><strong>Low stock.</strong> Only a few left.</div>
    <div class="alert alert--danger"><strong>Payment failed.</strong> Please try another method.</div>
    <div class="alert alert--info"><strong>Note.</strong> Delivery times vary by city.</div>
    <div class="cluster"><span class="badge">Black</span><span class="badge badge--gold">Gold</span><span class="badge badge--ivory">Ivory</span><span class="badge badge--success">Delivered</span><span class="badge badge--warning">Pending</span><span class="badge badge--danger">Cancelled</span><span class="badge badge--info">Shipped</span></div>
  </div>
</div></section>

<!-- RESPONSIVE -->
<section class="section section--ivory ds-section" id="responsive"><div class="container">
  <p class="eyebrow">08</p><h2>Responsive breakpoints</h2>
  <p class="lead">Mobile-first. Fluid type and gutters scale between breakpoints with <code>clamp()</code>.</p>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Name</th><th>Min width</th><th>Typical device</th><th>Product grid</th></tr></thead><tbody>
    <tr><td>base</td><td>0</td><td>Phones</td><td>2 columns</td></tr>
    <tr><td>sm</td><td>480px</td><td>Large phones</td><td>2 columns</td></tr>
    <tr><td>md</td><td>768px</td><td>Tablets</td><td>3 columns</td></tr>
    <tr><td>lg</td><td>1024px</td><td>Small laptops</td><td>4 columns</td></tr>
    <tr><td>xl</td><td>1280px</td><td>Desktops (container max)</td><td>4 columns</td></tr>
    <tr><td>2xl</td><td>1536px</td><td>Wide screens</td><td>4 columns + larger gutters</td></tr></tbody></table></div>
</div></section>
</main>

<footer class="section section--dark on-dark"><div class="container cluster" style="justify-content:space-between">
  <span class="eyebrow">ABIHA® · abiha.pk</span><span style="font-size:var(--fs-sm);color:#bdbdbd">Design system v1.0 · internal reference · not indexed</span>
</div></footer>
</body>
</html>

# ABIHA brand rules (what the website follows)

One place that says how the logo, colours, fonts and tone are used, so every page, email, invoice and the admin look like one brand.

## Logo
- Use the logo only through `logo($width)` (or `logo($width, true)` for the monogram). It keeps the artwork's own proportions, so it is never stretched, recoloured or retyped.
- The supplied artwork lives in `public/assets/brand/` (`abiha-logo-master.png`, `.pdf` are the originals: never edit them). Sizes are generated from them.
- An uploaded logo (Admin -> General settings -> Brand logo) replaces it everywhere: header, footer, emails, invoices, admin. A solid black or white background on the upload is removed automatically.
- Clear space: 12 % of the logo width on every side (`--logo-clear`). Smallest size on screen: 72 px wide (the header uses 76).
- Backgrounds: ivory or white (header, emails, invoices) and black (footer). Never on a photo, a gradient or a gold area.
- Favicon: the monogram on a transparent background (`public/favicon.ico`, `favicon-32/64.png`); home-screen icons `icon-192.png`, `icon-512.png` and `apple-touch-icon.png` (ivory square). Admin -> General settings -> Favicon icon replaces it.

## Colours (official palette; flat colour only, no gradients)
| Name | Hex | Use |
|---|---|---|
| Primary black | `#101010` | text, buttons, footer, headers of dark sections |
| Luxury gold | `#C99A35` | accents, rules, top bar, badges, focus ring (decorative: it is too light for small text on white) |
| Champagne | `#E5C77B` | gold on black (text, icons, hover) |
| Warm ivory | `#F8F6F1` | page bands, header, cards, product image backgrounds |
| White | `#FFFFFF` | pages and cards |
| Premium grey | `#777777` (text variant `#6B6B6B`) | secondary text |
| Border | `#EAEAEA` (storefront hairlines use warm `#E9E4D8`) | dividers |
Accessible text variants: `--gold-text #8A6513` for gold text on light backgrounds, `--grey-text #6B6B6B`, `--border-input #8C8C8C`. All colours are tokens in `resources/abiha-css-source/tokens.css` (compiled into `public/assets/abiha/abiha.css`); `theme.css` only refines them. Do not add new colours; status colours (success, warning, danger, info) are for messages only.

## Typography
- Headings: **Cormorant Garamond** (600 / 700), lining figures. Everything else (navigation, buttons, prices, tables, forms, admin): **Inter**.
- Buttons and labels: Inter SemiBold, uppercase, letter-spaced. Eyebrows: Inter Bold, 0.16 em tracking, gold text.
- Both fonts are self-hosted (`public/assets/abiha/fonts/`), so there are no third-party requests. Emails use Georgia / Arial because email clients ignore web fonts.

## Name and tone
- The name is printed as **ABIHA®** (wordmark) or the shop name from Admin -> General settings followed by ®; never invent claims, prices, offers or reviews.
- Voice: clear, warm, short sentences; Pakistan English; prices as `PKR 1,200`.

## Shape and motion
- Cards and controls have restrained rounded corners (6-14 px); buttons are square-ish with a 6 px radius; shadows are soft and warm.
- Hover and focus motion is short (150-350 ms) and switched off for visitors who ask for reduced motion.
- Depth on the home page (`theme.css` "Home page depth", `home.js`): cards tilt at most 6° towards the pointer, pictures and the hero drift at most 2 % with the pointer and 10 % with the scroll, and a soft white light follows the pointer. Fine pointers only, never on touch screens, and nothing moves for people who asked for reduced motion. Still no gradients: light is a blurred white disc, shade is black at low opacity.

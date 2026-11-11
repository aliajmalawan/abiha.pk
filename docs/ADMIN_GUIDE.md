# ABIHA® admin guide

For the shop owner and staff. The admin is at **`/admin`** (for example `https://abiha.pk/admin`). Sign in with a staff account; what you see in the left menu depends on your role. Technical setup is in [DEPLOYMENT.md](DEPLOYMENT.md).

## Who can do what (roles)

| Role | Can use |
|---|---|
| **Super Admin** | Everything, including staff roles and the activity log |
| **Admin** | Everything except managing staff roles |
| **Order Manager** | Orders, payment verification, shipping details, shipments, payment history, customer messages |
| **Product Manager** | Products and variants, categories, collections, reviews |
| **Inventory Manager** | Stock levels, adjustments, the stock history |
| **Content Manager** | Homepage, banners, pages, menus, newsletter, messages |

The server enforces this: a page or action outside your role is refused even if someone types the address. A Super Admin gives roles under **Staff & roles**. The last Super Admin cannot be demoted.

## A normal day

1. **Dashboard**: new orders, payments to verify, low stock and the bell (top right) for alerts.
2. **Orders**: open a new order, check the payment, move it to *Confirmed* → *Processing* → *Shipped* → *Delivered*. Every change is saved in the order history. Cancelling or returning puts the stock back (returns only if you tick the box after checking the goods).
3. **Payments**: *Cash on delivery*: after the rider returns the cash, open the order → **Mark as paid**. *Bank transfer*: open the order, click **View receipt**, compare it with your bank statement, then **Mark as paid** (exact amount) or **Payment not received**. The order is never marked paid by itself.
4. **Shipping a parcel**: hand it to your courier, then on the order fill **Courier**, **Tracking number**, **Tracking link** and set the status to *Shipped*. The customer sees exactly what you typed, and gets an email.
5. **Inventory**: stock changes are made under *Inventory* (adjust with a reason) or in the product form. Everything is written to the stock history.

## Catalogue

- **Products**: name, price, optional original price (shows "Save %"), SKU, category, description, specifications, SEO title and description, visibility, *Featured*, *New arrival*. Photos: up to 12 per product (JPG/PNG/WebP, 4 MB each); use the arrows to reorder, the first photo is the main one. **Variants**: tick "This product has variants", then give each size/colour combination its own SKU, stock and (optionally) price.
- **Categories / Collections**: parent and sub-categories, image, description, order, active or hidden. A category with products cannot be deleted; hide it instead.
- **Sale pricing**: set a percentage sale on many products at once. **Featured products**: tick the ones to promote.
- **Coupons**: percentage or fixed amount, minimum order, maximum discount, start and end date, total and per-customer limits, active or not, and optionally **only for chosen categories or products** (the discount then applies only to those items). A coupon that has been used cannot be deleted; deactivate it.

## Website

- **Homepage**: add, hide, remove and reorder blocks; edit each block's title, number of items and, for product rows, which products, the **layout** (carousel / grid / shop the look) and **collection tabs**. Hero, campaign and promo pictures and text are under **Banners**. The picture is always shown whole. Ticked **"Show the headline, text and button on top of the picture"**: your eyebrow, headline, text and button appear in a white card over the picture (below it on phones). If the picture is a finished design with its own words, untick it: only the button is shown over the picture (the headline is still kept for search engines).
- **Pages**: About, Contact, FAQ, Shipping, Returns, Privacy, Terms; tick "show in footer". The **FAQ** page comes with ready answers (ordering, payment, delivery, returns, account) that follow how the site works; the payment answer lists the methods you have switched on, and phone and email come from Settings. Delivery times and the return policy are taken from the Shipping & returns page. To use your own FAQ text instead, write it in the FAQ page body; it then replaces the built-in questions. The built-in questions can be changed in `config/faq.php`. The **About us** page is also ready-made: a hero, three promises (performance, comfort, style), your categories that have products, how shopping works and a contact block. It uses only facts the shop really has (categories, payment methods, phone, email). To tell your own story (history, team, values), write it in the About us page body; it then replaces the built-in page.
- **Menus**: the header links and the two footer columns (text and address; reorder; restore defaults). The *Shop* mega menu comes from your categories.
- **Testimonials**, **Newsletter** (subscribers, CSV export), **Messages** (contact form), **Reviews** (approve before they appear).
- **General settings**: site name, logo (a transparent PNG is best), favicon, contact details, currency, tax, sender email, social links, SEO, size guide, footer text.

## Money and shipping settings

- **Payments**: switch methods on or off, write the cash-on-delivery message, enter your **bank account details** (bank transfer stays hidden until you do). Card payments stay switched off until a provider is connected; see [PAYMENTS.md](PAYMENTS.md). **Payment history** lists every payment event.
- **Shipping**: methods, charge, free-delivery threshold, delivery time, and city/province rules (a city rule beats a province rule, which beats the default). See [SHIPPING.md](SHIPPING.md). **Shipments** lists every parcel.

## Reports and exports

*Reports*: sales (day / month / year), products, customers, revenue and orders, each with CSV export. *Orders*: filter, search, date range, **Export CSV**, **Invoice** and **Print** on each order.

## Safety habits

- Sample content is marked **SAMPLE**; replace it with your real products, photos, policies and contact details before launch.
- Use a strong password; give each person their own account and the smallest role they need.
- Never share the `.env` file. Take a backup before updates (DEPLOYMENT.md, section 14).

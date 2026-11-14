-- ABIHA: everything the live database needs for the current code, in ONE script.
-- How to use (cPanel): phpMyAdmin -> click the website database on the left -> SQL tab -> paste ALL of this -> Go.
-- Safe to run more than once and on a database that already has some of it: it only creates what is missing, it never
-- deletes, overwrites or edits existing products, orders, customers, settings or pages.
--   1. Runtime tables      rate limiting and the email queue (so order emails can be queued and retried)
--   2. Traffic analytics   the page_views table behind Admin -> Analytics
--   3. Product video       the optional video column on products
--   4. Pages               Privacy policy and Terms & conditions (added only if missing; text you edited is never replaced)
-- After it runs: clear the website cache (cPanel File Manager -> storage/cache: delete the files inside, or save any page in the admin).

-- ============================================================
-- 1. RUNTIME TABLES
-- ============================================================
-- Runtime tables of the plain-PHP version (new, additive; the Laravel tables are untouched).
--   rate_limits   fixed-window counters for App\Core\RateLimiter (replaces Laravel's cache-based throttle)
--   email_outbox  queued emails, delivered by App\Mail\Mailer::flush() after commit and by `php bin/console.php cron`
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket` varchar(64) NOT NULL,
  `window_start` int(10) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`,`window_start`),
  KEY `rate_limits_window_start_index` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `template` varchar(80) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `text_body` mediumtext NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `send_after` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email_outbox_status_send_after_index` (`status`,`send_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. TRAFFIC ANALYTICS
-- ============================================================
-- Website traffic analytics (first-party, no cookies, no raw IP addresses): one row per counted page view.
-- "visitor" is a one-way hash of IP + browser + day + the site key, so the same person is only recognised within one day.
CREATE TABLE IF NOT EXISTS page_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    visitor CHAR(24) NOT NULL,
    path VARCHAR(255) NOT NULL,
    channel VARCHAR(12) NOT NULL DEFAULT 'Direct',
    source VARCHAR(120) NULL,
    device VARCHAR(10) NOT NULL DEFAULT 'desktop',
    browser VARCHAR(24) NOT NULL DEFAULT 'Other',
    os VARCHAR(24) NOT NULL DEFAULT 'Other',
    PRIMARY KEY (id),
    KEY page_views_created_at (created_at),
    KEY page_views_path_created (path(120), created_at),
    KEY page_views_visitor_created (visitor, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. PRODUCT VIDEO COLUMN
-- ============================================================
-- Optional product video: either a YouTube / Vimeo / direct .mp4 or .webm link, or the path of an uploaded video file
-- (storage/app/public/videos/...). Added only when the column is not there yet, so it is safe on any database.
SET @has_video := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'video');
SET @add_video := IF(@has_video = 0, 'ALTER TABLE products ADD COLUMN video VARCHAR(255) NULL', 'SELECT 1');
PREPARE add_video_stmt FROM @add_video;
EXECUTE add_video_stmt;
DEALLOCATE PREPARE add_video_stmt;

-- ============================================================
-- 4. PRIVACY POLICY AND TERMS PAGES
-- ============================================================
-- Privacy policy and Terms & conditions pages (Admin -> Pages can edit both afterwards).
-- Each page is added ONLY if no page with that address exists yet, so text the owner has already written is never replaced.
-- The wording describes what this website really does (see app code); it contains no delivery times, return periods or addresses
-- that the business has not published. Have the texts read by a lawyer before relying on them.

INSERT INTO `pages` (`title`, `slug`, `body`, `meta_title`, `meta_description`, `is_published`, `show_in_footer`, `is_sample`, `sort_order`, `created_at`, `updated_at`)
SELECT 'Privacy policy', 'privacy-policy',
'<p><em>Last updated: 14 November 2026</em></p>
<p>ABIHA Apparel (&quot;ABIHA&quot;, &quot;we&quot;, &quot;us&quot;) respects your privacy. This policy explains what personal information we collect when you visit abiha.pk or place an order, why we collect it, who we share it with, and the choices you have.</p>

<h2>1. Information we collect</h2>
<ul>
<li><strong>Orders:</strong> your name, phone number, email address, delivery address (province, city, street address, postal code) and any note you add to the order.</li>
<li><strong>Your account:</strong> your name, email address, phone number, saved addresses, wishlist and order history. Your password is stored only in scrambled (hashed) form, so nobody at ABIHA can read it.</li>
<li><strong>Messages and sign-ups:</strong> the details you type into our contact form, newsletter sign-up, order tracking form and product reviews.</li>
<li><strong>Payment information:</strong> see section 3. We never see or store your card number or CVV.</li>
<li><strong>Technical information:</strong> to keep the website working and secure we use cookies (see section 4) and our servers keep ordinary logs of errors and security events.</li>
</ul>

<h2>2. How we use your information</h2>
<ul>
<li>To process, deliver and confirm your orders and to send you order emails (confirmation, payment received, shipped, delivered, cancelled).</li>
<li>To manage your account, answer your messages and give support.</li>
<li>To verify payments and prevent fraud, misuse and abuse of the website.</li>
<li>To send our newsletter only if you signed up for it. You can ask to be removed at any time.</li>
<li>To understand, in a general way, how the website is used so we can improve it (see section 4).</li>
<li>To meet our legal, tax and accounting obligations.</li>
</ul>

<h2>3. Payments</h2>
<ul>
<li><strong>Cash on delivery:</strong> you pay the rider when the order arrives. No payment details are collected online.</li>
<li><strong>Bank transfer:</strong> if you choose it, you may send us a transfer reference and a photo or PDF of your receipt. Receipts are kept privately and can be opened only by authorised staff, who check them against our bank statement.</li>
<li><strong>Debit / credit card:</strong> card payments are made on the secure page of our payment provider, Safepay. You type your card details there, not on abiha.pk. We never see, receive or store your card number or CVV. We only receive the result of the payment and a payment reference so we can mark your order as paid.</li>
</ul>

<h2>4. Cookies and website statistics</h2>
<ul>
<li><strong>Essential cookies:</strong> a session cookie keeps your shopping bag, your sign-in and our security checks working. If you tick &quot;remember me&quot; when signing in, a second cookie keeps you signed in on that device until you sign out. The website cannot work properly without these.</li>
<li><strong>Visit statistics without tracking:</strong> we count visits with a method that uses no tracking cookies. We do not store your IP address; a visitor is recognised only by an anonymous code that changes every day. Staff visits and automated bots are not counted, and we respect the &quot;Do Not Track&quot; setting of your browser.</li>
<li>We do not use advertising cookies and we do not sell your information.</li>
</ul>

<h2>5. Who we share information with</h2>
<p>We share only what is needed, and only with:</p>
<ul>
<li><strong>Courier companies</strong> that deliver your order (they need your name, phone number and delivery address).</li>
<li><strong>Our payment provider</strong> (Safepay), if you pay by card.</li>
<li><strong>Our website hosting and email service providers</strong>, who process data on our behalf so the website and our emails work.</li>
<li><strong>Authorities</strong>, where the law requires it.</li>
</ul>
<p>We do not sell or rent your personal information to anyone.</p>

<h2>6. How long we keep information</h2>
<p>We keep order records for as long as we need them to deal with your order, returns and complaints and to meet our legal, tax and accounting duties. Account information is kept while your account is open. You may ask us to close your account (see section 8).</p>

<h2>7. Security</h2>
<p>The website is served over an encrypted (HTTPS) connection. Passwords are hashed, forms are protected against forged requests, payment receipts are stored privately, and access to the admin area is limited to authorised staff. No method of transmission or storage is completely secure, but we take reasonable care to protect your information.</p>

<h2>8. Your choices and rights</h2>
<p>You can ask us to see the personal information we hold about you, correct it, or delete it (for example by closing your account), and you can ask us to stop sending you the newsletter. We may need to keep some order records where the law requires it. To make a request, use our <a href="/contact">Contact page</a> from the email address you used on the website, so we can be sure it is you.</p>

<h2>9. Children</h2>
<p>Our website is meant for adults. We do not knowingly collect information from children under 18. If you believe a child has given us personal information, please contact us and we will remove it.</p>

<h2>10. Changes to this policy</h2>
<p>We may update this policy from time to time. The date at the top shows when it was last changed. Please check it now and then.</p>

<h2>11. Contact us</h2>
<p>For any question about this policy or your information, please use our <a href="/contact">Contact page</a>. Our <a href="/pages/terms-and-conditions">Terms &amp; conditions</a> and <a href="/shipping-returns">Shipping &amp; returns</a> pages explain the rest of how we work.</p>',
'Privacy policy | ABIHA Apparel',
'How ABIHA Apparel collects, uses and protects your personal information, including orders, cookies and card payments.',
1, 1, 0, 90, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `slug` = 'privacy-policy');

INSERT INTO `pages` (`title`, `slug`, `body`, `meta_title`, `meta_description`, `is_published`, `show_in_footer`, `is_sample`, `sort_order`, `created_at`, `updated_at`)
SELECT 'Terms & conditions', 'terms-and-conditions',
'<p><em>Last updated: 14 November 2026</em></p>
<p>Welcome to ABIHA Apparel. These terms apply when you use abiha.pk and when you buy from us. By using the website or placing an order you agree to them. If you do not agree, please do not use the website.</p>

<h2>1. About us</h2>
<p>abiha.pk is operated by ABIHA Apparel in Pakistan. You can reach us at any time through our <a href="/contact">Contact page</a>.</p>

<h2>2. Using the website and your account</h2>
<ul>
<li>You must be 18 or older, or use the website with a parent or guardian, to place an order.</li>
<li>Give true and complete details when you order or create an account, and keep your password private. You are responsible for what happens under your account.</li>
<li>Please do not misuse the website: no attempts to break into it, disturb it, copy it automatically, or use it for anything unlawful.</li>
</ul>

<h2>3. Products and prices</h2>
<ul>
<li>All prices are shown in Pakistani rupees (PKR) and are for the items shown. Delivery charges, if any, are shown at checkout before you pay.</li>
<li>We take care to describe and photograph our products accurately. Colours can look slightly different on different screens.</li>
<li>Prices and availability can change without notice. The price that applies to you is the one shown when you place your order.</li>
<li>If we find a pricing or stock error after you order, we may cancel the order and refund anything you have already paid.</li>
</ul>

<h2>4. Placing an order</h2>
<ul>
<li>When you place an order we send an email confirming that we received it. That email is not yet our acceptance of your order.</li>
<li>We accept your order when we confirm it or dispatch it. We may decline or cancel an order, for example if an item is out of stock, if we cannot deliver to your address, if the details look false, or if payment cannot be verified. If we cancel an order you have paid for, we refund it.</li>
</ul>

<h2>5. Payment</h2>
<ul>
<li>The payment methods available are shown at checkout and can change from time to time.</li>
<li><strong>Cash on delivery:</strong> you pay the full amount to the rider when the order arrives.</li>
<li><strong>Bank transfer:</strong> your order is processed after we have verified your payment.</li>
<li><strong>Debit / credit card:</strong> the payment is made on our payment provider secure page. Your order is marked as paid only after the provider confirms the payment to us.</li>
</ul>

<h2>6. Delivery</h2>
<p>We deliver through courier companies. Delivery information, charges and estimated times are explained on our <a href="/shipping-returns">Shipping &amp; returns</a> page. Delivery times are estimates and can be affected by things outside our control. Please give a complete and correct address and a phone number on which the courier can reach you.</p>

<h2>7. Returns, exchanges and refunds</h2>
<p>Our returns, exchange and refund rules are set out on our <a href="/shipping-returns">Shipping &amp; returns</a> page, and they form part of these terms. Nothing in these terms limits any right you have under the law.</p>

<h2>8. Our content</h2>
<p>The ABIHA name and logo, the photographs, the designs, the text and the layout of this website belong to ABIHA Apparel or are used with permission. You may view and print pages for your own personal use. You may not copy, sell or reuse them for commercial purposes without our written permission.</p>

<h2>9. Reviews and messages you send us</h2>
<p>If you post a review or send us a message, it must be honest, lawful and not offensive. You allow us to publish reviews you submit on the website. We may remove content that breaks these terms.</p>

<h2>10. Our responsibility</h2>
<p>We will take reasonable care in running the website and fulfilling your orders. To the extent the law allows, ABIHA Apparel is not responsible for losses that we could not reasonably have foreseen, for delays or failures caused by events outside our control, or for the website being temporarily unavailable. Nothing in these terms excludes or limits any liability that cannot be excluded or limited by law.</p>

<h2>11. Privacy</h2>
<p>How we handle your personal information is explained in our <a href="/pages/privacy-policy">Privacy policy</a>.</p>

<h2>12. Changes to these terms</h2>
<p>We may change these terms and the website from time to time. The terms that apply to an order are the ones shown on this page when you placed it. The date at the top shows when they were last updated.</p>

<h2>13. Governing law</h2>
<p>These terms and any dispute connected with them are governed by the laws of Pakistan, and the courts of Pakistan have jurisdiction. Before taking any formal step, please contact us first through our <a href="/contact">Contact page</a> so we can try to put things right.</p>

<h2>14. Contact us</h2>
<p>If you have any question about these terms, please use our <a href="/contact">Contact page</a>.</p>',
'Terms & conditions | ABIHA Apparel',
'The terms for using abiha.pk and buying from ABIHA Apparel: orders, prices, payment, delivery, returns and your rights.',
1, 1, 0, 91, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `slug` = 'terms-and-conditions');


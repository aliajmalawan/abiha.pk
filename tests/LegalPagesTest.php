<?php

declare(strict_types=1);

/* The Privacy policy and Terms & conditions pages (database/content/legal_pages.sql, bin/console.php pages:legal). */

use App\Controllers\Admin\ContentController;
use App\Core\Database;
use App\Support\HtmlSanitizer;

T::$current = 'Legal pages';
reset_db();
$db = Database::get();
$sql = (string) file_get_contents(BASE_PATH . '/database/content/legal_pages.sql');
$run = static function () use ($db, $sql): void {
    $raw = $db->raw();
    $raw->multi_query($sql);
    do {
        if ($r = $raw->store_result()) {
            $r->free();
        }
    } while ($raw->more_results() && $raw->next_result());
};

T::eq(0, (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')"), 'a fresh database has neither page');
$run();
T::eq(2, (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')"), 'both pages are added');
foreach (['privacy-policy', 'terms-and-conditions'] as $slug) {
    $p = $db->one('SELECT * FROM pages WHERE slug = ?', [$slug]);
    T::ok((int) $p['is_published'] === 1 && (int) $p['show_in_footer'] === 1 && (int) $p['is_sample'] === 0, "{$slug}: published, linked from the footer, not a sample");
    T::ok(strlen(strip_tags((string) $p['body'])) > 2500, "{$slug}: a full text, not a stub");
    T::ok(trim((string) $p['meta_title']) !== '' && trim((string) $p['meta_description']) !== '', "{$slug}: has a search title and description");
    // the text must survive the same cleaning the admin editor applies (only allowed tags), apart from the link attributes it adds
    $clean = preg_replace('/ rel="[^"]*"/', '', HtmlSanitizer::clean((string) $p['body']));
    $norm = static fn (string $h): string => preg_replace('/\s+/', ' ', html_entity_decode($h, ENT_QUOTES | ENT_HTML5));   // the editor writes &quot; as a plain quote: same on screen
    T::eq($norm((string) $p['body']), $norm((string) $clean), "{$slug}: uses only tags the editor allows");
}

$privacy = http('GET', '/pages/privacy-policy');
T::eq(200, $privacy->status, 'the privacy page opens');
foreach (['Information we collect', 'Cookies and website statistics', 'We never see or store your card number or CVV', 'Safepay', 'Your choices and rights'] as $needle) {
    T::contains($needle, $privacy->body, "privacy says: {$needle}");
}
$terms = http('GET', '/pages/terms-and-conditions');
T::eq(200, $terms->status, 'the terms page opens');
foreach (['Placing an order', 'Cash on delivery', 'Governing law', 'Shipping &amp; returns'] as $needle) {
    T::contains($needle, $terms->body, "terms say: {$needle}");
}
T::contains('/pages/privacy-policy', $terms->body, 'the terms link to the privacy policy');
T::contains('/shipping-returns', $terms->body, 'and to the shipping and returns page, which holds the return rules');
T::ok(!preg_match('/\b\d+\s*(days?|working days|business days)\b/i', strip_tags((string) $db->value("SELECT GROUP_CONCAT(body) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')"))), 'no delivery or return period is invented');
$home = http('GET', '/')->body;
T::ok(str_contains($home, '/pages/privacy-policy') && str_contains($home, '/pages/terms-and-conditions'), 'both are linked from the footer');
T::contains('/pages/privacy-policy', http('GET', '/pages/faq')->body, 'the FAQ now links to the privacy policy');

$ov = ContentController::overview();
$flat = [];
foreach ($ov['sections'] as $sec) {
    foreach ($sec['items'] as $i) {
        $flat[$i['label']] = $i;
    }
}
T::eq('ok', $flat['Privacy policy']['status'] ?? null, 'the Content overview marks the privacy policy as done');
T::eq('ok', $flat['Terms & conditions']['status'] ?? null, 'and the terms as done');

// running it again, or after the owner edited a page, never replaces anything
$db->exec("UPDATE pages SET body = '<p>MY OWN TEXT</p>' WHERE slug = 'privacy-policy'");
$run();
T::eq(2, (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')"), 'running again adds nothing');
T::eq('<p>MY OWN TEXT</p>', $db->value("SELECT body FROM pages WHERE slug = 'privacy-policy'"), 'and never overwrites a page the owner edited');
$db->exec("DELETE FROM pages WHERE slug = 'terms-and-conditions'");
$run();
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug = 'terms-and-conditions'"), 'a missing page is added back without touching the other');

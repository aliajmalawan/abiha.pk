<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\ContentPages;
use App\Support\CatalogCache;
use App\Support\Settings;

/** Admin → FAQ: the questions and answers of the FAQ page, edited as simple groups (setting faq.groups). */
final class FaqController extends AdminController
{
    private const MAX_GROUPS = 12;
    private const MAX_ITEMS = 30;

    /** Words an answer may contain that turn into live links or values on the page. */
    public const TOKENS = [
        '{payments}' => 'the payment methods that are switched on right now',
        '{shipping}' => 'a link to Shipping & returns',
        '{track}' => 'a link to Track your order',
        '{contact}' => 'a link to Contact us',
        '{login}' => 'a link to Sign in',
        '{register}' => 'a link to Create an account',
        '{forgot}' => 'a link to Forgot password',
        '{privacy}' => 'a link to the Privacy policy page',
        '{phone}' => 'the shop phone number (a sentence with it, left out when empty)',
        '{email}' => 'the shop email (a sentence with it, left out when empty)',
    ];

    public function edit(Request $request): Response
    {
        $saved = Settings::get('faq.groups');
        $custom = is_array($saved) && $saved !== [];
        $groups = [];
        foreach (ContentPages::faqSource() as $title => $items) {
            $groups[] = ['title' => (string) $title, 'items' => array_map(static fn ($i) => ['q' => (string) $i['q'], 'a' => (string) $i['a']], array_values($items))];
        }
        return $this->adminView('admin/faq', ['groups' => $groups, 'custom' => $custom, 'tokens' => self::TOKENS], 'FAQ');
    }

    public function update(Request $request): Response
    {
        $in = is_array($request->post['groups'] ?? null) ? $request->post['groups'] : [];
        $clean = [];
        foreach (array_slice(array_values($in), 0, self::MAX_GROUPS) as $g) {
            if (!is_array($g)) {
                continue;
            }
            $items = [];
            foreach (array_slice(array_values(is_array($g['items'] ?? null) ? $g['items'] : []), 0, self::MAX_ITEMS) as $i) {
                $q = $this->text($i['q'] ?? '', 200);
                $a = $this->text($i['a'] ?? '', 3000);
                if ($q !== '') {
                    $items[] = ['q' => $q, 'a' => $a];
                }
            }
            if ($items) {
                $clean[] = ['title' => $this->text($g['title'] ?? '', 60) ?: 'Questions', 'items' => $items];
            }
        }
        if ($clean === []) {
            return $this->redirect(route('admin.faq.edit'), error: 'Add at least one question with a title before saving. To go back to the built-in questions use "Use the built-in FAQ".');
        }
        Settings::put('faq.groups', $clean);
        CatalogCache::bump();
        ActivityLogger::log('faq.updated', null, null, ['groups' => count($clean), 'questions' => array_sum(array_map(static fn ($g) => count($g['items']), $clean))]);
        return $this->redirect(route('admin.faq.edit'), status: 'FAQ saved. It is live on the FAQ page now.');
    }

    /** Throws the custom FAQ away and shows the built-in questions again. */
    public function reset(Request $request): Response
    {
        Settings::forget('faq.groups');
        CatalogCache::bump();
        ActivityLogger::log('faq.reset');
        return $this->redirect(route('admin.faq.edit'), status: 'The built-in FAQ is showing again.');
    }

    /** Plain text only (no HTML), tidy spaces, limited length. */
    private function text(mixed $v, int $max): string
    {
        $t = is_scalar($v) ? trim(strip_tags((string) $v)) : '';
        $t = (string) preg_replace('/[ \t]+/', ' ', str_replace("\r\n", "\n", $t));
        return mb_substr($t, 0, $max);
    }
}

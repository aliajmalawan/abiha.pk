<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Analytics;
use App\Support\Settings;

/** Admin → Analytics: website traffic (visitors, page views, pages, sources, devices) and the tracking on/off switch. */
final class AnalyticsController extends AdminController
{
    public const RANGES = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, [
            'range' => ['nullable', 'in:' . implode(',', array_keys(self::RANGES)) . ',custom'],
            'from' => ['nullable', 'bail', 'string', 'date'],
            'to' => ['nullable', 'bail', 'string', 'date', 'after_or_equal:from'],
            'export' => ['nullable', 'in:csv'],
        ]);
        $range = (string) ($f['range'] ?? '30');
        $today = date('Y-m-d');
        if ($range === 'custom' && !empty($f['from'])) {
            $from = date('Y-m-d', (int) strtotime((string) $f['from']));
            $to = !empty($f['to']) ? date('Y-m-d', (int) strtotime((string) $f['to'])) : $today;
            if (strtotime($to) - strtotime($from) > 366 * 86400) {
                $from = date('Y-m-d', strtotime($to . ' -365 days'));
            }
        } else {
            $range = isset(self::RANGES[$range]) ? $range : '30';
            $to = $today;
            $from = $range === 'today' ? $today : date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));
        }
        $report = Analytics::report($from, $to);

        if (($f['export'] ?? null) === 'csv') {
            return $this->csv($report);
        }
        return $this->adminView('admin/analytics', ['report' => $report, 'range' => $range, 'ranges' => self::RANGES], 'Analytics');
    }

    /** Switches tracking on or off (the data already collected is kept). */
    public function toggle(Request $request): Response
    {
        $on = $request->bool('enabled');
        Settings::put('analytics.enabled', $on);
        ActivityLogger::log('settings.analytics', null, null, ['enabled' => $on]);
        return $this->redirect(route('admin.analytics.index'), status: $on ? 'Visitor tracking is on.' : 'Visitor tracking is off. Nothing new is recorded; the data collected so far is kept.');
    }

    /** One CSV with the day-by-day numbers (cells are neutralised against spreadsheet formulas). */
    private function csv(array $r): Response
    {
        $safe = static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        $lines = [['Date', 'Visitors', 'Page views']];
        foreach ($r['daily'] as $d) {
            $lines[] = [$d['date'], $d['visitors'], $d['views']];
        }
        $lines[] = [];
        $lines[] = ['Top pages', 'Page views', 'Visitors'];
        foreach ($r['pages'] as $p) {
            $lines[] = [$safe((string) $p['label']), $p['views'], $p['visitors']];
        }
        $h = fopen('php://temp', 'r+');
        foreach ($lines as $l) {
            fputcsv($h, $l, ',', '"', '\\');
        }
        rewind($h);
        $body = "\xEF\xBB\xBF" . stream_get_contents($h);
        fclose($h);
        return Response::download($body, 'abiha-traffic-' . $r['from'] . '-to-' . $r['to'] . '.csv');
    }
}

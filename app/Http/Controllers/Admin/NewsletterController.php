<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim(is_string($request->query("q")) ? $request->query("q") : "");

        return view('admin.newsletter.index', [
            'subscribers' => Newsletter::query()->when($q !== '', fn ($w) => $w->where('email', 'like', '%'.addcslashes($q, '%_\\').'%'))->latest('id')->paginate(30)->withQueryString(),
            'activeCount' => Newsletter::query()->whereNull('unsubscribed_at')->count(),
            'q' => $q,
        ]);
    }

    /** CSV of active subscribers. Spreadsheet-formula characters are neutralised (CSV injection). */
    public function export(): StreamedResponse
    {
        ActivityLogger::log('newsletter.exported');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'subscribed_at']);
            Newsletter::query()->whereNull('unsubscribed_at')->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [preg_match('/^[=+\-@\t\r]/', $r->email) ? "'".$r->email : $r->email, $r->subscribed_at?->toDateTimeString()]);
                }
            });
            fclose($out);
        }, 'newsletter-subscribers-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function destroy(Newsletter $newsletter): RedirectResponse
    {
        $newsletter->delete();

        return back()->with('status', 'Subscriber removed.');
    }
}

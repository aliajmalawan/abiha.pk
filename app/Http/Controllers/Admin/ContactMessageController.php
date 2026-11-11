<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['status' => ['nullable', 'in:new,read,replied']]);

        return view('admin.messages.index', [
            'messages' => ContactMessage::query()->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString(),
            'status' => $f['status'] ?? null,
        ]);
    }

    public function show(ContactMessage $message): View
    {
        if ($message->status === 'new') {
            $message->update(['status' => 'read']);
        }

        return view('admin.messages.show', ['message' => $message]);
    }

    public function update(Request $request, ContactMessage $message): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:new,read,replied']]);
        $message->update($data);

        return back()->with('status', 'Message updated.');
    }

    public function destroy(ContactMessage $message): RedirectResponse
    {
        ActivityLogger::log('message.deleted', $message, ['from' => $message->email]);
        $message->delete();

        return redirect()->route('admin.messages.index')->with('status', 'Message deleted.');
    }
}

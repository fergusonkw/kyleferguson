<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EmailStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Every email the application has sent, and what SMTP2Go said became of it.
 */
final class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny-email-log');

        $query = EmailMessage::query()
            ->with(['related', 'sentBy'])
            ->orderByDesc('id');

        if (($status = EmailStatus::tryFrom((string) $request->input('status', ''))) !== null) {
            $query->where('status', $status->value);
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->trim()->value().'%';

            $query->where(fn ($q) => $q
                ->whereLike('to_address', $search)
                ->orWhereLike('subject', $search));
        }

        return view('admin-v2.email-log.index', [
            'messages' => $query->paginate(50)->withQueryString(),
            'statuses' => EmailStatus::options(),
        ]);
    }

    public function show(EmailMessage $emailMessage): View
    {
        Gate::authorize('viewAny-email-log');

        return view('admin-v2.email-log.show', [
            'message' => $emailMessage->load(['related', 'sentBy', 'events']),
        ]);
    }
}

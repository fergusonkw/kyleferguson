<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactConfirmation;
use App\Mail\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class ContactController extends Controller
{
    public function submit(ContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        $honeypot = (string) ($data['_hp'] ?? '');
        $loadedAt = (int) ($data['_t'] ?? 0);

        // Honeypot — bots fill it, humans never see it. Return a convincing
        // success to prevent enumeration.
        if ($honeypot !== '') {
            return response()->json(['ok' => true, 'ticket' => $this->ticket()]);
        }

        // Time trap — legitimate users take at least a few seconds to fill the form.
        if ($loadedAt === 0 || (time() - $loadedAt) < 3) {
            return response()->json(['ok' => true, 'ticket' => $this->ticket()]);
        }

        $ticket = $this->ticket();
        $date = date('D, j M Y \a\t g:ia T');

        $payload = [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'company' => (string) ($data['company'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'message' => (string) $data['message'],
            'copyToSelf' => (bool) ($data['copyToSelf'] ?? false),
        ];

        $toEmail = (string) config('mail.contact_to.address');
        $toName = (string) config('mail.contact_to.name');

        try {
            Mail::to($toEmail, $toName)
                ->send(new ContactInquiry($ticket, $date, $payload));

            if ($payload['copyToSelf']) {
                Mail::to($payload['email'], $payload['name'])
                    ->send(new ContactConfirmation($ticket, $date, $payload));
            }

            return response()->json(['ok' => true, 'ticket' => $ticket]);
        } catch (Throwable $e) {
            Log::error('contact form failed', ['error' => $e->getMessage(), 'ticket' => $ticket]);

            return response()->json([
                'ok' => false,
                'error' => 'Mail could not be sent. Please try again or email hello@kyleferguson.ca directly.',
            ], 500);
        }
    }

    private function ticket(): string
    {
        return 'KF-'.mb_strtoupper(mb_substr(Str::random(8), 0, 6));
    }
}

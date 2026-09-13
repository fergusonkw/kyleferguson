<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\Smtp2GoWebhookRequest;
use App\Services\Mail\Smtp2GoWebhookProcessor;
use Illuminate\Http\JsonResponse;

/**
 * Receives SMTP2Go's delivery reports. JSON or form-encoded, whichever the
 * webhook is set to send.
 */
final class Smtp2GoWebhookController extends Controller
{
    public function __construct(private readonly Smtp2GoWebhookProcessor $processor) {}

    public function __invoke(Smtp2GoWebhookRequest $request): JsonResponse
    {
        return response()->json($this->processor->handle($request->post()));
    }
}

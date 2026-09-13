<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving, or previewing, the message the invoice email carries.
 */
final class InvoiceClientMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('writeClientMessage', $this->route('invoice'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'client_message' => ['nullable', 'string', 'max:'.Invoice::CLIENT_MESSAGE_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_message.max' => 'Keep the message under '.number_format(Invoice::CLIENT_MESSAGE_MAX_LENGTH).' characters.',
        ];
    }

    public function clientMessage(): ?string
    {
        $message = $this->validated('client_message');

        return is_string($message) ? $message : null;
    }
}

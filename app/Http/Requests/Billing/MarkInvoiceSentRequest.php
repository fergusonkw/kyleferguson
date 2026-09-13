<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The first send. Carries whatever is in the message box at the moment the
 * button is pressed, so text typed and not yet saved still goes out.
 */
final class MarkInvoiceSentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('send', $this->route('invoice'));
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

    /**
     * Whether the request carried the message box at all. A send from
     * somewhere without it leaves the saved message as it is.
     */
    public function carriesClientMessage(): bool
    {
        return $this->has('client_message');
    }

    public function clientMessage(): ?string
    {
        $message = $this->validated('client_message');

        return is_string($message) ? $message : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\EmailStatus;
use App\Mail\Billing\ClientInvoiceMail;
use App\Mail\ContactConfirmation;
use App\Mail\Transport\TrackingSmtp2GoTransport;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceDocument;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Motomedialab\Smtp2Go\Exceptions\Smtp2GoException;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * The outbound half of the email log: every message the application sends is
 * written down as it goes — what it said, what it was about, and the id
 * SMTP2Go gave it so the delivery webhooks can find it again.
 */
final class EmailDeliveryLogTest extends TestCase
{
    use RefreshDatabase;

    private const SMTP2GO_ENDPOINT = 'https://api.smtp2go.com/v3/email/send';

    private const API_KEY = 'api-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Pdf::fake();

        config(['mail.mailers.smtp2go.api_key' => self::API_KEY]);
    }

    public function test_an_email_is_recorded_with_what_it_said_and_who_sent_it(): void
    {
        $this->actingAs($user = User::factory()->create());

        Mail::to('visitor@example.test', 'Visitor')->send($this->contactConfirmation());

        $message = EmailMessage::query()->sole();

        $this->assertSame('array', $message->mailer);
        $this->assertSame(ContactConfirmation::class, $message->mailable_class);
        $this->assertSame('Visitor <visitor@example.test>', $message->to_address);
        $this->assertSame('Your message to Kyle Ferguson — KF-1234', $message->subject);
        $this->assertSame(EmailStatus::Sent, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame($user->id, $message->sent_by_user_id);
        $this->assertStringContainsString('KF-1234', (string) $message->html_body);
        $this->assertStringContainsString('KF-1234', (string) $message->text_body);
        $this->assertFalse($message->content_withheld);
    }

    public function test_only_smtp2go_ids_are_kept(): void
    {
        // The array mailer invents a Message-ID of its own, which no webhook
        // will ever quote back.
        Mail::to('visitor@example.test')->send($this->contactConfirmation());

        $this->assertNull(EmailMessage::query()->sole()->provider_message_id);
    }

    public function test_a_password_reset_link_is_never_kept(): void
    {
        $user = User::factory()->create(['email' => 'kyle@example.test']);

        $user->notify(new ResetPassword('secret-reset-token'));

        $message = EmailMessage::query()->sole();

        $this->assertSame(ResetPassword::class, $message->mailable_class);
        $this->assertTrue($message->content_withheld);
        $this->assertNull($message->html_body);
        $this->assertNull($message->text_body);
        $this->assertStringNotContainsString('secret-reset-token', (string) json_encode($message->getAttributes()));
    }

    public function test_the_client_invoice_email_is_filed_under_its_invoice(): void
    {
        $invoice = $this->sentInvoice();
        $document = InvoiceDocument::factory()->for($invoice)->create();

        Mail::to('ap@client.test')->send(new ClientInvoiceMail($invoice));

        $message = EmailMessage::query()->sole();

        $this->assertTrue($message->related->is($invoice));
        $this->assertSame(['invoice_document_id' => (string) $document->id], $message->metadata);
        $this->assertTrue($invoice->emailMessages()->sole()->is($message));
    }

    public function test_an_attachment_is_described_not_stored(): void
    {
        $invoice = $this->sentInvoice();

        Mail::to('ap@client.test')->send(new ClientInvoiceMail($invoice));

        $attachment = EmailMessage::query()->sole()->attachments[0];

        $this->assertSame($invoice->invoice_number.'.pdf', $attachment['filename']);
        $this->assertSame('application/pdf', $attachment['content_type']);
        $this->assertGreaterThan(0, $attachment['size']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $attachment['sha256']);
    }

    public function test_resending_from_the_invoice_page_is_recorded_against_the_invoice(): void
    {
        $admin = $this->createAdmin();
        $invoice = $this->sentInvoice();

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.resend', $invoice), ['recipient' => 'accounts@client.test'])
            ->assertOk();

        $message = $invoice->emailMessages()->sole();

        $this->assertSame('accounts@client.test', $message->to_address);
        $this->assertSame($admin->id, $message->sent_by_user_id);
        $this->assertSame(EmailStatus::Sent, $message->status);
    }

    public function test_keeping_the_record_never_costs_the_send(): void
    {
        // A deploy that runs ahead of its migration must not stop invoices.
        Exceptions::fake();
        EmailMessage::creating(fn (): never => throw new RuntimeException('The log is unavailable.'));

        Mail::to('visitor@example.test')->send($this->contactConfirmation());

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---- SMTP2Go -------------------------------------------------------------

    public function test_the_smtp2go_mailer_uses_the_tracking_transport(): void
    {
        $this->assertInstanceOf(TrackingSmtp2GoTransport::class, Mail::mailer('smtp2go')->getSymfonyTransport());
    }

    public function test_smtp2gos_id_is_kept_so_its_webhooks_can_be_matched(): void
    {
        $this->acceptSends('1er8bV-6Tw0Mi-7h');

        Mail::mailer('smtp2go')->to('visitor@example.test')->send($this->contactConfirmation());

        $message = EmailMessage::query()->sole();

        $this->assertSame('smtp2go', $message->mailer);
        $this->assertSame('1er8bV-6Tw0Mi-7h', $message->provider_message_id);
        $this->assertSame(EmailStatus::Sent, $message->status);
    }

    public function test_the_api_key_travels_in_a_header_and_internal_headers_stay_home(): void
    {
        $this->acceptSends();

        Mail::mailer('smtp2go')->to('ap@client.test')->send(new ClientInvoiceMail($this->sentInvoice()));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Smtp2go-Api-Key', self::API_KEY)
            && ! array_key_exists('api_key', $request->data())
            && ! str_contains($request->body(), 'X-Email-Log-Id')
            && ! str_contains($request->body(), 'X-Metadata'));
    }

    public function test_attachments_are_sent_with_their_whole_mime_type(): void
    {
        $this->acceptSends();

        Mail::mailer('smtp2go')->to('ap@client.test')->send(new ClientInvoiceMail($this->sentInvoice()));

        Http::assertSent(fn (Request $request): bool => $request->data()['attachments'][0]['mimetype'] === 'application/pdf');
    }

    public function test_a_refused_send_is_recorded_as_failed_with_smtp2gos_reason(): void
    {
        Http::fake([self::SMTP2GO_ENDPOINT => Http::response([
            'data' => ['error' => 'Sender address not verified', 'error_code' => 'E_ApiResponseCodes.NON_VALIDATED_SENDER'],
        ], 400)]);

        try {
            Mail::mailer('smtp2go')->to('ap@client.test')->send(new ClientInvoiceMail($this->sentInvoice()));
            $this->fail('A refused send should throw.');
        } catch (Smtp2GoException $e) {
            $this->assertSame('SMTP2Go refused the message: Sender address not verified', $e->getMessage());
        }

        $message = EmailMessage::query()->sole();

        $this->assertSame(EmailStatus::Failed, $message->status);
        $this->assertStringContainsString('Sender address not verified', (string) $message->error);
        $this->assertNull($message->sent_at);
    }

    public function test_a_failures_context_describes_the_message_without_carrying_it(): void
    {
        // Laravel writes exception context into the log and on to Sentry.
        Http::fake([self::SMTP2GO_ENDPOINT => Http::response(['data' => ['succeeded' => 0, 'failed' => 1, 'failures' => ['ap@client.test']]], 200)]);

        try {
            Mail::mailer('smtp2go')->to('ap@client.test')->send(new ClientInvoiceMail($this->sentInvoice()));
            $this->fail('A refused send should throw.');
        } catch (Smtp2GoException $e) {
            $context = (string) json_encode($e->context());

            $this->assertStringNotContainsString(self::API_KEY, $context);
            $this->assertStringNotContainsString('fileblob', $context);
            $this->assertStringNotContainsString('html_body', $context);
            $this->assertSame(1, $e->context()['message']['recipients']['to']);
        }
    }

    public function test_an_unreachable_api_is_recorded_as_failed(): void
    {
        Http::fake([self::SMTP2GO_ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out')]);

        try {
            Mail::mailer('smtp2go')->to('visitor@example.test')->send($this->contactConfirmation());
            $this->fail('An unreachable API should throw.');
        } catch (ConnectionException) {
            // Carries on to the caller, which tells the operator.
        }

        $message = EmailMessage::query()->sole();

        $this->assertSame(EmailStatus::Failed, $message->status);
        $this->assertStringContainsString('timed out', (string) $message->error);
    }

    private function acceptSends(string $emailId = '1er8bV-6Tw0Mi-7h'): void
    {
        Http::fake([self::SMTP2GO_ENDPOINT => Http::response([
            'request_id' => 'aa253464-0bd0-467a-b24b-6159dcd7be60',
            'data' => ['succeeded' => 1, 'failed' => 0, 'failures' => [], 'email_id' => $emailId],
        ])]);
    }

    private function contactConfirmation(): ContactConfirmation
    {
        return new ContactConfirmation('KF-1234', 'September 12, 2026', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'company' => 'Acme',
            'type' => 'General',
            'message' => 'Hello there.',
            'copyToSelf' => true,
        ]);
    }

    private function sentInvoice(): Invoice
    {
        $business = Business::factory()->create(['supported_currencies' => ['CAD'], 'payment_terms_days' => 30]);
        $client = Client::factory()->for($business)->create(['billing_currency' => 'CAD']);

        return Invoice::factory()->for($business)->for($client)
            ->status(InvoiceStatus::Sent)->withTotal(120.00)
            ->create([
                'issued_on' => now()->subDays(21)->toDateString(),
                'due_on' => now()->addDays(9)->toDateString(),
                'sent_at' => now()->subDays(21),
            ])
            ->fresh();
    }
}

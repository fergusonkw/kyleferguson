<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\EmailStatus;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SMTP2Go's delivery reports: authenticated by a shared secret in the
 * Authorization header, matched to the email by SMTP2Go's `email_id`, and
 * moving each email's status forward only.
 *
 * Payloads follow SMTP2Go's documented webhook fields.
 */
final class Smtp2GoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-test-0123456789';

    private const EMAIL_ID = '1er8bV-6Tw0Mi-7h';

    private const API_KEY = 'api-0123456789abcdef0123456789abcdef';

    private EmailMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.smtp2go.webhook_secret' => self::SECRET]);

        $this->message = EmailMessage::factory()->create([
            'provider_message_id' => self::EMAIL_ID,
            'to_address' => 'ap@client.test',
        ]);
    }

    // ---- Who may report --------------------------------------------------------

    public function test_a_report_without_the_secret_is_refused(): void
    {
        $this->postJson(route('webhooks.smtp2go'), $this->payload('delivered'))->assertUnauthorized();
        $this->report($this->payload('delivered'), 'Bearer not-the-secret')->assertUnauthorized();

        $this->assertDatabaseCount('email_events', 0);
        $this->assertSame(EmailStatus::Sent, $this->message->fresh()->status);
    }

    public function test_an_unconfigured_secret_refuses_everything(): void
    {
        config(['services.smtp2go.webhook_secret' => null]);

        $this->report($this->payload('delivered'))->assertUnauthorized();
        $this->report($this->payload('delivered'), 'Bearer ')->assertUnauthorized();

        $this->assertDatabaseCount('email_events', 0);
    }

    public function test_the_secret_can_arrive_as_the_basic_auth_password(): void
    {
        // SMTP2Go's older setup form takes credentials in the URL, which
        // arrives as basic auth.
        $this->report($this->payload('delivered'), 'Basic '.base64_encode('smtp2go:'.self::SECRET))->assertOk();

        $this->assertSame(EmailStatus::Delivered, $this->message->fresh()->status);
    }

    public function test_the_secret_is_not_accepted_in_the_url(): void
    {
        $this->postJson(route('webhooks.smtp2go', ['secret' => self::SECRET]), $this->payload('delivered'))
            ->assertUnauthorized();
    }

    // ---- What a report does ----------------------------------------------------

    public function test_a_delivery_report_marks_the_email_delivered(): void
    {
        $this->report($this->payload('delivered'))
            ->assertOk()
            ->assertExactJson(['received' => 1, 'recorded' => 1]);

        $message = $this->message->fresh();
        $event = EmailEvent::query()->sole();

        $this->assertSame(EmailStatus::Delivered, $message->status);
        $this->assertSame('2026-09-12 10:15:00', $message->last_event_at->format('Y-m-d H:i:s'));
        $this->assertSame('delivered', $event->event);
        $this->assertSame('ap@client.test', $event->recipient);
        $this->assertTrue($event->emailMessage->is($message));
    }

    public function test_a_soft_bounce_is_a_delay_that_a_later_delivery_clears(): void
    {
        // SMTP2Go keeps retrying a soft bounce, and usually gets through.
        $this->report($this->payload('bounce', ['bounce' => 'soft', 'message' => '452 4.2.2 Mailbox full']))->assertOk();

        $this->assertSame(EmailStatus::SoftBounced, $this->message->fresh()->status);
        $this->assertSame('452 4.2.2 Mailbox full', $this->message->fresh()->error);

        $this->report($this->payload('delivered', ['time' => '2026-09-12T11:00:00Z']))->assertOk();

        $this->assertSame(EmailStatus::Delivered, $this->message->fresh()->status);
        $this->assertNull($this->message->fresh()->error);
    }

    public function test_a_hard_bounce_is_not_undone_by_a_late_delivery_report(): void
    {
        $this->report($this->payload('bounce', [
            'bounce' => 'hard',
            'message' => '550 5.1.1 The email account that you tried to reach does not exist',
            'host' => 'mx.client.test',
        ]))->assertOk();

        $this->report($this->payload('delivered', ['time' => '2026-09-12T09:00:00Z']))->assertOk();

        $message = $this->message->fresh();

        $this->assertSame(EmailStatus::HardBounced, $message->status);
        $this->assertSame('550 5.1.1 The email account that you tried to reach does not exist (mx.client.test)', $message->error);
        $this->assertSame(2, $message->events()->count());
        $this->assertSame('2026-09-12 10:15:00', $message->last_event_at->format('Y-m-d H:i:s'));
    }

    public function test_an_unqualified_bounce_is_treated_as_permanent(): void
    {
        $this->report($this->payload('bounce'))->assertOk();

        $this->assertSame(EmailStatus::HardBounced, $this->message->fresh()->status);
    }

    public function test_a_spam_complaint_outranks_delivery(): void
    {
        $this->message->update(['status' => EmailStatus::Delivered]);

        $this->report($this->payload('spam'))->assertOk();

        $this->assertSame(EmailStatus::Spam, $this->message->fresh()->status);
    }

    public function test_an_open_is_recorded_without_changing_the_status(): void
    {
        $this->message->update(['status' => EmailStatus::Delivered]);

        $this->report($this->payload('open', ['client' => 'Gmail', 'client-os' => 'Android']))->assertOk();

        $message = $this->message->fresh();

        $this->assertSame(EmailStatus::Delivered, $message->status);
        $this->assertSame('2026-09-12 10:15:00', $message->firstOpenedAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('Gmail on Android', EmailEvent::query()->sole()->summary());
    }

    // ---- Keeping the record straight -------------------------------------------

    public function test_reports_about_another_applications_email_are_ignored(): void
    {
        // An SMTP2Go account shared with Tracker Pull can send its reports here.
        $this->report($this->payload('bounce', ['email_id' => 'tracker-pull-email']))
            ->assertOk()
            ->assertExactJson(['received' => 1, 'recorded' => 0]);

        $this->assertDatabaseCount('email_events', 0);
    }

    public function test_a_report_it_cannot_use_is_acknowledged_rather_than_refused(): void
    {
        // A refusal would have SMTP2Go retry it for two days.
        $this->report(['event' => 'delivered'])->assertOk()->assertExactJson(['received' => 1, 'recorded' => 0]);
        $this->report(['email_id' => self::EMAIL_ID, 'event' => ['not', 'a', 'name']])->assertOk();

        $this->assertDatabaseCount('email_events', 0);
    }

    public function test_a_retried_report_is_recorded_once(): void
    {
        $this->report($this->payload('bounce', ['bounce' => 'soft']))->assertOk();
        $this->report($this->payload('bounce', ['bounce' => 'soft']))->assertOk();

        $this->assertDatabaseCount('email_events', 1);
    }

    public function test_the_api_key_in_auth_is_masked_before_it_is_stored(): void
    {
        $this->report($this->payload('delivered'))->assertOk();

        $payload = EmailEvent::query()->sole()->payload;

        $this->assertSame('api-****************************cdef', $payload['auth']);
        $this->assertStringNotContainsString(self::API_KEY, (string) json_encode($payload));
    }

    public function test_a_batch_of_reports_is_processed_whole(): void
    {
        $this->report([
            $this->payload('processed', ['time' => '2026-09-12T10:14:00Z']),
            $this->payload('delivered'),
        ])->assertOk()->assertExactJson(['received' => 2, 'recorded' => 2]);

        $this->assertSame(EmailStatus::Delivered, $this->message->fresh()->status);
    }

    public function test_form_encoded_reports_are_accepted(): void
    {
        $this->post(route('webhooks.smtp2go'), $this->payload('delivered'), ['Authorization' => 'Bearer '.self::SECRET])
            ->assertOk();

        $this->assertSame(EmailStatus::Delivered, $this->message->fresh()->status);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function report(array $body, string $authorization = 'Bearer '.self::SECRET): TestResponse
    {
        return $this->postJson(route('webhooks.smtp2go'), $body, ['Authorization' => $authorization]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $event, array $overrides = []): array
    {
        return [
            'event' => $event,
            'email_id' => self::EMAIL_ID,
            'rcpt' => 'ap@client.test',
            'sender' => 'hello@kyleferguson.ca',
            'from_address' => 'hello@kyleferguson.ca',
            'subject' => 'Invoice KF-0001 from Kyle Ferguson — August 2026',
            'auth' => self::API_KEY,
            'time' => '2026-09-12T10:15:00Z',
            'sendtime' => '2026-09-12T10:14:58Z',
            ...$overrides,
        ];
    }
}

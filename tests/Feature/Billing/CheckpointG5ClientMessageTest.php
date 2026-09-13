<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Permission;
use App\Mail\Billing\ClientInvoiceMail;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\EmailMessage;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * A message from the operator, carried in the invoice email: a write-up of
 * the month, or anything the client should know alongside the figures.
 *
 * It is written until the first send. After that it is the record of what the
 * client received, and changes only with a resend.
 */
final class CheckpointG5ClientMessageTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private int $periodsUsed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Pdf::fake();

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson',
            'contact_email' => 'hello@kyleferguson.ca',
            'supported_currencies' => ['CAD'],
            'payment_terms_days' => 30,
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'contact_name' => 'Dana Reid',
            'contact_email' => 'ap@acme.test',
            'billing_currency' => 'CAD',
        ]);
    }

    // ---- Writing it ------------------------------------------------------------

    public function test_a_message_is_saved_on_a_draft_and_on_an_approved_invoice(): void
    {
        foreach ([InvoiceStatus::Draft, InvoiceStatus::Approved] as $status) {
            $invoice = $this->invoice($status);

            $this->saveMessage($invoice, 'August was the server move.')
                ->assertOk()
                ->assertJsonPath('message', 'Message saved. It goes out with the invoice email.');

            $this->assertSame('August was the server move.', $invoice->fresh()->client_message);
        }
    }

    public function test_line_endings_are_evened_out_and_a_blank_box_is_no_message(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved);

        $this->saveMessage($invoice, "  First line\r\nSecond line  \r\n")->assertOk();
        $this->assertSame("First line\nSecond line", $invoice->fresh()->client_message);

        $this->saveMessage($invoice, "   \n ")
            ->assertOk()
            ->assertJsonPath('message', 'Message removed. The email will use the standard wording.');
        $this->assertNull($invoice->fresh()->client_message);
    }

    public function test_the_message_is_fixed_once_the_client_has_it(): void
    {
        // Even for a super admin, who passes every policy: this is the
        // invoice's state talking, not a permission.
        $invoice = $this->invoice(InvoiceStatus::Sent, ['client_message' => 'As sent.']);

        $this->actingAs($this->createSuperAdmin())
            ->patchJson(route('admin.billing.invoices.client-message', $invoice), ['client_message' => 'Rewritten.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This invoice has been emailed, so its message records what the client received. Resend it to send a different one.');

        $this->assertSame('As sent.', $invoice->fresh()->client_message);
    }

    public function test_a_voided_invoice_takes_no_message(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Void, ['sent_at' => null]);

        $this->saveMessage($invoice, 'Anything.')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A voided invoice is not emailed, so it takes no message.');
    }

    public function test_an_overlong_message_is_refused(): void
    {
        $this->saveMessage($this->invoice(InvoiceStatus::Approved), str_repeat('a', Invoice::CLIENT_MESSAGE_MAX_LENGTH + 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_message' => 'Keep the message under 5,000 characters.']);
    }

    public function test_writing_it_takes_draft_or_issuing_permission(): void
    {
        $viewer = $this->userWith([Permission::ViewBilling]);
        $preparer = $this->userWith([Permission::ViewBilling, Permission::ManageInvoices]);
        $draft = $this->invoice(InvoiceStatus::Draft);
        $approved = $this->invoice(InvoiceStatus::Approved);

        $this->actingAs($viewer)
            ->patchJson(route('admin.billing.invoices.client-message', $draft), ['client_message' => 'x'])
            ->assertForbidden();

        // Whoever prepares a draft can write its message; once approved, it
        // belongs to whoever sends it.
        $this->actingAs($preparer)
            ->patchJson(route('admin.billing.invoices.client-message', $draft), ['client_message' => 'x'])
            ->assertOk();
        $this->actingAs($preparer)
            ->patchJson(route('admin.billing.invoices.client-message', $approved), ['client_message' => 'x'])
            ->assertForbidden();
    }

    // ---- The email -------------------------------------------------------------

    public function test_the_email_carries_the_message_as_paragraphs(): void
    {
        $this->emailInvoice("First paragraph.\nSame paragraph.\n\nSecond paragraph.");

        $html = (string) EmailMessage::query()->sole()->html_body;

        $this->assertStringContainsString("First paragraph.<br />\nSame paragraph.</p>", $html);
        $this->assertMatchesRegularExpression('/<p [^>]*>Second paragraph\.<\/p>/', $html);
    }

    public function test_nothing_typed_can_become_markup(): void
    {
        $this->emailInvoice('<script>alert(1)</script> Smith & Sons');

        $html = (string) EmailMessage::query()->sole()->html_body;

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Smith &amp; Sons', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_the_plain_text_part_carries_the_message_as_written(): void
    {
        $this->emailInvoice("It's been a busy month & we moved servers.");

        $this->assertStringContainsString(
            "The PDF is attached.\n\nIt's been a busy month & we moved servers.\n\nBilled to:",
            (string) EmailMessage::query()->sole()->text_body,
        );
    }

    public function test_without_a_message_the_email_reads_as_it_always_has(): void
    {
        $this->emailInvoice(null);

        $message = EmailMessage::query()->sole();

        $this->assertStringContainsString("The PDF is attached.\n\nBilled to:", (string) $message->text_body);
        $this->assertStringNotContainsString('#c9cad0', (string) $message->html_body);
    }

    public function test_the_tracker_pull_email_carries_it_too(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved, [
            'client_message' => "It's been a busy month.",
            'email_template_view_snapshot' => 'emails.invoices.tracker-pull',
        ]);

        Mail::to('ap@acme.test')->send(new ClientInvoiceMail($invoice));

        $message = EmailMessage::query()->sole();

        $this->assertStringContainsString('It&#039;s been a busy month.</p>', (string) $message->html_body);
        $this->assertStringContainsString("It's been a busy month.", (string) $message->text_body);
    }

    // ---- Sending ---------------------------------------------------------------

    public function test_mark_sent_sends_what_is_in_the_box_saved_or_not(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved, ['client_message' => 'The saved wording.']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice), ['client_message' => 'Typed, never saved.'])
            ->assertOk();

        $this->assertSame('Typed, never saved.', $invoice->fresh()->client_message);
        $this->assertStringContainsString('Typed, never saved.', (string) EmailMessage::query()->sole()->html_body);
    }

    public function test_mark_sent_from_elsewhere_keeps_the_saved_message(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved, ['client_message' => 'The saved wording.']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertOk();

        $this->assertStringContainsString('The saved wording.', (string) EmailMessage::query()->sole()->html_body);
    }

    public function test_a_resend_can_change_the_message(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, ['client_message' => 'As first sent.']);

        $this->resend($invoice, ['client_message' => 'Resending — the first went to the wrong address.'])->assertOk();

        $this->assertSame('Resending — the first went to the wrong address.', $invoice->fresh()->client_message);
        $this->assertStringContainsString('the first went to the wrong address', (string) EmailMessage::query()->sole()->html_body);
        $this->assertTrue(AuditLog::query()->where('event', 'invoice_resent')->sole()->new_values['message_changed']);
    }

    public function test_a_resend_without_the_box_carries_the_message_already_sent(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, ['client_message' => 'As first sent.']);

        $this->resend($invoice)->assertOk();

        $this->assertSame('As first sent.', $invoice->fresh()->client_message);
        $this->assertStringContainsString('As first sent.', (string) EmailMessage::query()->sole()->html_body);
        $this->assertFalse(AuditLog::query()->where('event', 'invoice_resent')->sole()->new_values['message_changed']);
    }

    public function test_a_failed_resend_leaves_the_message_the_client_last_received(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, ['client_message' => 'As first sent.']);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('Mail transport unavailable.'));

        $this->resend($invoice, ['client_message' => 'Never arrived.'])->assertStatus(422);

        $this->assertSame('As first sent.', $invoice->fresh()->client_message);
    }

    // ---- Preview ---------------------------------------------------------------

    public function test_the_preview_shows_the_box_without_saving_it(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved);

        $this->actingAs($this->createAdmin())
            ->post(route('admin.billing.invoices.email-preview', $invoice), ['client_message' => 'Still deciding on this.'])
            ->assertOk()
            ->assertSee('Still deciding on this.')
            ->assertSee('Invoice '.$invoice->invoice_number);

        $this->assertNull($invoice->fresh()->client_message);
        $this->assertDatabaseCount('email_messages', 0);
    }

    public function test_previewing_takes_the_same_permission_as_writing(): void
    {
        $this->actingAs($this->userWith([Permission::ViewBilling]))
            ->post(route('admin.billing.invoices.email-preview', $this->invoice(InvoiceStatus::Approved)), ['client_message' => 'x'])
            ->assertForbidden();
    }

    // ---- The invoice page ------------------------------------------------------

    public function test_an_unsent_invoice_offers_the_message_box(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved, ['client_message' => 'Work in progress.']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="clientMessageForm"', false)
            ->assertSee('Work in progress.')
            ->assertSee('Preview email');
    }

    public function test_a_sent_invoice_shows_the_message_as_sent_and_offers_it_in_the_resend_dialog(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, ['client_message' => 'As first sent.']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('id="clientMessageForm"', false)
            ->assertSee('Sent with the invoice. Resending lets you change it.')
            ->assertSee('id="resend_client_message"', false);
    }

    public function test_a_viewer_sees_the_message_but_no_box(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved, ['client_message' => 'Work in progress.']);

        $this->actingAs($this->userWith([Permission::ViewBilling]))
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Work in progress.')
            ->assertDontSee('id="clientMessageForm"', false)
            ->assertDontSee('Sent with the invoice.');
    }

    private function emailInvoice(?string $message): void
    {
        Mail::to('ap@acme.test')->send(new ClientInvoiceMail(
            $this->invoice(InvoiceStatus::Approved, ['client_message' => $message]),
        ));
    }

    private function saveMessage(Invoice $invoice, string $message): TestResponse
    {
        return $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.client-message', $invoice), ['client_message' => $message]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resend(Invoice $invoice, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.resend', $invoice), [
                'recipient' => 'ap@acme.test',
                ...$overrides,
            ]);
    }

    /**
     * An invoice ready to render and send. A client gets one per period, so
     * each call takes the next month.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(InvoiceStatus $status, array $attributes = []): Invoice
    {
        $this->periodsUsed++;
        $issued = $status !== InvoiceStatus::Draft;

        return Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod(now()->subMonths($this->periodsUsed)->format('Y-m'))
            ->status($status)->withTotal(120.00)
            ->create([
                'business_snapshot' => ['name' => 'Kyle Ferguson', 'contact_email' => 'hello@kyleferguson.ca'],
                'client_snapshot' => ['name' => 'Acme Industries', 'contact_name' => 'Dana Reid', 'contact_email' => 'ap@acme.test'],
                'issued_on' => $issued ? now()->subDays(3)->toDateString() : null,
                'due_on' => $issued ? now()->addDays(27)->toDateString() : null,
                'sent_at' => $status->isIssued() ? now()->subDays(3) : null,
                ...$attributes,
            ])
            ->fresh();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scoped', 'slug' => 'scoped-'.uniqid()]);

        $role->permissions()->sync(
            PermissionModel::query()
                ->whereIn('slug', array_map(fn (Permission $p): string => $p->value, $permissions))
                ->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }
}

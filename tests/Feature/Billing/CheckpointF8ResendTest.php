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
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * Sending an invoice again.
 *
 * Mark Sent only runs once, on the way out of Approved, so an invoice that
 * went to the wrong address had no way back to the right one. Resend is that
 * way back — and because a wrong address is the usual reason for it, it can
 * also replace the client link and restart the client's payment terms in the
 * same step.
 */
final class CheckpointF8ResendTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        Pdf::fake();

        // To the second: the column drops microseconds, so a frozen instant
        // carrying them would never compare equal to what was stored.
        $this->freezeSecond();

        $this->business = Business::factory()->create([
            'supported_currencies' => ['CAD'],
            'payment_terms_days' => 30,
        ]);
    }

    public function test_it_emails_the_clients_current_address_and_moves_sent_at(): void
    {
        // It is not the client's fault the first email went astray, so
        // `sent_at` records when they actually got it.
        $invoice = $this->sentInvoice();
        $invoice->client->update(['contact_email' => 'corrected@client.test']);

        $this->resend($invoice, ['recipient' => 'corrected@client.test'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', "{$invoice->invoice_number} re-sent to corrected@client.test.");

        Mail::assertSent(ClientInvoiceMail::class, fn (ClientInvoiceMail $mail): bool => $mail->hasTo('corrected@client.test'));
        Mail::assertSentCount(1);

        $this->assertTrue($invoice->fresh()->sent_at->equalTo(now()));
    }

    public function test_a_one_off_address_does_not_change_the_client(): void
    {
        $invoice = $this->sentInvoice();
        $original = $invoice->client->contact_email;

        $this->resend($invoice, ['recipient' => 'accounts@client.test'])->assertOk();

        Mail::assertSent(ClientInvoiceMail::class, fn (ClientInvoiceMail $mail): bool => $mail->hasTo('accounts@client.test'));
        $this->assertSame($original, $invoice->client->fresh()->contact_email);
    }

    public function test_the_status_is_left_where_the_payments_put_it(): void
    {
        $invoice = $this->sentInvoice(InvoiceStatus::PartiallyPaid);

        $this->resend($invoice)->assertOk();

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    // ---- The due date ------------------------------------------------------

    public function test_the_due_date_moves_when_one_is_given(): void
    {
        $invoice = $this->sentInvoice();
        $restarted = now()->addDays(30)->toDateString();

        $this->resend($invoice, ['due_on' => $restarted])->assertOk();

        $this->assertSame($restarted, $invoice->fresh()->due_on->toDateString());
    }

    public function test_the_due_date_is_left_alone_when_none_is_given(): void
    {
        // Moving sent_at does not move the due date on its own — the date is
        // what every overdue calculation reads, and changing it is a decision.
        $invoice = $this->sentInvoice();
        $before = $invoice->due_on->toDateString();

        $this->resend($invoice)->assertOk();

        $this->assertSame($before, $invoice->fresh()->due_on->toDateString());
    }

    public function test_a_due_date_before_the_issue_date_is_refused(): void
    {
        $invoice = $this->sentInvoice();

        $this->resend($invoice, ['due_on' => $invoice->issued_on->copy()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_on' => 'The due date cannot fall before the issue date of '.$invoice->issued_on->format('F j, Y').'.']);

        Mail::assertNothingSent();
    }

    public function test_the_pdf_is_re_rendered_with_the_new_due_date(): void
    {
        // The stored PDF dates from approval. Attaching it after the due date
        // moved would send a document that contradicts the email.
        $invoice = $this->sentInvoice();
        $restarted = now()->addDays(30);

        $this->resend($invoice, ['due_on' => $restarted->toDateString()])->assertOk();

        Pdf::assertSaved(Storage::disk('local')->path("invoices/{$this->business->id}/{$invoice->invoice_number}.pdf"));
        Pdf::assertSee($restarted->format('F j, Y'));
    }

    // ---- The link ----------------------------------------------------------

    public function test_replacing_the_link_sends_the_new_one_and_kills_the_old(): void
    {
        $invoice = $this->sentInvoice();
        $old = $invoice->hosted_view_token;

        $this->resend($invoice, ['replace_link' => '1'])->assertOk();

        $new = $invoice->fresh()->hosted_view_token;
        $this->assertNotSame($old, $new);

        Mail::assertSent(ClientInvoiceMail::class, fn (ClientInvoiceMail $mail): bool => $mail->content()->with['hostedUrl'] === route('invoices.hosted.show', $new));

        $this->get(route('invoices.hosted.show', $old))->assertNotFound();
        $this->get(route('invoices.hosted.show', $new))->assertOk();
    }

    public function test_the_link_is_kept_unless_replacing_it_is_asked_for(): void
    {
        // A client who lost the email still has a working link somewhere —
        // a bookmark, a forwarded copy to their bookkeeper.
        $invoice = $this->sentInvoice();
        $old = $invoice->hosted_view_token;

        $this->resend($invoice)->assertOk();

        $this->assertSame($old, $invoice->fresh()->hosted_view_token);
    }

    // ---- Refusals ----------------------------------------------------------

    public function test_an_invoice_that_was_never_sent_is_pointed_at_mark_sent(): void
    {
        $admin = $this->createAdmin();

        foreach ([InvoiceStatus::Draft, InvoiceStatus::Approved, InvoiceStatus::Void] as $status) {
            $invoice = $this->sentInvoice($status);
            $invoice->forceFill(['sent_at' => null])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.billing.invoices.resend', $invoice), ['recipient' => 'client@client.test'])
                ->assertStatus(422)
                ->assertJsonPath('message', "Invoice {$invoice->invoice_number} has not been sent yet — use Mark Sent to send it the first time.");
        }

        Mail::assertNothingSent();
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $this->resend($this->sentInvoice(), ['recipient' => 'not-an-address'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient' => 'That is not a valid email address.']);

        Mail::assertNothingSent();
    }

    public function test_resending_needs_the_issuing_permission(): void
    {
        $invoice = $this->sentInvoice();
        $editor = $this->userWithBillingPermissions([Permission::ViewBilling, Permission::ManageInvoices]);

        $this->actingAs($editor)
            ->postJson(route('admin.billing.invoices.resend', $invoice), ['recipient' => 'client@client.test'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_a_failed_send_does_not_claim_the_client_has_it(): void
    {
        // The same promise Mark Sent makes: `sent_at` only moves once the mail
        // has actually been handed off.
        $invoice = $this->sentInvoice();
        $before = $invoice->sent_at;

        Mail::shouldReceive('to')->andThrow(new RuntimeException('Mail transport unavailable.'));

        $this->resend($invoice)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mail transport unavailable.');

        $this->assertTrue($before->equalTo($invoice->fresh()->sent_at));
        $this->assertFalse(AuditLog::query()->where('event', 'invoice_resent')->exists());
    }

    // ---- The record --------------------------------------------------------

    public function test_the_resend_is_audited_with_where_it_went_and_what_moved(): void
    {
        $invoice = $this->sentInvoice();
        $previousSentAt = $invoice->sent_at->toIso8601String();
        $previousDueOn = $invoice->due_on->toDateString();
        $restarted = now()->addDays(30)->toDateString();

        $this->resend($invoice, [
            'recipient' => 'corrected@client.test',
            'due_on' => $restarted,
            'replace_link' => '1',
        ])->assertOk();

        $log = AuditLog::query()->where('event', 'invoice_resent')->sole();

        $this->assertSame($previousSentAt, $log->old_values['sent_at']);
        $this->assertSame($previousDueOn, $log->old_values['due_on']);
        $this->assertSame('corrected@client.test', $log->new_values['recipient']);
        $this->assertSame($restarted, $log->new_values['due_on']);
        $this->assertTrue($log->new_values['link_replaced']);

        $this->assertStringNotContainsString($invoice->fresh()->hosted_view_token, (string) json_encode($log->toArray()));
    }

    // ---- The invoice page --------------------------------------------------

    public function test_a_sent_invoice_offers_resend_with_the_clients_address_and_restarted_terms(): void
    {
        $invoice = $this->sentInvoice();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="resendBtn"', false)
            ->assertSee('value="'.$invoice->client->contact_email.'"', false)
            ->assertSee('data-date="'.now()->addDays(30)->toDateString().'"', false)
            ->assertSee('Restart the 30-day terms');
    }

    public function test_an_invoice_that_was_never_sent_offers_no_resend(): void
    {
        $admin = $this->createAdmin();

        foreach ([InvoiceStatus::Draft, InvoiceStatus::Approved, InvoiceStatus::Void] as $status) {
            $this->actingAs($admin)
                ->get(route('admin.billing.invoices.show', $this->sentInvoice($status)))
                ->assertOk()
                ->assertDontSee('id="resendBtn"', false)
                ->assertDontSee('id="resendForm"', false);
        }
    }

    public function test_a_user_who_cannot_issue_is_not_offered_resend(): void
    {
        $viewer = $this->userWithBillingPermissions([Permission::ViewBilling]);

        $this->actingAs($viewer)
            ->get(route('admin.billing.invoices.show', $this->sentInvoice()))
            ->assertOk()
            ->assertDontSee('id="resendBtn"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resend(Invoice $invoice, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.resend', $invoice), array_merge([
                'recipient' => $invoice->client->contact_email,
            ], $overrides));
    }

    /**
     * An invoice that went out three weeks ago and is now overdue — the shape
     * a wrong-address invoice is in by the time anyone notices.
     *
     * A client gets one invoice per period, so each call brings its own.
     */
    private function sentInvoice(InvoiceStatus $status = InvoiceStatus::Sent): Invoice
    {
        $client = Client::factory()->for($this->business)->create(['billing_currency' => 'CAD']);

        return Invoice::factory()->for($this->business)->for($client)
            ->status($status)->withTotal(120.00)
            ->create([
                'issued_on' => now()->subDays(21)->toDateString(),
                'due_on' => now()->subDays(1)->toDateString(),
                'sent_at' => now()->subDays(21),
            ])
            ->fresh();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWithBillingPermissions(array $permissions): User
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

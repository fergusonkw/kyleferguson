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
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * The client link: what protects it in transit, and what to do when it goes
 * to the wrong person.
 *
 * The link has always been unguessable. What it lacked was a way back once it
 * had been handed out — a forwarded email meant a permanent reader, fixable
 * only in the database — and any protection against leaking through the
 * Referer header, where the token rides along as part of the URL.
 */
final class CheckpointF7ClientLinkTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::factory()->create(['supported_currencies' => ['CAD']]);
    }

    // ---- Referrer-Policy ---------------------------------------------------

    public function test_the_hosted_page_forbids_sending_its_address_onward(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_the_hosted_pdf_forbids_sending_its_address_onward(): void
    {
        $invoice = $this->invoiceWithFakedPdf(InvoiceStatus::Sent);

        $this->get(route('invoices.hosted.pdf', $invoice->hosted_view_token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ---- Replacing the link ------------------------------------------------

    public function test_replacing_the_link_kills_the_old_one_and_the_new_one_works(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);
        $old = $invoice->hosted_view_token;

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
            ->assertOk()
            ->assertJsonPath('success', true);

        $new = $invoice->fresh()->hosted_view_token;

        $this->assertNotSame($old, $new);
        $this->assertSame(64, mb_strlen($new));

        $this->get(route('invoices.hosted.show', $old))->assertNotFound();
        $this->get(route('invoices.hosted.pdf', $old))->assertNotFound();
        $this->get(route('invoices.hosted.show', $new))->assertOk();
    }

    public function test_every_status_with_a_live_link_can_have_it_replaced(): void
    {
        $admin = $this->createAdmin();

        foreach ([InvoiceStatus::Approved, InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid] as $status) {
            $invoice = $this->invoice($status);

            $this->actingAs($admin)
                ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
                ->assertOk();

            $this->assertNotSame($invoice->hosted_view_token, $invoice->fresh()->hosted_view_token, $status->value);
        }
    }

    public function test_an_invoice_without_a_live_link_has_none_to_replace(): void
    {
        // A draft's link opens nothing yet, and a voided one already 404s, so
        // replacing either is a mistake worth refusing rather than silently
        // doing.
        $admin = $this->createAdmin();

        foreach ([InvoiceStatus::Draft, InvoiceStatus::Void] as $status) {
            $invoice = $this->invoice($status);

            $this->actingAs($admin)
                ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', "Invoice {$invoice->invoice_number} has no live client link to replace — only an approved or sent invoice has one.");

            $this->assertSame($invoice->hosted_view_token, $invoice->fresh()->hosted_view_token, $status->value);
        }
    }

    public function test_only_a_sent_invoice_is_told_to_resend_after_a_replacement(): void
    {
        // An approved invoice's first email will carry the new link, so
        // there is nothing to resend yet.
        $admin = $this->createAdmin();
        $approved = $this->invoice(InvoiceStatus::Approved);
        $sent = $this->invoice(InvoiceStatus::Sent);

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.rotate-link', $approved))
            ->assertOk()
            ->assertJsonPath('message', "{$approved->invoice_number} has a new client link. The old one no longer works.");

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.rotate-link', $sent))
            ->assertOk()
            ->assertJsonPath('message', "{$sent->invoice_number} has a new client link. The old one no longer works — resend the invoice to give the client the new one.");
    }

    public function test_replacing_the_link_needs_the_issuing_permission(): void
    {
        // Deciding who can read an invoice is the same call as sending it.
        $invoice = $this->invoice(InvoiceStatus::Sent);
        $editor = $this->userWithBillingPermissions([Permission::ViewBilling, Permission::ManageInvoices]);

        $this->actingAs($editor)
            ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
            ->assertForbidden();

        $this->assertSame($invoice->hosted_view_token, $invoice->fresh()->hosted_view_token);
    }

    public function test_replacing_the_link_changes_nothing_else_about_the_invoice(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);
        $invoice->forceFill(['sent_at' => now()->subDay()])->save();
        $before = $invoice->fresh();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
            ->assertOk();

        $after = $invoice->fresh();

        $this->assertSame($before->invoice_number, $after->invoice_number);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->total, $after->total);
        $this->assertTrue($before->sent_at->equalTo($after->sent_at));
    }

    public function test_replacing_the_link_is_audited_without_either_token(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);
        $old = $invoice->hosted_view_token;
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
            ->assertOk();

        $log = AuditLog::query()->where('event', 'invoice_link_rotated')->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($invoice->id, $log->auditable_id);
        $this->assertContains('security', $log->tags);

        // The new token is a live credential; the log is read by more people
        // than should hold one.
        $serialised = json_encode($log->toArray());
        $this->assertStringNotContainsString($old, (string) $serialised);
        $this->assertStringNotContainsString($invoice->fresh()->hosted_view_token, (string) $serialised);
    }

    public function test_mail_built_after_a_replacement_carries_the_new_link(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.rotate-link', $invoice))
            ->assertOk();

        $fresh = $invoice->fresh();
        $hostedUrl = (new ClientInvoiceMail($fresh))->content()->with['hostedUrl'];

        $this->assertSame(route('invoices.hosted.show', $fresh->hosted_view_token), $hostedUrl);
    }

    // ---- The invoice page --------------------------------------------------

    public function test_an_issued_invoice_shows_its_link_with_copy_and_replace(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Client Link')
            ->assertSee(route('invoices.hosted.show', $invoice->hosted_view_token), false)
            ->assertSee('id="copyLinkBtn"', false)
            ->assertSee('id="rotateLinkBtn"', false);
    }

    public function test_a_user_who_cannot_issue_sees_the_link_but_not_the_replace_button(): void
    {
        // Seeing the link grants nothing a view-only user lacks: they can
        // already download the PDF and send that anywhere.
        $invoice = $this->invoice(InvoiceStatus::Sent);
        $viewer = $this->userWithBillingPermissions([Permission::ViewBilling]);

        $this->actingAs($viewer)
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="copyLinkBtn"', false)
            ->assertDontSee('id="rotateLinkBtn"', false);
    }

    public function test_an_approved_invoice_shows_its_link_before_it_is_sent(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Approved);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="clientLinkInput"', false)
            ->assertSee(route('invoices.hosted.show', $invoice->hosted_view_token), false);
    }

    public function test_an_invoice_without_a_live_link_shows_none(): void
    {
        $admin = $this->createAdmin();

        foreach ([InvoiceStatus::Draft, InvoiceStatus::Void] as $status) {
            $invoice = $this->invoice($status);

            $this->actingAs($admin)
                ->get(route('admin.billing.invoices.show', $invoice))
                ->assertOk()
                ->assertDontSee('id="clientLinkInput"', false)
                ->assertDontSee($invoice->hosted_view_token, false);
        }
    }

    /**
     * A client gets one invoice per period, so each call brings its own client
     * — the loops above need several invoices side by side.
     */
    private function invoice(InvoiceStatus $status): Invoice
    {
        $client = Client::factory()->for($this->business)->create(['billing_currency' => 'CAD']);

        return Invoice::factory()->for($this->business)->for($client)
            ->status($status)->withTotal(120.00)->create();
    }

    /**
     * An invoice whose PDF render is faked, so serving it does not drive
     * Chromium — the header is the subject here, not the render.
     */
    private function invoiceWithFakedPdf(InvoiceStatus $status): Invoice
    {
        Pdf::fake();

        return $this->invoice($status);
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

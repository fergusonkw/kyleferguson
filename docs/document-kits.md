# Document kits

A **document kit** is a stylesheet owned by another repository that one of this
application's invoice templates renders with. Tracker Pull's kit lives in its
marketing site, where the same class names dress invoices, reports and letters;
this application vendors a copy so its invoices look like every other Tracker
Pull document.

Kits exist because invoice templates are per-business. A business picks its
template on the businesses page, the choice is snapshotted onto every invoice it
issues, and the template is free to bring styling from wherever that business's
brand actually lives.

## Syncing

Refresh every vendored kit:

```bash
php artisan billing:sync-document-kit
```

Check whether the vendored copies are current, changing nothing — this is what
belongs in CI or a pre-commit hook:

```bash
php artisan billing:sync-document-kit --check
```

One kit, from a checkout that is not a sibling of this one:

```bash
php artisan billing:sync-document-kit tracker-pull --from=../some/other/path
```

By default the command looks for the source repository beside this one, so with
`kyleferguson` and `tracker-pull-marketing-site` both checked out under
`C:\laragon\www` it needs no arguments.

**Commit the result.** The vendored file is what renders in production; the
source repository is not present there.

### When to sync

- After a change to `templates/kit/tracker-pull-doc.css` upstream.
- Before a release, if you want the invoice to pick up a brand change.
- Never automatically. Taking an upstream change is a decision this repository
  makes, because a change to the kit changes how invoices print.

## Why the sync pulls rather than being pushed

The owning repository has its own build (`npm run templates:build`) that inlines
the kit into its standalone HTML templates, and it could just as easily write
into this repository. It deliberately does not:

- **Breakage would arrive unannounced.** A commit in a marketing site would
  change how invoices render without this application's tests ever running.
- **The path would be a fiction.** Cross-repo writes only work when both
  checkouts sit where the script expects. That is a property of one developer's
  machine, not of the repositories; the marketing site's CI has no checkout of
  this one.
- **Nothing would record where the copy came from.**

Pulling keeps the timing, the verification and the provenance on this side. It
is the same arrangement `billing:sync-invoice-fonts` uses for the webfonts:
upstream is the provenance and the upgrade path, the committed copy is what
actually renders.

## What crosses the boundary

**The CSS. Not the markup.**

Upstream's `templates/invoice.html` is a static, fill-in-the-blanks reference
implementation. The Blade template here reimplements that markup against the
same class names, because its conditionals — the Qty/Unit Price columns
collapsing when nothing is metered, child lines, conversion notes, the status
badge, the hosted client bar — interleave with the very elements a generated
block would have to own. There is no coherent seam to generate.

So the two will drift as markup, and that is expected. What must not drift is
the styling, which is why only the CSS is vendored and `--check` exists.

## Rules for a template that uses a kit

**Never edit the vendored file.** It carries a generated provenance header and
is otherwise byte-identical to upstream, so drift shows up as a clean diff. The
sync compares the styling alone, so a commit upstream that leaves the CSS alone
does not read as staleness.

**Override, don't fork.** Anything a template needs that the kit does not carry
goes *after* the kit is inlined:

```blade
<style>
{!! $kitCss !!}
{!! $fontFaceCss !!}

  :root {
    --brand-red: {{ $accent }};   {{-- from the business snapshot --}}
  }
</style>
```

This is how per-business branding wins without touching the shared file, and
it is why a third business could adopt the Tracker Pull layout with its own
colours. Emit an override only when the value is actually set, so an unset
colour leaves the kit's own token alone.

**Inline, never link.** Browsershot renders from a bare HTML string with no
document base and no promise of network access. A linked stylesheet or a
remote font leaves the same invoice printing two different ways depending on
the host — silently, because a font falling back looks like a design choice.
The kit, the fonts and the logo are all inlined for this reason.

## Adding a kit

1. Register it in `DocumentKitStore::KITS` (kit name → vendored filename) and
   point a template at it in `DocumentKitStore::TEMPLATE_KITS`.
2. Register where it comes from in `SyncDocumentKit::SOURCES`.
3. Run `php artisan billing:sync-document-kit <name>` and commit the result.

## Fonts

Kits and fonts are vendored separately. A kit names a font family; the faces
themselves come from `resources/fonts` via `InvoiceFontStore`, which embeds
them as data URIs.

Faces are scoped per template in `InvoiceFontStore::TEMPLATE_FAMILIES`, because
every declared face is embedded in every document — shipping all of them would
put Inter into invoices set in Archivo and roughly double both templates' PDFs.
A template absent from that map gets every face, on the grounds that a heavier
document beats one whose fonts silently fall back.

Adding a family means adding it to `InvoiceFontStore::FACES`, mapping its npm
package in `SyncInvoiceFonts::PACKAGES`, installing that package, and running:

```bash
php artisan billing:sync-invoice-fonts
```

## Email templates

A business also picks a client-email template (`email_template_view`), from the
same registry mechanism and snapshotted onto each invoice the same way.

**Each HTML template needs a `-text` companion.** The registry treats
`<name>-text.blade.php` as the plain-text half of `<name>.blade.php` rather than
a choice of its own, and `ClientInvoiceMail` pairs them by name. A template
shipped without a companion still sends — only its plain-text part falls back to
the default's, which will describe the invoice correctly but in the wrong voice.

**Mail templates do not inline the kit.** Mail clients strip `<style>` blocks
unpredictably, so every rule is an inline attribute on a table and the colours
come from the invoice's snapshot rather than the kit's tokens. They also carry
no webfont: clients block remote font fetches far harder than images, and a
half-loaded face reads worse than the system stack the kit falls back to.

Keep the two halves saying the same things. Anything the HTML states — the
amount, the due date, the hosted link, the late-fee terms — belongs in the text
part too, because plenty of readers only ever see that one.

## Current kits

| Kit | Invoice template | Email template | Source | Fonts |
| --- | --- | --- | --- | --- |
| `tracker-pull` | `admin-v2.billing.invoices.templates.tracker-pull` | `emails.invoices.tracker-pull` | `fergusonkw/tracker-pull-marketing-site` → `templates/kit/tracker-pull-doc.css` | Inter |
| — | `admin-v2.billing.invoices.templates.default` | `emails.invoices.default` | Carries its own CSS | Archivo, Space Mono |

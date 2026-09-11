Invoice generation needs attention — {{ $businessName }}, {{ $periodLabel }}

{{ count($failures) }} client{{ count($failures) === 1 ? '' : 's' }} could not be invoiced for {{ $periodLabel }}.
Work billed to them is not on any draft.

@foreach($failures as $client => $reason)
- {{ $client }}: {{ $reason }}
@endforeach

Open invoices: {{ $listUrl }}

Fix the cause and generate the draft by hand, or wait for tomorrow's run.

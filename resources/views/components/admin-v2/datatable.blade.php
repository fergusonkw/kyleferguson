@props([
    'id',
    'columns' => [],
    'ajaxUrl' => null,
    'serverSide' => true,
    'searchable' => true,
    'orderable' => true,
    'pageLength' => 25,
    'exportButtons' => false,
    'selectRows' => false,
    'order' => null,
    /** HTML shown when the table has zero rows. */
    'emptyMessage' => 'No entries to show.',
    /** HTML shown when the user's search returns nothing. */
    'noResultsMessage' => 'No matching records found.',
])

<div class="overflow-x-auto pt-2">
    <table id="{{ $id }}" class="table w-full" {{ $attributes }}>
        <thead>
            <tr>
                @if(!empty($columns))
                    @foreach($columns as $column)
                        <th data-column="{{ $column['data'] ?? '' }}"
                            @if(isset($column['orderable']) && !$column['orderable']) data-orderable="false" @endif
                            @if(isset($column['searchable']) && !$column['searchable']) data-searchable="false" @endif
                            @if(isset($column['className'])) class="{{ $column['className'] }}" @endif>
                            {{ $column['title'] }}
                        </th>
                    @endforeach
                @endif
            </tr>
        </thead>
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>

@push('scripts')
<script>
(function() {
    function initializeDataTable() {
        if (typeof window.DataTable === 'undefined') {
            console.error('DataTables not loaded yet, retrying...');
            setTimeout(initializeDataTable, 100);
            return;
        }

        const columns = @json($columns);

        const tableConfig = {
            processing: true,
            @if($serverSide && $ajaxUrl)
            serverSide: true,
            ajax: {
                url: '{{ $ajaxUrl }}',
                type: 'GET',
                error: function(xhr, error, code) {
                    console.error('DataTable AJAX error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        error: error,
                        code: code,
                        responseText: xhr.responseText
                    });

                    const errorMsg = xhr.status === 404
                        ? 'Endpoint not found. Please check the route configuration.'
                        : 'Error loading data. Please try again.';

                    if (window.Alert) {
                        Alert.error(errorMsg);
                    }
                }
            },
            @endif
            columns: columns.length > 0 ? columns : null,
            pageLength: {{ $pageLength }},
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            searching: {{ $searchable ? 'true' : 'false' }},
            ordering: {{ $orderable ? 'true' : 'false' }},
            responsive: true,
            autoWidth: false,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search records...",
                lengthMenu: "_MENU_ records per page",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries to show",
                infoFiltered: "(filtered from _MAX_ total entries)",
                emptyTable: @json($emptyMessage),
                zeroRecords: @json($noResultsMessage),
                processing: '<div class="inline-block size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" role="status"><span class="sr-only">Loading...</span></div>',
                paginate: {
                    first: '&laquo;',
                    previous: '&lsaquo;',
                    next: '&rsaquo;',
                    last: '&raquo;'
                }
            },
            @if($selectRows)
            select: {
                style: 'multi',
                selector: 'td:first-child'
            },
            @endif
            @if($exportButtons)
            dom: '<"flex items-center justify-between mb-3"lf>Brtip',
            buttons: [
                { extend: 'copy',  className: 'btn btn-sm btn-light', text: 'Copy' },
                { extend: 'csv',   className: 'btn btn-sm btn-light', text: 'CSV' },
                { extend: 'excel', className: 'btn btn-sm btn-light', text: 'Excel' },
                { extend: 'pdf',   className: 'btn btn-sm btn-light', text: 'PDF' },
                { extend: 'print', className: 'btn btn-sm btn-light', text: 'Print' }
            ],
            @else
            dom: '<"flex items-center justify-between mb-3"lf>rtip',
            @endif
            @if($order)
            order: @json($order),
            @endif
        };

        try {
            const table = new DataTable('#{{ $id }}', tableConfig);
            window['dataTable_{{ $id }}'] = table;
        } catch (error) {
            console.error('Error initializing DataTable:', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDataTable);
    } else {
        initializeDataTable();
    }
})();
</script>
@endpush

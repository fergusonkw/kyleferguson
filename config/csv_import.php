<?php

declare(strict_types=1);

return [
    // Storage configuration
    'storage' => [
        'disk' => 'local',
        'path' => 'csv-imports',
        'max_size' => 10 * 1024, // 10MB in KB
        'allowed_extensions' => ['csv'],
    ],

    // Processing configuration
    'processing' => [
        'chunk_size' => 50, // Process records in chunks
        'timeout' => 300, // 5 minutes timeout
        'max_errors' => 50, // Maximum errors before stopping
    ],

    // Column mapping configuration
    'column_mapping' => [
        'required_columns' => [
            'driver_name',
            'vehicle_name',
            'class_name',
            'event_date',
        ],
        'optional_columns' => [
            'driver_hometown',
            'owner_name',
            'vehicle_make_model', // Alternative/supplement to vehicle_name
            'distance_pulled',
            'disqualification_reason',
            'pulloff_distance',
            'pulloff_disqualification_reason',
            'pull_order',
            'notes',
            'class_sequence',
        ],
        // Flexible column names that map to our required fields
        'column_aliases' => [
            'driver_name' => ['driver', 'driver_name', 'participant', 'participant_name'],
            'distance_pulled' => ['distance', 'distance_pulled', 'pull_distance', 'result'],
            'vehicle_name' => ['vehicle', 'vehicle_name', 'tractor', 'tractor_name'],
            'vehicle_make_model' => ['vehicle_make_model', 'make_model', 'make/model', 'make_and_model', 'tractor_make_model'],
            'class_name' => ['class', 'class_name', 'division', 'category'],
            'event_date' => ['event_date', 'date', 'pull_date', 'class_date', 'day'],
            'class_sequence' => ['class_sequence', 'sequence', 'run_number', 'class_run', 'run'],
            'disqualification_reason' => ['dq', 'disqualified', 'dq_reason', 'disqualification'],
            'pulloff_distance' => ['pulloff_distance', 'pulloff_dist', 'pulloff', 'full_pull_distance'],
            'pulloff_disqualification_reason' => ['pulloff_dq', 'pulloff_disqualification', 'pulloff_dq_reason'],
        ],
    ],

    // Business logic configuration
    'business_rules' => [
        // Auto-detect points eligibility based on organization
        'auto_set_points_eligibility' => true,

        // Whether to calculate points after import
        'auto_calculate_points' => true,

        // Whether to overwrite existing participants
        'allow_overwrite' => false,

        // Default pull order increment if not provided
        'default_pull_order_increment' => 10,
    ],

    // Validation rules
    'validation' => [
        'distance_pulled' => [
            'nullable',
            'numeric',
            'min:0',
            'max:999.99',
        ],
        'driver_name' => [
            'required',
            'string',
            'max:255',
        ],
        'vehicle_name' => [
            'nullable',
            'required_without:vehicle_make_model',
            'string',
            'max:255',
        ],
        'vehicle_make_model' => [
            'nullable',
            'string',
            'max:255',
        ],
        'class_name' => [
            'required',
            'string',
            'max:255',
        ],
        'event_date' => [
            'required',
            'date',
        ],
        'class_sequence' => [
            'nullable',
            'integer',
            'min:1',
            'max:99',
        ],
    ],
];

<?php

/*
| OCR engine selection and fallback (spec 3.6).
|
| Every engine with driver "http" is a container implementing the OCR contract
| (spec 3.2), so adding an engine is a new container plus an entry here (NFR-10).
|
| Timeouts must nest. Worst case per engine: (1 + retries) x timeout + backoff
| = 4 x 60 s + 130 s = 370 s; two engines = 740 s. That stays below the worker
| --timeout (900 s), which stays below REDIS_QUEUE_RETRY_AFTER (960 s).
*/

return [

    'default' => env('OCR_ENGINE', 'paddle'),

    'fallback' => array_values(array_filter(array_map('trim', explode(',', (string) env('OCR_FALLBACK', 'paddle,ndl'))))),

    // Seconds per OCR call.
    'timeout' => (int) env('OCR_TIMEOUT', 60),

    // Retries per engine on 500 / 503 / connection errors, waiting `backoff` seconds before each.
    'retries' => (int) env('OCR_RETRIES', 3),
    'backoff' => [10, 30, 90],

    // V-09: lines below this average confidence are flagged (spec 3.5, 3.6).
    'confidence_min' => (float) env('OCR_CONFIDENCE_MIN', 0.80),

    // Upload limit per file in MB (spec 3.6, FR-01).
    'upload' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 20),
    ],

    'engines' => [

        'paddle' => [
            'driver' => 'http',
            'url' => env('OCR_PADDLE_URL', 'http://ocr-paddle:8000'),
            'enabled' => env('OCR_PADDLE_ENABLED', true),
        ],

        // Light fallback engine (NDLOCR-Lite); enable once its container exists.
        'ndl' => [
            'driver' => 'http',
            'url' => env('OCR_NDL_URL', 'http://ocr-ndl:8000'),
            'enabled' => env('OCR_NDL_ENABLED', false),
        ],

        // Paid cloud OCR; off unless explicitly enabled (NFR-09).
        'cloud' => [
            'driver' => 'cloud',
            'enabled' => env('OCR_CLOUD_ENABLED', false),
        ],

    ],

];

<?php

/*
| OCR engine selection and fallback (spec 3.6).
|
| Every engine with driver "http" is a container implementing the OCR contract
| (spec 3.2), so adding an engine is a new container plus an entry here (NFR-10).
|
| Timeouts must nest. Worst case per engine: (1 + retries) x timeout + backoff.
| Defaults: 4 x 60 s + 130 s = 370 s; paddle + ndl = 740 s. Development (slow
| emulated paddle): OCR_TIMEOUT=200, OCR_RETRIES=1 -> 2 x 200 s + 10 s = 410 s;
| two engines = 820 s. Both stay below the worker --timeout (900 s), which stays
| below REDIS_QUEUE_RETRY_AFTER (960 s). A third engine needs smaller values.
*/

return [

    'default' => env('OCR_ENGINE', 'paddle'),

    'fallback' => array_values(array_filter(array_map('trim', explode(',', (string) env('OCR_FALLBACK', 'paddle,ndl'))))),

    // Seconds per OCR call.
    'timeout' => (int) env('OCR_TIMEOUT', 60),

    // Retries per engine on 500 / 503 / connection errors, waiting `backoff` seconds before each.
    'retries' => (int) env('OCR_RETRIES', 3),
    'backoff' => [10, 30, 90],

    // Field extraction (spec 3.6 "extraction.driver"): rule, or llm (FR-17, not implemented yet).
    'extraction' => [
        'driver' => env('EXTRACTOR', 'rule'),
    ],

    // V-09: lines below this average confidence are flagged (spec 3.5, 3.6).
    'confidence_min' => (float) env('OCR_CONFIDENCE_MIN', 0.80),

    // Address of this app on the home Wi-Fi (http://<Mac IP>:8080), shown as a QR code
    // on the upload page so a phone can open it. The IP can change after reconnecting.
    'phone_url' => env('PHONE_URL'),

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

        // Light fallback engine (NDLOCR-Lite, container ocr-ndl), tried when paddle fails a critical check.
        'ndl' => [
            'driver' => 'http',
            'url' => env('OCR_NDL_URL', 'http://ocr-ndl:8000'),
            'enabled' => env('OCR_NDL_ENABLED', true),
        ],

        // Paid cloud OCR; off unless explicitly enabled (NFR-09).
        'cloud' => [
            'driver' => 'cloud',
            'enabled' => env('OCR_CLOUD_ENABLED', false),
        ],

    ],

];

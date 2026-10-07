<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Ocr\Engines\HttpOcrEngine;
use App\Ocr\OcrManager;
use Illuminate\Http\JsonResponse;

class EngineController extends Controller
{
    /**
     * Configured engines with their health (spec 2.3, used for monitoring in 2.6).
     */
    public function __invoke(OcrManager $ocr): JsonResponse
    {
        $engines = collect(config('ocr.engines'))->map(function (array $config, string $name) use ($ocr) {
            $engine = $config['enabled'] ? $ocr->driver($name) : null;

            return [
                'name' => $name,
                'driver' => $config['driver'],
                'enabled' => (bool) $config['enabled'],
                'default' => $name === $ocr->getDefaultDriver(),
                'health' => $engine instanceof HttpOcrEngine ? $engine->health() : null,
            ];
        })->values();

        return response()->json([
            'data' => $engines,
            'fallback' => $ocr->fallbackOrder(),
        ]);
    }
}

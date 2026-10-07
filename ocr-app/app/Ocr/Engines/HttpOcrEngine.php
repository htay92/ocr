<?php

namespace App\Ocr\Engines;

use App\Ocr\Contracts\OcrEngine;
use App\Ocr\Data\OcrResult;
use App\Ocr\OcrException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Calls an OCR container through the contract in spec 3.2.
 *
 * - 500 / 503 / connection errors: retried with backoff, then OcrException.
 * - 400: fails at once.
 * - 413: the image is downscaled once and sent again.
 */
final class HttpOcrEngine implements OcrEngine
{
    private const DOWNSCALE_LONG_SIDE = 4000;

    /**
     * @param  list<int>  $backoff  seconds to wait before each retry
     */
    public function __construct(
        private readonly string $name,
        private readonly string $url,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly array $backoff,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function recognize(string $path, array $options = []): OcrResult
    {
        if (! is_file($path)) {
            throw new OcrException('invalid_image', "{$this->name}: file not found: {$path}");
        }

        $downscaled = null;
        $attempt = 0;

        try {
            while (true) {
                try {
                    $response = $this->post($downscaled ?? $path, $options);
                } catch (ConnectionException $e) {
                    if ($attempt < $this->retries) {
                        $this->wait($attempt++);

                        continue;
                    }
                    throw new OcrException(OcrException::UNREACHABLE, "{$this->name}: {$e->getMessage()}", $e);
                }

                if ($response->successful()) {
                    return OcrResult::fromResponse($response->json());
                }

                if ($response->status() === 413 && $downscaled === null) {
                    $downscaled = $this->downscale($path) ?? throw $this->failure($response);

                    continue;
                }

                if (in_array($response->status(), [500, 503], true) && $attempt < $this->retries) {
                    $this->wait($attempt++);

                    continue;
                }

                throw $this->failure($response);
            }
        } finally {
            if ($downscaled !== null) {
                @unlink($downscaled);
            }
        }
    }

    /**
     * @return array{engine: string, ready: bool, version?: string, message?: string}
     */
    public function health(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->url}/health");
        } catch (ConnectionException $e) {
            return ['engine' => $this->name, 'ready' => false, 'message' => $e->getMessage()];
        }

        return $response->json() ?? ['engine' => $this->name, 'ready' => false, 'message' => "HTTP {$response->status()}"];
    }

    private function post(string $path, array $options): Response
    {
        return Http::timeout($this->timeout)
            ->attach('file', file_get_contents($path), basename($path))
            ->post("{$this->url}/ocr", [
                'preprocess' => ($options['preprocess'] ?? true) ? 'true' : 'false',
                'lang' => $options['lang'] ?? 'ja',
                'return_image' => ($options['return_image'] ?? false) ? 'true' : 'false',
            ]);
    }

    private function wait(int $attempt): void
    {
        Sleep::for($this->backoff[$attempt] ?? end($this->backoff) ?: 0)->seconds();
    }

    private function failure(Response $response): OcrException
    {
        $code = $response->json('code') ?? 'engine_error';
        $message = $response->json('message') ?? "HTTP {$response->status()}";

        return new OcrException($code, "{$this->name}: {$message}");
    }

    /**
     * Shrink the image so its long side is 4,000 px. Returns a temporary JPEG,
     * or null when the file isn't an image GD can read (a PDF, for example).
     */
    private function downscale(string $path): ?string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return null;
        }

        $scale = self::DOWNSCALE_LONG_SIDE / max(imagesx($image), imagesy($image));
        if ($scale < 1) {
            $image = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        }

        $target = tempnam(sys_get_temp_dir(), 'ocr-down').'.jpg';
        imagejpeg($image, $target, 90);

        return $target;
    }
}

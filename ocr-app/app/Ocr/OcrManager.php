<?php

namespace App\Ocr;

use App\Ocr\Contracts\OcrEngine;
use App\Ocr\Engines\CloudOcrEngine;
use App\Ocr\Engines\HttpOcrEngine;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves OCR engines from config/ocr.php, like Laravel's Mail and Storage managers.
 *
 * @method OcrEngine driver(?string $driver = null)
 */
class OcrManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('ocr.default');
    }

    /**
     * Engines to try in order: the default first, then OCR_FALLBACK,
     * without duplicates or disabled engines.
     *
     * @return list<string>
     */
    public function fallbackOrder(): array
    {
        $names = array_unique([$this->getDefaultDriver(), ...$this->config->get('ocr.fallback', [])]);

        return array_values(array_filter($names, fn (string $name) => (bool) $this->config->get("ocr.engines.{$name}.enabled")));
    }

    /**
     * @return list<string>
     */
    public function enabledEngines(): array
    {
        return array_keys(array_filter($this->config->get('ocr.engines', []), fn (array $engine) => (bool) ($engine['enabled'] ?? false)));
    }

    protected function createDriver($driver): OcrEngine
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $config = $this->config->get("ocr.engines.{$driver}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("OCR engine [{$driver}] is not configured.");
        }

        if (! ($config['enabled'] ?? false)) {
            throw new InvalidArgumentException("OCR engine [{$driver}] is disabled.");
        }

        return match ($config['driver'] ?? null) {
            'http' => new HttpOcrEngine(
                name: $driver,
                url: rtrim($config['url'], '/'),
                timeout: (int) $this->config->get('ocr.timeout'),
                retries: (int) $this->config->get('ocr.retries'),
                backoff: $this->config->get('ocr.backoff'),
            ),
            'cloud' => new CloudOcrEngine,
            default => throw new InvalidArgumentException("OCR engine [{$driver}] has an unknown driver."),
        };
    }
}

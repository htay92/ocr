<?php

namespace App\Providers;

use App\Extraction\Contracts\Extractor;
use App\Extraction\RuleExtractor;
use App\Ocr\OcrManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OcrManager::class);

        $this->app->bind(Extractor::class, fn () => match (config('ocr.extraction.driver')) {
            'rule' => new RuleExtractor,
            default => throw new InvalidArgumentException('Unknown extractor ['.config('ocr.extraction.driver').']; only "rule" is implemented.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

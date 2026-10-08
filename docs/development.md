# Developer guide

How to set up, run, debug and read the logs of the Receipt OCR system on a development machine. The specification in `docs/spec/` describes *what* the system does; this guide covers *how to work on it*.

- [1. Overview](#1-overview)
- [2. Setup (first time)](#2-setup-first-time)
- [3. Running the system](#3-running-the-system)
- [4. Configuration](#4-configuration)
- [5. Tests and code style](#5-tests-and-code-style)
- [6. Logs](#6-logs)
- [7. Debugging](#7-debugging)
- [7a. Google Cloud Vision (optional engine)](#7a-google-cloud-vision-optional-engine)
- [8. Troubleshooting](#8-troubleshooting)
- [9. Where things are](#9-where-things-are)

---

## 1. Overview

Everything runs in Docker Compose (`docker-compose.yml`, project name `ocr`). Run every `docker compose` command from the repository root (`ocr/`).

| Service | What it is | Reachable at |
| --- | --- | --- |
| `web` | nginx 1.27, serves `ocr-app/public` | **http://localhost:8080** (the only host port) |
| `app` | PHP 8.3-FPM with Laravel 12 (`ocr-app/` is bind-mounted) | via `web` |
| `worker` | Same image, runs `php artisan queue:work --tries=1 --timeout=900` | — |
| `redis` | Queue and cache | internal |
| `db` | PostgreSQL 16 (production database; development uses SQLite) | internal |
| `ocr-paddle` | PaddleOCR PP-OCRv5, FastAPI (`POST /ocr`, `GET /health`) | internal, `http://ocr-paddle:8000` |
| `ocr-ndl` | NDLOCR-Lite (ONNX), the fallback engine, same API | internal, `http://ocr-ndl:8000` |
| `ocr-cloud` | Google Cloud Vision (optional, compose profile `cloud`, off by default) | internal, `http://ocr-cloud:8000` |

How a document flows:

```
browser / phone ──► web ──► app ──► storage/app/private/documents/…
                               └──► Redis queue ──► worker: ProcessDocumentJob
                                                     ├─ CSV / Excel / PDF with text: read directly (no OCR)
                                                     └─ image / scanned PDF: ocr-paddle ──(critical check failed)──► ocr-ndl
                                                        → classify → extract → validate → status
```

Statuses: `pending → processing → extracted | needs_review | failed`, then `confirmed` after review (spec 2.4).

---

## 2. Setup (first time)

### Requirements

- Docker Desktop (or Docker Engine with Compose v2). Give Docker at least **8 GB of memory** and about **10 GB of disk**. The images are large: `ocr-paddle` 2.7 GB, `ocr-ndl` 1 GB, PHP 0.8 GB.
- Git with access to `git@github.com:htay92/ocr.git`.
- On **Apple Silicon**, `ocr-paddle` runs as an x86 image under emulation, because PaddlePaddle's arm64 build crashes. It works, but it is slow: about 30–90 s per receipt. `ocr-ndl` runs natively and takes about 5 s.

You do not need PHP, Composer or Python on the host.

### Steps

```bash
git clone git@github.com:htay92/ocr.git
cd ocr

# 1. Build the images (the first ocr-paddle build downloads the models and takes a while).
docker compose build

# 2. Laravel environment file and SQLite database.
cp ocr-app/.env.example ocr-app/.env
touch ocr-app/database/database.sqlite

# 3. PHP dependencies and app key (run inside the PHP image).
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate

# 4. Start everything and create the tables.
docker compose up -d
docker compose exec app php artisan migrate

# 5. Create your login (also prints an API token).
docker compose exec app php artisan user:token you@example.com --password='choose-a-password'
```

Open http://localhost:8080 and log in. The UI is in Japanese by default; the language switch is in the header.

Wait until both engines are **healthy** before uploading images. The PaddleOCR model takes up to two minutes to load under emulation:

```bash
docker compose ps          # ocr-paddle / ocr-ndl should show "(healthy)"
```

### Development settings to change in `ocr-app/.env`

Paddle under emulation is slower than the default timeout allows. On a Mac, use:

```dotenv
OCR_TIMEOUT=200
OCR_RETRIES=1
```

The reason is in [Timeouts](#timeouts).

---

## 3. Running the system

### Start, stop, rebuild

```bash
docker compose up -d                    # start (or apply compose changes)
docker compose stop                     # stop, keep containers
docker compose down                     # remove containers (data stays: SQLite file, volumes, storage/)
docker compose ps                       # status and health
docker compose restart worker           # after changing job, extractor, validation or OCR client code
docker compose up -d --build ocr-ndl    # after changing engines/ (rebuild the engine image)
```

What needs a restart after you edit code:

| You changed | Do this |
| --- | --- |
| Controllers, views, routes, CSS (`ocr-app/…`) | Nothing. Reload the page; the code is bind-mounted. |
| Jobs, extraction, validation, OCR client (code the worker runs) | `docker compose restart worker` |
| `ocr-app/.env` or `config/*.php` | `docker compose restart app worker` |
| `engines/common/*` or `engines/ocr-*/*` | `docker compose up -d --build ocr-paddle ocr-ndl` |
| `docker/php/Dockerfile`, `php.ini` | `docker compose up -d --build app worker` |

Don't run `php artisan config:cache` or `optimize` in development. A cached config hides `.env` changes. If it ever happens, run `docker compose exec app php artisan optimize:clear`.

### Using it from a phone

Uploading from the phone camera needs a URL the phone can reach. Two options:

- **Same Wi-Fi:** set `PHONE_URL=http://<your Mac's IP>:8080` in `ocr-app/.env`. The upload page then shows a QR code.
- **VS Code port forwarding:** forward port 8080 with visibility *Private* and sign in with the same GitHub account on the phone. Put the forwarded `https://…devtunnels.ms` URL in `PHONE_URL`. Laravel trusts the proxy headers (`bootstrap/app.php`), so HTTPS links and CSRF work.

### Useful artisan commands

```bash
docker compose exec app php artisan ocr:run path/to/receipt.jpg --engine=ndl   # what one engine reads
docker compose exec app php artisan ocr:run path/to/receipt.jpg --json         # raw contract response
docker compose exec app php artisan ocr:compare storage/app/samples           # all engines × all files (see 5.)
docker compose exec app php artisan user:token you@example.com                # new API token (Bearer)
docker compose exec app php artisan queue:failed                              # jobs that crashed
docker compose exec app php artisan migrate:status
```

Paths given to artisan are inside the container, relative to `ocr-app/`. To use a file from elsewhere, copy it under `ocr-app/storage/app/` first; that folder is git-ignored.

---

## 4. Configuration

Laravel reads `ocr-app/.env` itself. **Never add `env_file:` to the PHP services in compose.** Injected variables outrank `phpunit.xml`, so the tests would then run against the development database and Redis.

The keys you are most likely to touch (all of them: spec 3.6 and `config/ocr.php`):

| Key | Default | Meaning |
| --- | --- | --- |
| `OCR_ENGINE` | `paddle` | First engine |
| `OCR_FALLBACK` | `paddle,ndl` | Engines in order; the next one runs when a critical check (V-xx) fails |
| `OCR_NDL_ENABLED` | `true` | Use the NDLOCR-Lite fallback |
| `OCR_CLOUD_ENABLED` | `false` | Google Cloud Vision ([7a](#7a-google-cloud-vision-optional-engine)) |
| `OCR_CLOUD_MONTHLY_LIMIT` | `1000` | Successful Vision runs per month before uploads stop using it |
| `OCR_TIMEOUT` / `OCR_RETRIES` | `60` / `3` | Per OCR call; see Timeouts below |
| `OCR_CONFIDENCE_MIN` | `0.80` | V-09 warning threshold |
| `EXTRACTOR` | `rule` | Field extraction driver |
| `APP_LOCALE` | `ja` | UI language (`ja` or `en`) |
| `PHONE_URL` | empty | URL shown as a QR code on the upload page |
| `LOG_LEVEL` | `debug` | See [Logs](#6-logs) |
| `UPLOAD_MAX_MB` | `20` | Per file |

### Timeouts

A slow job must finish before the queue considers it lost; otherwise it is started a second time. The limits must nest:

```
OCR calls of all engines, with retries  <  worker --timeout (900 s)  <  REDIS_QUEUE_RETRY_AFTER (960 s)
```

Per engine, the worst case is `(1 + OCR_RETRIES) × OCR_TIMEOUT + backoff (10/30/90 s)`.

| Settings | Per engine | paddle + ndl |
| --- | --- | --- |
| Defaults: 60 s timeout, 3 retries | 370 s | 740 s |
| Mac development: 200 s timeout, 1 retry | 410 s | 820 s |

If you add a third engine, lower these values.

---

## 5. Tests and code style

```bash
docker compose exec app php artisan test                          # all Pest tests (~7 s)
docker compose exec app php artisan test --filter=TemplateFlow    # one file or test name
docker compose exec app ./vendor/bin/pint                         # format PHP (run before committing)

docker compose exec ocr-ndl pytest -q                             # engine contract tests (~30 s)
docker compose exec ocr-paddle pytest -q                          # same tests, PaddleOCR (~3 min on a Mac)
```

- `phpunit.xml` forces the test settings: an in-memory SQLite database, the sync queue, no ndl and no cloud engine, and English. Tests never call a real OCR engine. They fake it with `Http::fake()` and the helpers in `tests/Pest.php` (`ocrResponse()`, `validReceiptLines()`, `kintaiCsv()`…).
- The engine tests in `engines/common/tests/` are shared: the same contract tests run in every engine container. Engine-specific tests sit in `engines/ocr-*/tests/`.
- Fixtures: synthetic attendance files are in `ocr-app/tests/fixtures/`. Real samples (receipts with personal data, the real 勤務データ PDF) are never committed; the folder's `.gitignore` excludes them.

### Measuring OCR accuracy

`ocr:compare` runs every enabled engine on sample files and checks the NFR-01 key fields against a ground-truth `expected.json` placed next to the files. Nothing is saved.

```bash
mkdir -p ocr-app/storage/app/samples
cp docs/tests/fixtures/receipts/* ocr-app/storage/app/samples/      # images + expected.json
docker compose exec app php artisan ocr:compare storage/app/samples
docker compose exec app php artisan ocr:compare storage/app/samples --engines=ndl
```

Run it before and after changing the normalizer, the extraction rules or the preprocessing.

---

## 6. Logs

| What | Where | How to read it |
| --- | --- | --- |
| Laravel (web requests and jobs: exceptions, `Log::` calls) | `ocr-app/storage/logs/laravel.log` | `tail -f ocr-app/storage/logs/laravel.log` or `docker compose exec app php artisan pail` |
| Queue worker (which job ran, DONE / FAIL, time) | container output | `docker compose logs -f worker` |
| OCR engines (model load, requests, Python tracebacks) | container output | `docker compose logs -f ocr-paddle ocr-ndl` |
| nginx access and errors (404, 413, 502) | container output | `docker compose logs -f web` |
| PHP-FPM | container output | `docker compose logs -f app` |
| Everything at once | — | `docker compose logs -f --tail=50` |

**Useful options**
- `docker compose logs --since=10m worker` shows only recent output.
- `php artisan pail --filter="ProcessDocumentJob"` filters the tailed logs.
- `php artisan pail -vv` shows the full stack traces.

**Per document, without logs**
- An OCR or processing error is saved on the document itself (`documents.error_message`, e.g. `E-OCR-01: …`). It is shown on its detail page.
- Every OCR run is stored in `ocr_runs` (FR-11): engine, time, critical failures and `error_code`. The detail page lists them under 実行履歴.

**Log level and size**
- `LOG_LEVEL=debug` in development.
- `laravel.log` grows without limit (`LOG_STACK=single`). Empty it with `: > ocr-app/storage/logs/laravel.log`, or switch to `LOG_STACK=daily` to rotate it.

---

## 7. Debugging

### Look at the data: tinker

```bash
docker compose exec -e HOME=/tmp app php artisan tinker
```

Pass `-e HOME=/tmp`, because the container user's home directory is not writable and tinker fails without it. Examples:

```php
$d = App\Models\Document::latest('id')->first();
$d->only(['id', 'status', 'type', 'error_message']);
$d->ocrRuns->map->only(['id', 'engine', 'critical_failures', 'elapsed_ms', 'error_code']);
$d->ocrRuns->last()->text();                       // the text the engine read
$d->checks()->get(['rule_code', 'level', 'message']);  // V-xx / A-xx results
App\Models\ImportProfile::pluck('name', 'id');     // attendance templates
```

The non-interactive form for scripts is:

```bash
docker compose exec -T -e HOME=/tmp app php artisan tinker --execute 'echo App\Models\Document::count();'
```

### Call an engine directly

The engines have no host ports, so call them from inside the network:

```bash
docker compose exec app curl -s http://ocr-ndl:8000/health
docker compose exec app curl -s -F file=@storage/app/samples/レシート３.jpeg http://ocr-ndl:8000/ocr | head -c 600
```

`ocr:run` does the same through Laravel's client (retries, error codes) and prints a table.

### Re-run a document

On the detail page, use 「再実行」 and pick the engine. The job runs again for that engine only. For a job stuck in `processing`, see [Troubleshooting](#8-troubleshooting).

### Step through PHP with Xdebug

Xdebug is not installed; `dump()` / `dd()`, `Log::debug()` and `pail` are usually enough. If you need breakpoints, add `xdebug` to `install-php-extensions` in `docker/php/Dockerfile` and these settings to `docker/php/php.ini`:

```ini
xdebug.mode=debug
xdebug.client_host=host.docker.internal
xdebug.start_with_request=trigger
```

Then rebuild with `docker compose up -d --build app worker`. Remove it again afterwards: it slows every request.

### Debug an engine container

```bash
docker compose exec ocr-ndl python -c "import main; print(main.VERSION)"
docker compose exec ocr-ndl pytest -q -x -k contract    # stop at the first failure
docker compose run --rm --no-deps ocr-ndl bash          # a shell in a fresh container
```

`engines/common/server.py` holds the HTTP contract. Each `engines/ocr-*/main.py` only has `load()` (load the model) and `recognize()` (read one image).

---

## 7a. Google Cloud Vision (optional engine)

`ocr-cloud` sends the preprocessed image to Google Cloud Vision (`DOCUMENT_TEXT_DETECTION`). It is **off by default**, because images leave the machine (NFR-09). The first 1,000 images a month are free; after that it costs $1.50 per 1,000.

**Turn it on**

1. Put the API key in **`ocr/.env`** (the repository root; copy `.env.example`). This file is git-ignored, and only the `ocr-cloud` container sees it. Do not put the key in `ocr-app/.env`.
   ```dotenv
   GOOGLE_VISION_API_KEY=AIza...
   ```
2. Start the engine. It is in the compose profile `cloud`, so a plain `docker compose up -d` never starts it.
   ```bash
   docker compose --profile cloud up -d --build ocr-cloud
   docker compose exec app curl -s http://ocr-cloud:8000/health    # {"engine":"cloud",...,"ready":true}
   ```
3. Enable it in `ocr-app/.env`, then run `docker compose restart app worker`:
   ```dotenv
   OCR_CLOUD_ENABLED=true
   OCR_FALLBACK=paddle,ndl,cloud     # optional: let uploads use it, last, only when the free engines fail a check
   OCR_CLOUD_MONTHLY_LIMIT=1000      # uploads stop using it after this many successful runs this month
   ```

**Try it**

None of these save anything; each image uses one Vision unit:

```bash
docker compose exec app php artisan ocr:run storage/app/samples/レシート６.jpeg --engine=cloud
docker compose exec app php artisan ocr:compare storage/app/samples --engines=paddle,ndl,cloud
```

In the browser, 「再実行」 on a document offers `cloud` once it is enabled.

**Turn it off**

1. Set `OCR_CLOUD_ENABLED=false` and run `docker compose restart app worker`.
2. Run `docker compose --profile cloud stop ocr-cloud`.

**Limit the cost on Google's side**

The app's monthly limit only counts uploads. `ocr:run` and `ocr:compare` don't count. The real guarantee is on Google's side:
- Cloud Console → Cloud Vision API → **Quotas**: set requests per day, e.g. 30.
- **Billing → Budgets & alerts**: add a budget alert.

**Errors**

A wrong or restricted key shows as `engine_error` with Google's status, e.g. `Vision API 403 PERMISSION_DENIED: API key not valid`. The key itself never appears in messages or logs.

**Tests**

`docker compose --profile cloud run --rm --no-deps -e GOOGLE_VISION_API_KEY= ocr-cloud pytest -q` runs the mapping tests against a fake Vision API. The shared contract tests are skipped without a key; with one, they cost about 10 units.

## 8. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Upload stays at 処理中 (processing) | Worker not running or crashed | `docker compose ps worker`, `docker compose logs worker`, `php artisan queue:failed`; then `docker compose restart worker` and 「再実行」 |
| `E-OCR-01` engine unreachable | Engine still loading (503 `not_ready`) or stopped | `docker compose ps` until `(healthy)`; `docker compose logs ocr-paddle` |
| OCR takes over a minute | PaddleOCR emulated on Apple Silicon | Expected; ndl is about 5 s. Keep `OCR_TIMEOUT=200` |
| The same document is processed twice | Timeouts do not nest | See [Timeouts](#timeouts) |
| 419 Page Expired | Stale page or session (often after a restart) | Reload the page and log in again |
| 502 Bad Gateway | `app` container down | `docker compose up -d app` |
| 413 on upload | File over the PHP or nginx limit | Limits: 20 MB per file (Laravel), 25 MB (`php.ini`), 110 MB per request (nginx) |
| `.env` change has no effect | Container not restarted, or config cached | `docker compose restart app worker`; `php artisan optimize:clear` |
| Japanese text missing from a PDF | `poppler-data` not in the PHP image | It is in `docker/php/Dockerfile`; rebuild `app` and `worker` |
| Attendance import: 「このファイルに合うテンプレートがありません。」 | No template fits, and no date column was found | Create a template from the file on the テンプレート page |
| Phone cannot open the site | `PHONE_URL` wrong, or the Mac's IP changed | See [Using it from a phone](#using-it-from-a-phone) |
| Tests stop with "Tests stopped: they would use the database …" | Config is cached, or compose injects env vars. The guard in `tests/TestCase.php` stops the run before RefreshDatabase can wipe the dev database (this happened once). | `php artisan config:clear`; remove any `env_file` |
| `tinker`: "Writing to directory /var/www/.config/psysh is not allowed" | Container home not writable | Add `-e HOME=/tmp` |
| ocr-paddle crashes on model load | Running as arm64 | Keep `platform: linux/amd64` and `enable_mkldnn=False` |

**Starting over with an empty database** (deletes all documents):

```bash
docker compose exec app php artisan migrate:fresh
rm -rf ocr-app/storage/app/private/documents ocr-app/storage/app/private/imports
docker compose exec app php artisan user:token you@example.com --password='…'
```

---

## 9. Where things are

```
ocr/
├── docker-compose.yml              all services
├── docker/php/                     PHP image (Dockerfile, php.ini)
├── docker/nginx/default.conf
├── engines/
│   ├── common/                     server.py (contract), preprocess.py, shared tests
│   ├── ocr-paddle/                 Dockerfile, main.py, requirements.txt, tests/
│   ├── ocr-cloud/                  Google Cloud Vision over REST (optional, profile "cloud")
│   └── ocr-ndl/                    Dockerfile (NDLOCR-Lite at a pinned commit), main.py, tests/
├── docs/
│   ├── development.md              this guide
│   └── spec/                       specification (source of truth)
└── ocr-app/                        Laravel
    ├── app/Ocr/                    OcrManager, HttpOcrEngine (engine client)
    ├── app/Jobs/ProcessDocumentJob.php
    ├── app/Processing/             routing, PDF text, classify, DocumentAnalyzer
    ├── app/Extraction/             JapaneseNormalizer, RuleExtractor
    ├── app/Validation/             V-01..V-10, A-01..A-08
    ├── app/Attendance/             templates, parsers, importer
    ├── app/Console/Commands/       ocr:run, ocr:compare, user:token
    ├── config/ocr.php              engines, fallback, timeouts
    ├── lang/ja.json                Japanese UI texts
    ├── storage/app/private/        uploaded files (never public)
    ├── storage/logs/laravel.log
    └── tests/                      Pest (Feature, Unit, fixtures)
```

<?php

use App\Models\AttendanceSheet;
use App\Models\Document;
use App\Models\ImportProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function csvUpload(array $rows, string $name = 'kintai-2026-10.csv'): UploadedFile
{
    return new UploadedFile(kintaiCsv($rows), $name, 'text/csv', null, true);
}

it('previews a CSV with checks and the month, then imports it in one go', function () {
    $this->actingAs($this->user);

    $response = $this->post('/attendance/import', ['file' => csvUpload(kintaiRows())]);
    $preview = $response->headers->get('Location');
    expect($preview)->toContain('/attendance/import/u-');

    $this->get($preview)
        ->assertOk()
        ->assertSee(['山田 花子', 'E001', '2026-10-01 〜 2026-10-31', '162:00', 'スポーツの日', 'A-05', 'A-06'])
        ->assertSee('<tr class="holiday"', false)
        ->assertSee('class="holiday-name"', false)
        ->assertDontSee('name="acknowledged"', false);
    expect(AttendanceSheet::count())->toBe(0);                                   // nothing saved by the preview

    $key = basename(parse_url($preview, PHP_URL_PATH));
    $this->post("/attendance/import/{$key}")->assertRedirect()->assertSessionHas('status', 'Imported 1 attendance sheet.');

    $sheet = AttendanceSheet::with('records', 'checks')->sole();
    expect($sheet->only(['employee_name', 'employee_code', 'worked_minutes', 'overtime_minutes', 'late_night_minutes', 'scheduled_days']))
        ->toBe(['employee_name' => '山田 花子', 'employee_code' => 'E001', 'worked_minutes' => 9720, 'overtime_minutes' => 360, 'late_night_minutes' => 30, 'scheduled_days' => 21])
        ->and($sheet->records)->toHaveCount(31)
        ->and($sheet->records[11]->only(['day_type', 'holiday_name']))->toBe(['day_type' => 'holiday', 'holiday_name' => 'スポーツの日'])
        ->and($sheet->checks->pluck('level', 'rule_code')->only(['A-01', 'A-05', 'A-06'])->all())->toBe(['A-01' => 'pass', 'A-05' => 'pass', 'A-06' => 'pass'])
        ->and($sheet->document->only(['type', 'route', 'status']))->toBe(['type' => 'attendance', 'route' => 'spreadsheet', 'status' => 'confirmed']);
    Storage::disk('local')->assertExists($sheet->document->file_path);
    expect(Storage::disk('local')->files('imports'))->toBe([]);                  // the upload is not kept twice

    $this->get("/documents/{$sheet->document_id}")->assertSee(['山田 花子', '162:00', 'スポーツの日']);
    $this->get('/attendance')->assertSee(['山田 花子', '162:00']);
});

it('asks before replacing an earlier import of the same employee and month', function () {
    $this->actingAs($this->user);
    $import = function (array $data = []) {
        $location = $this->post('/attendance/import', ['file' => csvUpload(kintaiRows())])->headers->get('Location');

        return $this->post('/attendance/import/'.basename(parse_url($location, PHP_URL_PATH)), $data);
    };

    $import();
    $first = AttendanceSheet::sole();

    $import()->assertSessionHasErrors('replace');
    $import(['replace' => '1'])->assertSessionHas('status');

    expect(AttendanceSheet::count())->toBe(1)
        ->and(AttendanceSheet::sole()->id)->not->toBe($first->id)
        ->and(Document::count())->toBe(2);                                      // the old document is kept for history
});

it('imports critical findings only after an acknowledgement', function () {
    $this->actingAs($this->user);
    $rows = kintaiRows();
    $rows[2][7] = '09:00';                                                       // 10/2: 9 h, but the 合計 row says 162:00 → A-05
    $location = $this->post('/attendance/import', ['file' => csvUpload($rows)])->headers->get('Location');
    $key = basename(parse_url($location, PHP_URL_PATH));

    $this->get($location)->assertSee('name="acknowledged"', false)->assertSee('労働時間');
    $this->post("/attendance/import/{$key}")->assertSessionHasErrors('acknowledged');
    $this->post("/attendance/import/{$key}", ['acknowledged' => '1'])->assertSessionHas('status');

    expect(AttendanceSheet::sole()->checks->firstWhere('rule_code', 'A-05')->level)->toBe('critical');
});

it('saves a template from the file itself when no template fits', function () {
    $this->actingAs($this->user);
    $rows = kintaiRows();
    $rows[0][2] = '年月日';                                                     // the date column has another title
    $location = $this->post('/attendance/import', ['file' => csvUpload($rows)])->headers->get('Location');

    $this->get($location)->assertOk()
        ->assertSee(['the new template', '山田 花子', '162:00', 'Check or edit the template', 'Delete it and set up by hand']);
    $created = ImportProfile::latest('id')->first();
    expect($created->settings['columns'][2])->toMatchArray(['title' => '年月日', 'role' => 'work_date'])
        ->and(ImportProfile::count())->toBe(3);

    // The next file of this format uses it: no second template.
    $next = $this->post('/attendance/import', ['file' => csvUpload($rows)])->headers->get('Location');
    $this->get($next)->assertOk()->assertDontSee('the new template')->assertSee("selected>{$created->name}", false);
    expect(ImportProfile::count())->toBe(3);
});

it('offers to create a template by hand when no column holds the date', function () {
    $this->actingAs($this->user);
    $rows = kintaiRows();
    $rows[0][2] = 'Datum';                                                      // a title no suggestion knows
    $location = $this->post('/attendance/import', ['file' => csvUpload($rows)])->headers->get('Location');

    $this->get($location)->assertOk()
        ->assertSee(['No template fits this file.', 'Create a template from this file'])
        ->assertSee(route('templates.design', basename(parse_url($location, PHP_URL_PATH))), false);
    expect(ImportProfile::count())->toBe(2);
});

it('imports a CSV that came in through the normal upload', function () {
    $this->actingAs($this->user);
    Storage::disk('local')->put('documents/k.csv', file_get_contents(kintaiCsv(kintaiRows())));
    $document = Document::factory()->for($this->user)->status('extracted')
        ->create(['type' => 'attendance', 'route' => 'spreadsheet', 'file_path' => 'documents/k.csv', 'original_name' => 'kintai-2026-10.csv', 'mime_type' => 'text/csv']);

    $this->get("/documents/{$document->id}")->assertSee('Import as attendance');
    $this->get("/attendance/import/d-{$document->id}")->assertOk()->assertSee('山田 花子');
    $this->post("/attendance/import/d-{$document->id}")->assertRedirect("/documents/{$document->id}");

    expect($document->fresh()->status)->toBe('confirmed')
        ->and($document->attendanceSheets()->count())->toBe(1);

    $other = Document::factory()->create();
    $this->get("/attendance/import/d-{$other->id}")->assertNotFound();
});

it('imports the real 勤務データ PDF', function () {
    $this->actingAs($this->user);
    $pdf = new UploadedFile(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf'), 'kintai.pdf', 'application/pdf', null, true);
    $location = $this->post('/attendance/import', ['file' => $pdf])->headers->get('Location');

    $this->get($location)->assertSee([samplePdfName(), '168:00', 'スポーツの日']);
    $this->post('/attendance/import/'.basename(parse_url($location, PHP_URL_PATH)))->assertSessionHas('status');

    expect(AttendanceSheet::sole()->only(['employee_name', 'scheduled_minutes', 'paid_leave_remaining_days']))
        ->toBe(['employee_name' => samplePdfName(), 'scheduled_minutes' => 10080, 'paid_leave_remaining_days' => '26.0']);
})->skip(fn () => ! hasSamplePdf(), 'needs poppler, the sample PDF and its git-ignored .json');

it('imports through the API, with a dry run first', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/attendance/import', ['file' => csvUpload(kintaiRows()), 'dry_run' => true])
        ->assertOk()
        ->assertJsonPath('data.sheets.0.employee_name', '山田 花子')
        ->assertJsonPath('data.sheets.0.summary.worked_minutes', 9720)
        ->assertJsonPath('data.sheets.0.checks.4.rule_code', 'A-05');
    expect(AttendanceSheet::count())->toBe(0);

    $this->postJson('/api/attendance/import', ['file' => csvUpload(kintaiRows())])
        ->assertCreated()
        ->assertJsonPath('sheets.0.worked_minutes', 9720);
    $this->postJson('/api/attendance/import', ['file' => csvUpload(kintaiRows())])->assertUnprocessable()->assertJsonValidationErrors('replace');
    $this->getJson('/api/import-profiles')->assertJsonFragment(['name' => 'kintai-standard'])->assertJsonFragment(['name' => '勤務データ PDF']);
});

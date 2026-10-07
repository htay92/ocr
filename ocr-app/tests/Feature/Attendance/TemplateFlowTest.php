<?php

use App\Attendance\TemplateDesigner;
use App\Models\AttendanceSheet;
use App\Models\ImportProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function xlsxUpload(string $path, string $name): UploadedFile
{
    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** The design form as the page posts it, from the page's own suggestions. */
function designForm(array $settings, array $extra = []): array
{
    return [
        ...$settings,
        'columns' => array_map(fn ($c) => [...$c, 'include' => $c['include'] ? '1' : null], $settings['columns']),
        'summary' => array_map(fn ($i) => [...$i, 'include' => '1'], $settings['summary']),
        'work_types' => array_map(fn ($v) => $v ?? '', $settings['work_types']),
        ...$extra,
    ];
}

it('new format: a template is saved from the file → check and edit it → import continues', function () {
    // 1. Import a 作業報告書: no template fits yet, so one is made from the file and saved.
    $location = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), '作業報告書_田中(11月).xlsx')])->headers->get('Location');
    $key = basename(parse_url($location, PHP_URL_PATH));
    $this->get($location)->assertOk()->assertSee(['the new template', '作業報告書', '田中', '144:15']);
    $created = ImportProfile::firstWhere('name', '作業報告書');
    expect($created)->not->toBeNull();

    // 2. "Check or edit": the setting page shows the file with the saved settings.
    $page = $this->get("/attendance/templates/design/{$key}?template={$created->id}")->assertOk()
        ->assertSee(['就業日', '稼働時間', '作業内容', '「稼働」', '「休日」', 'value="A1"', 'value="C1"', 'value="A4"'], false)
        ->assertSee('<option value="hours" selected>', false);
    $settings = app(TemplateDesigner::class)->analyse(Storage::disk('local')->path('imports/'.substr($key, 2)), 'x.xlsx')['settings'];

    // 3. Test with the sample: the usual preview with the format's own columns.
    $this->post("/attendance/templates/design/{$key}", designForm($settings, ['name' => '作業報告書 A社', 'break_default' => '1:00', 'template_id' => $created->id, 'action' => 'test']))
        ->assertOk()
        ->assertSee(['Test result', '田中', '2025-11-01 〜 2025-11-30', '144:15', '文化の日', 'システム開発'])
        ->assertDontSee('<th class="num">時間外</th>', false);
    expect($created->fresh()->name)->toBe('作業報告書');                       // a test saves nothing

    // 4. Save (renames the saved template): back to the import, now read with it.
    $this->post("/attendance/templates/design/{$key}", designForm($settings, ['name' => '作業報告書 A社', 'break_default' => '1:00', 'template_id' => $created->id, 'action' => 'save']))
        ->assertRedirect(route('attendance.preview', ['key' => $key, 'profile' => $created->id]));
    expect($created->fresh()->name)->toBe('作業報告書 A社')->and(ImportProfile::count())->toBe(3);
    $this->post("/attendance/import/{$key}", ['profile_id' => $created->id])->assertSessionHas('status');

    $sheet = AttendanceSheet::sole();
    expect($sheet->employee_name)->toBe('田中')
        ->and($sheet->worked_minutes)->toBe(8655)
        ->and($sheet->overtime_minutes)->toBeNull()                            // the format has no such column
        ->and(array_column($sheet->columns, 'title'))->toBe(['就業日', '区分', '始業時間', '終業時間', '稼働時間', '備考', '作業内容'])
        ->and($sheet->records()->where('work_date', '2025-11-12')->first()->values)->toMatchArray(['c6' => 495, 'c7' => '客先作業', 'c8' => 'システム開発'])
        ->and(collect($sheet->summary_values)->pluck('value', 'title')->all())->toMatchArray(['休日日数（日）' => 12]);

    $this->get("/documents/{$sheet->document_id}")->assertSee(['稼働時間', '作業内容', 'システム開発', '休日日数（日）']);

    // 5. Next month's file of the same format: the template is picked automatically.
    $next = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx('鈴木'), '作業報告書_鈴木(11月).xlsx')])->headers->get('Location');
    $this->get($next)->assertSee(['鈴木', '144:15'])->assertSee('selected>作業報告書 A社', false);
});

it('picks the right template among several', function () {
    shukkinboTemplate();
    sagyouTemplate();

    $shukkinbo = $this->post('/attendance/import', ['file' => xlsxUpload(shukkinboXlsx(), '出勤簿.xlsx')])->headers->get('Location');
    $sagyou = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), '作業報告書.xlsx')])->headers->get('Location');
    $csv = $this->post('/attendance/import', ['file' => new UploadedFile(kintaiCsv(kintaiRows()), 'k.csv', 'text/csv', null, true)])->headers->get('Location');

    $this->get($shukkinbo)->assertSee('selected>出勤簿（自動計算）', false);
    $this->get($sagyou)->assertSee('selected>作業報告書', false);
    $this->get($csv)->assertSee('selected>kintai-standard', false);
});

it('asks for the employee name when the file does not contain it', function () {
    shukkinboTemplate();
    $location = $this->post('/attendance/import', ['file' => xlsxUpload(shukkinboXlsx(), '出勤簿.xlsx')])->headers->get('Location');
    $key = basename(parse_url($location, PHP_URL_PATH));

    $this->get($location)->assertSee(['name="employee_name"', 'disabled'], false);
    $this->post("/attendance/import/{$key}")->assertSessionHasErrors('employee_name');

    $this->get("{$location}?employee_name=山田 花子")->assertSee('山田 花子')->assertSee('29:00');
    $this->post("/attendance/import/{$key}", ['employee_name' => '山田 花子'])->assertSessionHas('status');
    expect(AttendanceSheet::sole()->only(['employee_name', 'worked_minutes', 'late_night_minutes']))
        ->toBe(['employee_name' => '山田 花子', 'worked_minutes' => 1740, 'late_night_minutes' => 120]);
});

it('lists templates and edits one from a new sample file', function () {
    $profile = sagyouTemplate();

    $this->get('/attendance/templates')->assertSee(['作業報告書', '勤務データ PDF', 'kintai-standard', '就業日 · 区分 · 始業時間']);

    $location = $this->post('/attendance/templates/sample', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), 'x.xlsx'), 'template' => $profile->id])->headers->get('Location');
    $this->get($location)->assertSee(['value="作業報告書"', 'value="1:00"'], false);              // the saved settings are shown
});

it('manages templates through the API', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/import-profiles', ['name' => 'B社', 'kind' => 'spreadsheet', 'header_row' => 2, 'settings' => [
        'columns' => [['title' => '日付', 'type' => 'date', 'role' => 'work_date'], ['title' => '現場', 'type' => 'text', 'role' => null]],
    ]])->assertCreated()->assertJsonPath('data.settings.columns.1.title', '現場');
    $this->postJson('/api/import-profiles', ['name' => 'C社', 'settings' => ['columns' => [['title' => 'x', 'role' => 'bogus']]]])->assertUnprocessable();

    $this->deleteJson('/api/import-profiles/'.ImportProfile::firstWhere('name', 'B社')->id)->assertNoContent();
    expect(ImportProfile::where('name', 'B社')->exists())->toBeFalse();
});

it('deletes a template; attendance imported with it stays', function () {
    $profile = sagyouTemplate();
    $location = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), '作業報告書.xlsx')])->headers->get('Location');
    $this->post('/attendance/import/'.basename(parse_url($location, PHP_URL_PATH)))->assertSessionHas('status');
    $sheet = AttendanceSheet::sole();

    $this->get('/attendance/templates')->assertSee(route('templates.destroy', $profile), false);
    $this->delete("/attendance/templates/{$profile->id}")->assertRedirect('/attendance/templates')->assertSessionHas('status');

    expect(ImportProfile::find($profile->id))->toBeNull()
        ->and($sheet->fresh()->import_profile_id)->toBeNull()
        ->and($sheet->records()->count())->toBe(30);
    $this->get("/documents/{$sheet->document_id}")->assertOk()->assertSee(['稼働時間', '作業内容', 'システム開発', '144:15']);
});

it('deletes an auto-saved template from the preview and opens the setting page', function () {
    $location = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), '作業報告書.xlsx')])->headers->get('Location');
    $key = basename(parse_url($location, PHP_URL_PATH));
    $this->get($location)->assertSee('the new template');
    $created = ImportProfile::firstWhere('name', '作業報告書');

    $this->delete("/attendance/templates/{$created->id}", ['key' => $key])->assertRedirect(route('templates.design', $key));
    expect(ImportProfile::find($created->id))->toBeNull();
    $this->get("/attendance/templates/design/{$key}")->assertOk()->assertSee('就業日');
});

it('names auto-saved templates uniquely and keeps none that cannot read the file', function () {
    ImportProfile::create(['name' => '作業報告書', 'kind' => 'pdf', 'settings' => ['columns' => []]]);   // the name is taken
    $location = $this->post('/attendance/import', ['file' => xlsxUpload(sagyouHoukokushoXlsx(), '作業報告書_田中(11月).xlsx')])->headers->get('Location');
    $this->get($location)->assertSee('作業報告書 (2)');
    expect(ImportProfile::where('name', '作業報告書 (2)')->exists())->toBeTrue();

    // A header and a date column, but no day rows: E-IMP-02, and the suggestion is not kept.
    $count = ImportProfile::count();
    $empty = new UploadedFile(kintaiCsv([['日付', '開始', '終了', 'メモ欄']]), 'empty.csv', 'text/csv', null, true);
    $this->get($this->post('/attendance/import', ['file' => $empty])->headers->get('Location'))->assertSee('E-IMP-02');
    expect(ImportProfile::count())->toBe($count);
});

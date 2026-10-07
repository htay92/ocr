<?php

use App\Attendance\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Attendance templates: one per company format, with the format's own columns.
// - import_profiles: kind (spreadsheet | pdf), settings (columns, cells, units, work-type words…),
//   match (what identifies files of this format)
// - attendance_sheets.columns: the template's columns at import time; summary_values: the file's own summary items
// - attendance_records.values: every column's value by key
// - standard fields become nullable: null = this format has no such column
return new class extends Migration
{
    private const SHEET_MINUTES = ['scheduled', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'];

    private const RECORD_MINUTES = ['break', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'];

    public function up(): void
    {
        Schema::table('import_profiles', function (Blueprint $table) {
            $table->string('kind', 20)->default('spreadsheet');
            $table->json('settings')->nullable();
            $table->json('match')->nullable();
            $table->json('column_map')->nullable()->change();
        });

        Schema::table('attendance_sheets', function (Blueprint $table) {
            $table->json('columns')->nullable();
            $table->json('summary_values')->nullable();
            foreach (self::SHEET_MINUTES as $kind) {
                $table->unsignedInteger("{$kind}_minutes")->nullable()->default(null)->change();
            }
            $table->unsignedSmallInteger('scheduled_days')->nullable()->default(null)->change();
            $table->decimal('worked_days', 5, 1)->nullable()->default(null)->change();
            $table->decimal('paid_leave_taken_days', 5, 1)->nullable()->default(null)->change();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->json('values')->nullable();
            foreach (self::RECORD_MINUTES as $kind) {
                $table->unsignedSmallInteger("{$kind}_minutes")->nullable()->default(null)->change();
            }
        });

        // The existing CSV/Excel profile keeps its column_map; the 勤務データ PDF layout,
        // so far hard-coded, becomes a built-in template.
        DB::table('import_profiles')->where('name', 'kintai-standard')->update(['kind' => 'spreadsheet']);
        DB::table('import_profiles')->insert([
            'name' => '勤務データ PDF',
            'kind' => 'pdf',
            'encoding' => 'auto',
            'delimiter' => ',',
            'header_row' => 1,
            'settings' => json_encode(Template::kintaiPdfSettings(), JSON_UNESCAPED_UNICODE),
            'match' => json_encode(['anchors' => ['【勤務データ】'], 'headers' => ['日付', '勤務区分', '開始', '終了', '休憩時間', '労働時間']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('import_profiles')->where('kind', 'pdf')->delete();
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn('values'));
        Schema::table('attendance_sheets', fn (Blueprint $table) => $table->dropColumn(['columns', 'summary_values']));
        Schema::table('import_profiles', fn (Blueprint $table) => $table->dropColumn(['kind', 'settings', 'match']));
    }
};

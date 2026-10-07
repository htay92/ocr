<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Spec 3.3: import_profiles, attendance_sheets, attendance_records (milestone 6).
// Additions: attendance_sheets.summary_source (which summary values came from the
// file, for A-05/A-07) and validation_results.attendance_sheet_id (A-xx results).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('encoding', 20)->default('auto');
            $table->char('delimiter', 1)->default(',');
            $table->unsignedSmallInteger('header_row')->default(1);
            $table->json('column_map');
            $table->string('date_format', 20)->nullable();
            $table->string('time_format', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_name');
            $table->string('employee_code')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            foreach (['scheduled', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'] as $kind) {
                $table->unsignedInteger("{$kind}_minutes")->default(0);
            }
            $table->unsignedSmallInteger('scheduled_days')->default(0);
            $table->decimal('worked_days', 5, 1)->default(0);
            $table->decimal('paid_leave_taken_days', 5, 1)->default(0);
            $table->decimal('paid_leave_remaining_days', 5, 1)->nullable();
            $table->json('work_type_counts')->nullable();
            $table->json('summary_source')->nullable();
            $table->timestamps();

            $table->unique(['employee_name', 'period_start']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_sheet_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('day_type', 10);
            $table->string('holiday_name')->nullable();
            $table->string('work_type')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            foreach (['break', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'] as $kind) {
                $table->unsignedSmallInteger("{$kind}_minutes")->default(0);
            }
            $table->text('memo')->nullable();
            $table->boolean('confirmed')->default(false);
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();

            $table->unique(['attendance_sheet_id', 'work_date']);
        });

        Schema::table('validation_results', function (Blueprint $table) {
            $table->foreignId('attendance_sheet_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Default profile for Excel and CSV exports (spec 3.7).
        DB::table('import_profiles')->insert([
            'name' => 'kintai-standard',
            'encoding' => 'auto',
            'delimiter' => ',',
            'header_row' => 1,
            'column_map' => json_encode([
                'employee_name' => '氏名', 'employee_code' => '社員番号', 'work_date' => '日付', 'work_type' => '勤務区分',
                'start_time' => '開始', 'end_time' => '終了', 'break_minutes' => '休憩時間', 'worked_minutes' => '労働時間',
                'overtime_minutes' => '時間外', 'late_night_minutes' => '深夜', 'legal_holiday_minutes' => '法定休日',
                'legal_holiday_late_night_minutes' => '法定休日深夜', 'memo' => 'メモ', 'confirmed' => '確定',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('validation_results', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_sheet_id'));
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sheets');
        Schema::dropIfExists('import_profiles');
    }
};

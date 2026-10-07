<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Result of one validation rule (V-xx) for one OCR run of a document (spec 3.3, 3.5).
 */
class ValidationResult extends Model
{
    protected $fillable = ['document_id', 'ocr_run_id', 'attendance_sheet_id', 'rule_code', 'level', 'message'];
}

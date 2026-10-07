<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptLine extends Model
{
    protected $fillable = ['line_no', 'name', 'quantity', 'unit_price', 'amount', 'tax_rate', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }
}

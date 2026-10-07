<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptTax extends Model
{
    protected $fillable = ['tax_rate', 'taxable_amount', 'tax_amount'];
}

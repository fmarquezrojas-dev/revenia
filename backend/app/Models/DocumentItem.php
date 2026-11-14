<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(['line_number', 'quantity_millis', 'unit_price_cents', 'base_cents', 'igv_cents', 'total_cents'], 'integer');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

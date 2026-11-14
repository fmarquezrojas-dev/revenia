<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fiscal_snapshot' => 'array', 'attempt_count' => 'integer', 'can_retry' => 'boolean', 'lease_until' => 'datetime', 'next_attempt_at' => 'datetime', 'version' => 'integer', 'number' => 'integer', 'issue_date' => 'date', 'issued_at' => 'datetime', 'taxable_cents' => 'integer', 'exempt_cents' => 'integer', 'unaffected_cents' => 'integer', 'igv_cents' => 'integer', 'total_cents' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('line_number');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'series_id');
    }
}

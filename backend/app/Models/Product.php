<?php

namespace App\Models;

use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;
    use HasUuids;

    public const MAX_PRICE_CENTS = Money::MAX_CENTS;

    protected $fillable = ['code', 'description', 'unit_code', 'currency', 'unit_price_cents', 'tax_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'unit_price_cents' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

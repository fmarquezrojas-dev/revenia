<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSeries extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'document_series';

    protected $fillable = ['doc_type', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'current_number' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

<?php

namespace Modules\Properties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyListing extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'listed_on' => 'date',
            'last_seen_on' => 'date',
            'metadata' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PropertyMedia::class)->orderBy('position');
    }

    public function priceRecords(): HasMany
    {
        return $this->hasMany(PropertyPriceRecord::class);
    }
}

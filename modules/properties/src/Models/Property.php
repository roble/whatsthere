<?php

namespace Modules\Properties\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Property extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'floor_area_sqm' => 'float',
        ];
    }

    public function priceRecords(): HasMany
    {
        return $this->hasMany(PropertyPriceRecord::class);
    }

    public function askingPrice(): HasOne
    {
        return $this->hasOne(PropertyPriceRecord::class)->ofMany([
            'effective_date' => 'max', 'id' => 'max',
        ], function (Builder $query): void {
            $query->where('record_type', 'asking_price')->where('effective_date', '<=', today());
        });
    }

    public function listings(): HasMany
    {
        return $this->hasMany(PropertyListing::class);
    }

    public function activeListing(): HasOne
    {
        return $this->hasOne(PropertyListing::class)
            ->where('status', 'active')
            ->latest('last_seen_on');
    }
}

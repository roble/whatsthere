<?php

namespace Modules\Properties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyPriceRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        // Pinned to the date format rather than left as a bare `date` cast.
        // Without the format Eloquent writes a full timestamp on drivers with
        // no native date type, so the import's own `updateOrCreate` lookup by
        // effective_date misses and every re-import duplicates the history.
        return ['amount' => 'integer', 'effective_date' => 'date:Y-m-d'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PropertyListing::class, 'property_listing_id');
    }
}

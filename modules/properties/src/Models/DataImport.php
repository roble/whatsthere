<?php

namespace Modules\Properties\Models;

use Illuminate\Database\Eloquent\Model;

class DataImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}

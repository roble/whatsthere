<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingState extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'conversation_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'phase',
        'question_count',
        'current_question',
        'answers',
        'plan',
        'flow',
        'property_result_ids',
    ];

    /**
     * Mirrors the column defaults so a row created with firstOrCreate reads
     * the same in memory as it does after a reload. Without this the first
     * turn sees a null phase, offers no tools, and the interview never starts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'phase' => 'interviewing',
        'question_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'current_question' => 'array',
            'plan' => 'array',
            'property_result_ids' => 'array',
        ];
    }
}

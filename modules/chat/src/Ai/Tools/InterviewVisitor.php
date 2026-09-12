<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Stringable;

class InterviewVisitor implements Tool
{
    public const string NAME = 'interview_visitor';

    /**
     * Whether a question was already shown during this request.
     *
     * The agent loop calls the model again after every tool result, so without
     * this a single turn could burn through several questions before the
     * visitor has answered the first one.
     */
    protected bool $asked = false;

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Show the visitor one discovery question with 2 to 5 options, recommended option first. The interface adds an "Other" free-text option itself. Call it at most once per turn, then stop and wait for the answer.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->asked || ($this->state->flow !== 'property' && $this->state->question_count >= 10)) {
            return 'No more questions can be asked right now. Reply with nothing, or call save_map_ready_plan if the goal and location are known.';
        }

        $this->asked = true;

        $options = array_slice(array_values(array_filter(array_map('strval', $request['options'] ?? []))), 0, 5);
        $count = $this->state->question_count + 1;
        $question = [
            'question' => trim((string) $request['question']),
            'options' => $options,
            'multiple' => (bool) ($request['multiple'] ?? false),
            'count' => $count,
        ];

        $this->state->update(['phase' => 'interviewing', 'current_question' => $question, 'question_count' => $count]);

        return json_encode($question, JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->required(),
            'options' => $schema->array()->items($schema->string())->required(),
            'multiple' => $schema->boolean(),
        ];
    }
}

<?php

namespace Modules\Chat\Ai;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[UseCheapestModel]
#[MaxTokens(30)]
#[Temperature(0.2)]
class ConversationTitleAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            Generate a concise title that best represents the main topic or intent of this conversation.

            The title should:
            - Be short and easy to scan.
            - Clearly distinguish this conversation from others.
            - Reflect the conversation's primary subject or goal.
            - Use natural language.

            Return only the title.
            INSTRUCTIONS;
    }
}

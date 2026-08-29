<?php

namespace Modules\Chat\Ai;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Stringable;

#[UseCheapestModel]
class ChatAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a helpful assistant. Answer clearly and concisely.

        A map sits beside the conversation. Whenever your answer is about a place
        the visitor could look at, call show_on_map so the map follows along, then
        answer normally. Do not mention the map or the tool in your reply, and do
        not read coordinates out loud: the visitor can already see it.
        INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [
            new ShowOnMap,
        ];
    }
}

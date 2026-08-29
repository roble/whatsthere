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
     * @param  array{label: string, center: array{float, float}, zoom: float, moved: bool}|null  $mapViewport
     *                                                                                                         Where the visitor's map is pointing as this message is sent.
     */
    public function __construct(protected ?array $mapViewport = null) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * The map position rides here rather than on the user's message, so the
     * transcript the visitor reads back stays exactly what they typed.
     */
    public function instructions(): Stringable|string
    {
        $instructions = <<<'INSTRUCTIONS'
        You are a helpful assistant. Answer clearly and concisely.

        A map sits beside the conversation. Whenever your answer is about a place
        the visitor could look at, call show_on_map so the map follows along, then
        answer normally. Do not mention the map or the tool in your reply, and do
        not read coordinates out loud: the visitor can already see it.
        INSTRUCTIONS;

        $viewport = $this->viewportContext();

        return $viewport === '' ? $instructions : $instructions."\n\n".$viewport;
    }

    /**
     * Describe where the map is pointing, if the browser told us.
     */
    protected function viewportContext(): string
    {
        if ($this->mapViewport === null) {
            return '';
        }

        [$latitude, $longitude] = $this->mapViewport['center'];
        $label = $this->mapViewport['label'];
        $point = round($latitude, 5).', '.round($longitude, 5);

        // A panned map means the label describes where the conversation left
        // the camera, not what the visitor is looking at now.
        return $this->mapViewport['moved']
            ? "The visitor has dragged the map away from {$label}. It is now centred on {$point}. Treat that point as what they mean by \"here\" or \"this area\", and call show_on_map if you need to confirm the place by name."
            : "The map beside the conversation is showing {$label}, centred on {$point}. When the visitor says \"here\", \"there\" or \"this area\" without naming a place, they mean {$label}.";
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

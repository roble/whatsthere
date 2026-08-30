import type { UIMessage } from 'ai';
import type { Component } from 'vue';
import {
    THOUGHT_KINDS,
    UNKNOWN_TOOL,
    type ThoughtBody,
    type ThoughtKind,
    type ThoughtPart,
} from './kinds';

export { THOUGHT_KINDS, UNKNOWN_TOOL } from './kinds';
export type { ThoughtBody, ThoughtKind, ThoughtPart } from './kinds';

/** One step of the chain, ready to render. */
export type Thought = {
    /** Stable within a message, so it can key the list and a test selector. */
    id: string;
    icon: Component;
    /** Translation key; pass through `$t(label, params)`. */
    label: string;
    params: Record<string, string>;
    description?: string;
    body?: ThoughtBody;
    status: 'active' | 'complete';
};

/**
 * A tool part is finished once its output has landed, either way; a reasoning
 * part once the model has stopped adding to it. Anything else is still running,
 * which is what puts the step in its active styling.
 */
function isComplete(part: ThoughtPart): boolean {
    return (
        part.state === 'output-available' ||
        part.state === 'output-error' ||
        part.state === 'done'
    );
}

/**
 * Finishing and succeeding are not the same thing.
 *
 * A tool that reports "could not find it" returns normally, so only the kind
 * itself knows whether the result was any use.
 */
function didSucceed(kind: ThoughtKind, part: ThoughtPart): boolean {
    return part.state !== 'output-error' && (kind.succeeded?.(part) ?? true);
}

/** Fill `:name` placeholders that have no matching parameter, e.g. an unknown tool. */
function toolName(type: string): string {
    return type.slice('tool-'.length).replace(/_/g, ' ');
}

/**
 * Turn one message's streamed parts into the steps to show beside it.
 *
 * Walked in order so reasoning and tool calls interleave the way they arrived:
 * the model thinks, acts, thinks again, and the chain reads as that sequence
 * rather than as two separate lists.
 */
export function thoughtsFor(parts: UIMessage['parts']): Thought[] {
    const thoughts: Thought[] = [];

    (parts as ThoughtPart[]).forEach((part, index) => {
        if (part.type === 'text') {
            return;
        }

        const isTool = part.type.startsWith('tool-');
        const kind: ThoughtKind | undefined =
            THOUGHT_KINDS[part.type] ?? (isTool ? UNKNOWN_TOOL : undefined);

        // Not every part is a thought -- a step for something with no way to
        // describe it is worse than leaving it out.
        if (!kind) {
            return;
        }

        const complete = isComplete(part);
        const settled = complete
            ? didSucceed(kind, part)
                ? kind.doneLabel
                : kind.failedLabel
            : undefined;

        thoughts.push({
            id: `${index}-${part.type}`,
            icon: kind.icon,
            label: settled ?? kind.label,
            params: {
                ...(isTool ? { tool: toolName(part.type) } : {}),
                ...kind.params?.(part),
            },
            description: kind.description?.(part),
            body: kind.body?.(part),
            status: complete ? 'complete' : 'active',
        });
    });

    return thoughts;
}

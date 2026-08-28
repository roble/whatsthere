import {
    computed,
    onScopeDispose,
    ref,
    shallowRef,
    toValue,
    watch,
    type MaybeRefOrGetter,
} from 'vue';
import type { WebMcpTool } from './types';

export type { WebMcpTool };

type Provider = () => WebMcpTool[];

const providers = shallowRef<Provider[]>([]);

/**
 * Whether the browser implements WebMCP at all. Chrome 151 exposes it on both
 * `document` and `navigator`; `document` is the surface the spec moved to.
 */
export const webMcpSupported =
    typeof document !== 'undefined' && 'modelContext' in document;

/** Set by the app shell so gated tools can be withheld while signed out. */
export const webMcpAuthenticated = ref(false);

/** Everything the page declares, available or not. The badge lists this. */
export const webMcpTools = computed<WebMcpTool[]>(() =>
    providers.value.flatMap((provider) => provider()),
);

/**
 * The subset actually handed to the browser right now.
 *
 * Support is part of the filter, not just a guard in the sync: on a browser
 * without WebMCP nothing is registered, so reporting tools as active there
 * would tell the visitor their agent can call things it cannot.
 */
export const webMcpActiveTools = computed<WebMcpTool[]>(() =>
    webMcpSupported
        ? webMcpTools.value.filter(
              (tool) => !tool.requiresAuth || webMcpAuthenticated.value,
          )
        : [],
);

/**
 * Offer tools for as long as the calling component is alive.
 *
 * Any component can call this -- a page for tools that need its local state, a
 * module's setup for tools that only need the router. Adding a capability later
 * means one more entry in the returned array and nothing else.
 */
export function useWebMcpTools(provider: MaybeRefOrGetter<WebMcpTool[]>): void {
    const get: Provider = () => toValue(provider);

    providers.value = [...providers.value, get];

    onScopeDispose(() => {
        providers.value = providers.value.filter(
            (candidate) => candidate !== get,
        );
    });
}

/**
 * The protocol wants `{ content: [...] }`. Letting tools return plain values
 * and wrapping here keeps every tool body to a single expression.
 */
function toContent(result: unknown): unknown {
    if (result && typeof result === 'object' && 'content' in result) {
        return result;
    }

    return {
        content: [
            {
                type: 'text',
                text:
                    typeof result === 'string'
                        ? result
                        : JSON.stringify(result ?? null),
            },
        ],
    };
}

/**
 * Keep the browser's tool list in step with `webMcpActiveTools`.
 *
 * Chrome ignores re-registering a name and offers no unregister, so the only
 * way to change the exposed set is to abort every prior registration and
 * declare them all again. Call this once, from the app shell.
 */
export function syncWebMcpTools(): void {
    if (!webMcpSupported) {
        return;
    }

    let controller: AbortController | null = null;

    watch(
        webMcpActiveTools,
        (tools) => {
            controller?.abort();
            controller = new AbortController();
            const { signal } = controller;

            for (const tool of tools) {
                document.modelContext
                    .registerTool(
                        {
                            name: tool.name,
                            description: tool.description,
                            inputSchema: tool.inputSchema,
                            annotations: {
                                readOnlyHint: tool.readOnly === true,
                            },
                            execute: async (args: Record<string, unknown>) =>
                                toContent(await tool.execute(args ?? {})),
                        },
                        { signal },
                    )
                    // Aborting a registration rejects its promise. That is the
                    // documented way to withdraw a tool, not a failure.
                    .catch((error: Error) => {
                        if (error?.name !== 'AbortError') {
                            console.error(
                                `WebMCP: failed to register "${tool.name}"`,
                                error,
                            );
                        }
                    });
            }
        },
        { immediate: true },
    );

    onScopeDispose(() => controller?.abort());
}

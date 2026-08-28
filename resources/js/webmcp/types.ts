/**
 * A capability this page offers to whatever AI agent the visitor is running.
 *
 * One definition drives everything: the browser registration, the badge's
 * listing, and the sign-in gating. There is deliberately no parallel metadata
 * array to keep in sync.
 */
export interface WebMcpTool {
    /** Unique, snake_case. Chrome ignores a second registration of the same name. */
    name: string;
    /** Written for a model, not a human: say when to call it and what comes back. */
    description: string;
    inputSchema: {
        type: 'object';
        properties?: Record<string, unknown>;
        required?: string[];
    };
    /** Safe to call speculatively. Anything that changes state must leave this false. */
    readOnly?: boolean;
    /** Only registered while someone is signed in; shown as locked otherwise. */
    requiresAuth?: boolean;
    /**
     * Return anything JSON-serialisable. Plain values are wrapped in the
     * protocol's content envelope for you.
     */
    execute: (args: Record<string, unknown>) => unknown | Promise<unknown>;
}

/**
 * Chrome 151 ships WebMCP without TypeScript lib definitions, so the shape we
 * actually rely on is declared here. Verified against the live API rather than
 * the spec text: registerTool returns a promise and takes an AbortSignal, and
 * aborting is the only way to withdraw a tool.
 */
declare global {
    interface ModelContext {
        registerTool(
            tool: {
                name: string;
                description: string;
                inputSchema: object;
                annotations?: Record<string, boolean>;
                execute: (args: Record<string, unknown>) => Promise<unknown>;
            },
            options?: { signal?: AbortSignal },
        ): Promise<void>;
        getTools(): Promise<Array<{ name: string; description: string }>>;
    }

    interface Document {
        readonly modelContext: ModelContext;
    }
}

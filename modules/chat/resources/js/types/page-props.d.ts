/**
 * Props the chat module shares on every Inertia response.
 *
 * Declared the same way the auth module declares its own, so `usePage()` sees
 * them everywhere without each call site restating the shape -- which is what
 * made those call sites fail the PageProps constraint.
 */
declare module '@inertiajs/core' {
    interface PageProps {
        chat?: {
            sessions: Array<{ id: string; title: string }>;
            retitle_at?: number[];
        };
    }
}

export {};

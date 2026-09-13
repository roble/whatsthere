import type { WebMcpTool } from '@/webmcp';
import { router } from '@inertiajs/vue3';

function openAuthPage(routeName: 'login' | 'register'): {
    destination: string;
    status: string;
} {
    const destination = route(routeName);

    router.visit(destination);

    return {
        destination,
        status: `Opening the ${routeName === 'login' ? 'sign-in' : 'registration'} page. The visitor must complete the form.`,
    };
}

/** Authentication entry points available to a browser agent while signed out. */
export function guestAuthTools(registrationEnabled: boolean): WebMcpTool[] {
    const tools: WebMcpTool[] = [
        {
            name: 'open_login',
            description:
                "Open Whatsthere's sign-in page. Use when the visitor wants to log in or an authenticated tool is required. Never ask for or handle their credentials; the visitor completes the form.",
            inputSchema: { type: 'object', properties: {} },
            requiresGuest: true,
            execute: () => openAuthPage('login'),
        },
    ];

    if (registrationEnabled) {
        tools.push({
            name: 'open_signup',
            description:
                "Open Whatsthere's account registration page. Use when the visitor needs an account. Never ask for or handle their credentials; the visitor completes the form.",
            inputSchema: { type: 'object', properties: {} },
            requiresGuest: true,
            execute: () => openAuthPage('register'),
        });
    }

    return tools;
}

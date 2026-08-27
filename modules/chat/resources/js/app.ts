// import { registerIcon } from '@/lib/navigation';
// import IconExample from '~icons/lucide/example';

import '@modules/chat/resources/css/style.css';

/**
 * Chat module setup
 * Called during app initialization before mounting
 */
export function setup() {
    console.debug('Chat module loaded');

    // Register icons for navigation items defined in routes/navigation.php
    // registerIcon('chat', IconExample);
}

/**
 * Chat module after mount logic
 * Called after the app has been mounted
 */
export function afterMount() {
    console.debug('Chat module after mount logic executed');
}

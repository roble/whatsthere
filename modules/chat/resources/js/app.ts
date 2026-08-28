import ChatSessions from '@modules/chat/resources/js/components/ChatSessions.vue';
import { registerGlobalComponent } from '@/lib/globalComponents';
import { registerIcon } from '@/lib/navigation';
import IconMessageCircle from '~icons/lucide/message-circle';

import '@modules/chat/resources/css/style.css';

/**
 * Chat module setup
 * Called during app initialization before mounting
 */
export function setup() {
    // Session list and the new-chat shortcut, hung in the global sidebar.
    registerGlobalComponent('sidebar-content', ChatSessions);

    // Icon for navigation items defined in routes/navigation.php
    registerIcon('chat', IconMessageCircle);
}

/**
 * Chat module after mount logic
 * Called after the app has been mounted
 */
export function afterMount() {
    console.debug('Chat module after mount logic executed');
}

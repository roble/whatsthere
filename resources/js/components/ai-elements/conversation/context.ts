import type { InjectionKey, Ref } from 'vue';
import { inject } from 'vue';

export interface ConversationContextType {
    scrollRef: Ref<HTMLElement | null>;
    isAtBottom: Readonly<Ref<boolean>>;
    scrollToBottom: () => void;
}

export const ConversationKey: InjectionKey<ConversationContextType> =
    Symbol('Conversation');

export function useConversationContext(): ConversationContextType {
    const ctx = inject(ConversationKey);
    if (!ctx) {
        throw new Error(
            'Conversation components must be used within Conversation',
        );
    }
    return ctx;
}

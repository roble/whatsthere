<script setup lang="ts">
import type { ChatStatus } from 'ai';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSpeechButton,
    PromptInputSubmit,
    PromptInputTextarea,
    PromptInputTools,
    type PromptInputMessage,
} from '@/components/ai-elements/prompt-input';
import { Button } from '@/components/ui/button';
import type { MapMarker } from '@modules/chat/resources/js/map';
import PropertyPhoto from '@modules/chat/resources/js/components/PropertyPhoto.vue';
import { formatPrice } from '@modules/chat/resources/js/listing';
import IconX from '~icons/lucide/x';

defineProps<{
    selectedProperty: MapMarker | null;
    placeholder: string;
    composerStatus: ChatStatus;
}>();

const emit = defineEmits<{
    submit: [message: PromptInputMessage];
    clearSelected: [];
}>();
</script>

<template>
    <div class="chat-composer relative px-3 pt-2 pb-3">
        <div
            class="chat-composer__glow pointer-events-none absolute inset-x-6 top-0 h-24"
            aria-hidden="true"
        />

        <div
            v-if="selectedProperty"
            class="border-border/50 from-background/95 to-primary/5 relative mb-2 flex items-center gap-2.5 rounded-2xl border bg-gradient-to-r px-2 py-1.5 shadow-lg shadow-primary/10 backdrop-blur-xl"
            data-testid="selected-property-chip"
        >
            <span
                class="relative size-9 shrink-0 overflow-hidden rounded-xl ring-2 ring-red-500/70"
            >
                <PropertyPhoto
                    :images="selectedProperty.images ?? []"
                    :alt="selectedProperty.name"
                />
                <span
                    class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-red-500 ring-2 ring-background"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-semibold tracking-tight">
                    {{ selectedProperty.name }}
                </p>
                <p class="text-primary text-[11px] font-semibold">
                    {{ formatPrice(selectedProperty) }}
                </p>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                class="size-7 shrink-0 rounded-lg hover:bg-red-500/10"
                :aria-label="$t('Clear selected listing')"
                data-testid="clear-selected-property"
                @click="emit('clearSelected')"
            >
                <IconX class="size-3.5" />
            </Button>
        </div>

        <PromptInput
            class="chat-composer__input"
            data-testid="chat-form"
            @submit="emit('submit', $event)"
        >
            <PromptInputBody>
                <PromptInputTextarea
                    :placeholder="placeholder"
                    rows="1"
                    class="min-h-0 text-[15px] leading-relaxed"
                    data-testid="chat-input"
                />
            </PromptInputBody>
            <PromptInputFooter align="inline-end">
                <PromptInputTools>
                    <PromptInputSpeechButton
                        :aria-label="$t('Dictate a message')"
                        data-testid="chat-mic"
                    />
                    <PromptInputSubmit
                        :status="composerStatus"
                        class="chat-composer__submit"
                        data-testid="chat-submit"
                    />
                </PromptInputTools>
            </PromptInputFooter>
        </PromptInput>
    </div>
</template>

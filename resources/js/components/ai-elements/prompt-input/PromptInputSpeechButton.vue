<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { MicIcon, SquareIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';
import { cn } from '@/lib/utils';
import { onMounted, onUnmounted, ref } from 'vue';
import { usePromptInput } from './context';
import PromptInputButton from './PromptInputButton.vue';

interface SpeechRecognition extends EventTarget {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    start: () => void;
    stop: () => void;
    onstart: ((this: SpeechRecognition, ev: Event) => any) | null;
    onend: ((this: SpeechRecognition, ev: Event) => any) | null;
    onresult:
        | ((this: SpeechRecognition, ev: SpeechRecognitionEvent) => any)
        | null;
    onerror:
        | ((this: SpeechRecognition, ev: SpeechRecognitionErrorEvent) => any)
        | null;
}

interface SpeechRecognitionEvent extends Event {
    results: SpeechRecognitionResultList;
    resultIndex: number;
}

interface SpeechRecognitionResultList {
    readonly length: number;
    item: (index: number) => SpeechRecognitionResult;
    [index: number]: SpeechRecognitionResult;
}

interface SpeechRecognitionResult {
    readonly length: number;
    item: (index: number) => SpeechRecognitionAlternative;
    [index: number]: SpeechRecognitionAlternative;
    isFinal: boolean;
}

interface SpeechRecognitionAlternative {
    transcript: string;
    confidence: number;
}

interface SpeechRecognitionErrorEvent extends Event {
    error: string;
}

declare global {
    interface Window {
        SpeechRecognition: {
            new (): SpeechRecognition;
        };
        webkitSpeechRecognition: {
            new (): SpeechRecognition;
        };
    }
}

type PromptInputSpeechButtonProps = InstanceType<
    typeof PromptInputButton
>['$props'];

interface Props extends /* @vue-ignore */ PromptInputSpeechButtonProps {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();

const { textInput, setTextInput } = usePromptInput();
const isListening = ref(false);
const recognition = ref<SpeechRecognition | null>(null);

onMounted(() => {
    const Win = window as any;
    const SpeechRecognition =
        Win.SpeechRecognition || Win.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const sr = new SpeechRecognition();
        sr.continuous = true;
        sr.interimResults = true;
        sr.lang = document.documentElement.lang || 'en-US';

        // Whatever was already typed when dictation started. Results are
        // appended to this rather than to the live value, because the whole
        // utterance is rewritten on every event.
        // ponytail: typing while dictating gets overwritten on the next
        // result; track the caret instead if that turns out to matter.
        let baseText = '';

        sr.onstart = () => {
            isListening.value = true;
            baseText = textInput.value;
        };
        sr.onend = () => (isListening.value = false);

        /**
         * Continuous mode accumulates every result of the session, so the full
         * utterance is rebuilt each time. Interim results are included on
         * purpose: they are what makes words appear while you speak, instead of
         * the box staying empty until the provider finalises a segment.
         */
        sr.onresult = (event: SpeechRecognitionEvent) => {
            let transcript = '';
            for (let i = 0; i < event.results.length; i++) {
                transcript += event.results[i][0]?.transcript ?? '';
            }

            setTextInput(
                baseText + (baseText && transcript ? ' ' : '') + transcript,
            );
        };

        sr.onerror = (event: SpeechRecognitionErrorEvent) => {
            isListening.value = false;

            // Stopping on purpose reports as 'aborted'; that is not a failure.
            if (event.error !== 'aborted') {
                toast.error(trans('Dictation failed'), {
                    description: event.error,
                });
            }
        };

        recognition.value = sr;
    }
});

onUnmounted(() => {
    recognition.value?.stop();
});

function toggleListening() {
    if (!recognition.value) return;
    if (isListening.value) {
        recognition.value.stop();
    } else {
        recognition.value.start();
    }
}
</script>

<template>
    <PromptInputButton
        :disabled="!recognition"
        :aria-pressed="isListening"
        :class="
            cn(
                'relative transition-all duration-200',
                isListening &&
                    'bg-destructive text-destructive-foreground hover:bg-destructive hover:text-destructive-foreground dark:hover:bg-destructive animate-pulse',
                props.class,
            )
        "
        v-bind="props"
        @click="toggleListening"
    >
        <SquareIcon v-if="isListening" class="size-4 fill-current" />
        <MicIcon v-else class="size-4" />
    </PromptInputButton>
</template>

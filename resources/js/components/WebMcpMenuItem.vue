<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    webMcpActiveTools,
    webMcpAuthenticated,
    webMcpSupported,
    webMcpTools,
} from '@/webmcp';
import { trans } from 'laravel-vue-i18n';
import { computed, onUnmounted, ref } from 'vue';
import IconBot from '~icons/lucide/bot';
import IconBotOff from '~icons/lucide/bot-off';
import IconCheck from '~icons/lucide/check';
import IconCopy from '~icons/lucide/copy';
import IconEye from '~icons/lucide/eye';
import IconPencil from '~icons/lucide/pencil';

const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
let copyResetTimer: ReturnType<typeof setTimeout> | null = null;

const activeNames = computed(
    () => new Set(webMcpActiveTools.value.map((tool) => tool.name)),
);

// Callable tools lead; the rest still show, because their presence is the
// argument for signing in or switching browser.
const listed = computed(() =>
    [...webMcpTools.value].sort(
        (a, b) =>
            Number(activeNames.value.has(b.name)) -
            Number(activeNames.value.has(a.name)),
    ),
);

const label = computed(() =>
    webMcpSupported ? 'Agent tools' : 'Agent tools available',
);

const icon = computed(() => (webMcpSupported ? IconBot : IconBotOff));

// State lives on the badge dot, so the icon inherits whatever colour the
// sidebar gives every other menu icon.
const stateColor = computed(() =>
    webMcpSupported ? 'bg-emerald-500' : 'bg-orange-500',
);

const chatUrl = route('chat.index');

const agentPrompt = computed(() =>
    trans(
        "Open :url. Discover and use this page's WebMCP tools to help me",
        { url: chatUrl },
    ),
);

async function copyAgentPrompt(): Promise<void> {
    if (copyResetTimer !== null) {
        clearTimeout(copyResetTimer);
    }

    try {
        await navigator.clipboard.writeText(agentPrompt.value);
        copyState.value = 'copied';
    } catch {
        copyState.value = 'failed';
    }

    copyResetTimer = setTimeout(() => {
        copyState.value = 'idle';
        copyResetTimer = null;
    }, 2000);
}

onUnmounted(() => {
    if (copyResetTimer !== null) {
        clearTimeout(copyResetTimer);
    }
});
</script>

<template>
    <SidebarMenu v-if="webMcpTools.length" data-testid="webmcp-menu">
        <SidebarMenuItem>
            <Dialog>
                <DialogTrigger as-child>
                    <SidebarMenuButton
                        :tooltip="$t(label)"
                        data-testid="webmcp-trigger"
                    >
                        <component :is="icon" />
                        <span>{{ $t(label) }}</span>
                    </SidebarMenuButton>
                </DialogTrigger>

                <SidebarMenuBadge data-testid="webmcp-state">
                    <span
                        class="size-2 rounded-full"
                        :class="stateColor"
                        role="img"
                        :aria-label="
                            webMcpSupported
                                ? $t('Connected')
                                : $t('Not connected')
                        "
                        :title="
                            webMcpSupported
                                ? $t('Connected')
                                : $t('Not connected')
                        "
                    />
                </SidebarMenuBadge>

                <DialogContent class="sm:max-w-lg" data-testid="webmcp-panel">
                    <DialogHeader class="-mx-6 border-b px-6 pb-4">
                        <DialogTitle class="flex items-center gap-2">
                            <component :is="icon" class="size-4" />
                            {{ $t('WebMCP') }}
                        </DialogTitle>
                        <DialogDescription>
                            <template v-if="!webMcpSupported">
                                {{
                                    $t(
                                        'Your own AI agent could operate this page. Two steps to enable it:',
                                    )
                                }}
                            </template>
                            <template v-else-if="!webMcpAuthenticated">
                                {{
                                    $t(
                                        'Your AI agent can use these. Sign in to unlock the rest.',
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    $t(
                                        'Your AI agent can call any of these directly, as you.',
                                    )
                                }}
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <!--
                        chrome:// URLs cannot be linked from a page -- the
                        browser blocks the navigation -- so these are rendered
                        to copy.
                    -->
                    <ol
                        v-if="!webMcpSupported"
                        class="text-muted-foreground -mx-6 space-y-2 border-b px-6 pb-4 text-xs"
                        data-testid="webmcp-setup"
                    >
                        <li>
                            {{ $t('Chrome 149+') }}
                            <span class="opacity-70">{{
                                $t('(151+ recommended)')
                            }}</span>
                        </li>
                        <li>
                            <code
                                class="text-foreground bg-muted rounded px-1 py-0.5 select-all"
                                >chrome://flags/#enable-webmcp-testing</code
                            >
                            → {{ $t('Enabled') }} → {{ $t('Relaunch') }}
                            <p class="mt-1 opacity-70">
                                {{ $t('or launch Chrome with') }}
                                <code class="select-all"
                                    >--enable-features=WebMCP</code
                                >
                            </p>
                        </li>
                        <li>
                            <code
                                class="text-foreground bg-muted rounded px-1 py-0.5 select-all"
                                >chrome://inspect/#remote-debugging</code
                            >
                            → {{ $t('allow remote debugging') }}
                        </li>
                    </ol>

                    <section
                        class="bg-muted/40 -mx-6 -my-4 border-b px-6 py-4"
                        data-testid="webmcp-agent-prompt"
                    >
                        <div
                            class="mb-2 flex items-center justify-between gap-3"
                        >
                            <p class="text-sm font-medium">
                                {{ $t('Prompt for your agent') }}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="xs"
                                data-testid="webmcp-copy-prompt"
                                aria-live="polite"
                                @click="copyAgentPrompt"
                            >
                                <IconCheck
                                    v-if="copyState === 'copied'"
                                    class="text-emerald-600 dark:text-emerald-400"
                                />
                                <IconCopy v-else />
                                <template v-if="copyState === 'copied'">
                                    {{ $t('Copied') }}
                                </template>
                                <template v-else-if="copyState === 'failed'">
                                    {{ $t('Copy failed') }}
                                </template>
                                <template v-else>{{ $t('Copy') }}</template>
                            </Button>
                        </div>
                        <p
                            class="text-muted-foreground text-xs leading-relaxed break-words"
                        >
                            {{ agentPrompt }}
                        </p>
                    </section>

                    <ul class="-mx-6 max-h-80 min-h-0 overflow-y-auto px-6 py-2">
                        <li
                            v-for="tool in listed"
                            :key="tool.name"
                            class="py-2"
                            :class="{
                                'opacity-50': !activeNames.has(tool.name),
                            }"
                            :data-testid="`webmcp-tool-${tool.name}`"
                        >
                            <div class="flex items-center gap-2">
                                <component
                                    :is="tool.readOnly ? IconEye : IconPencil"
                                    class="text-muted-foreground size-3.5 shrink-0"
                                />
                                <code class="text-xs font-medium">{{
                                    tool.name
                                }}</code>
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="
                                        activeNames.has(tool.name)
                                            ? 'bg-emerald-500'
                                            : 'bg-muted-foreground/40'
                                    "
                                    role="img"
                                    :aria-label="
                                        activeNames.has(tool.name)
                                            ? $t('Connected')
                                            : $t('Not connected')
                                    "
                                    :title="
                                        activeNames.has(tool.name)
                                            ? $t('Connected')
                                            : $t('Not connected')
                                    "
                                />
                            </div>
                            <p
                                class="text-muted-foreground mt-0.5 ml-5.5 text-xs"
                            >
                                {{ tool.description }}
                            </p>
                        </li>
                    </ul>
                </DialogContent>
            </Dialog>
        </SidebarMenuItem>
    </SidebarMenu>
</template>

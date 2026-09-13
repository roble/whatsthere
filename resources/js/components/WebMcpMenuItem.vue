<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
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
    webMcpVisitorTools,
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

const listed = computed(() => webMcpVisitorTools.value);

const activeNames = computed(
    () => new Set(webMcpActiveTools.value.map((tool) => tool.name)),
);

/**
 * Two sections, not one long list.
 *
 * What a visitor wants to know first is what their agent can do *right now*;
 * the rest is a preview of where the trip goes next. Splitting on that answers
 * both without a dot on every row to decode.
 */
const groups = computed(() => {
    const live = webMcpVisitorTools.value.filter((tool) =>
        activeNames.value.has(tool.name),
    );
    const later = webMcpVisitorTools.value.filter(
        (tool) => !activeNames.value.has(tool.name),
    );

    return [
        { key: 'active', label: 'Ready to call', tools: live },
        { key: 'later', label: 'Later in the trip', tools: later },
    ].filter((group) => group.tools.length);
});

const label = computed(() =>
    webMcpSupported ? 'WebMCP tools' : 'WebMCP is available',
);

const icon = computed(() => (webMcpSupported ? IconBot : IconBotOff));

const chatUrl = route('chat.index');

const agentPrompt = computed(() =>
    trans("Open :url. Discover and use this page's WebMCP tools to help me", {
        url: chatUrl,
    }),
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
    <SidebarMenu
        v-if="
            webMcpTools.length && (!webMcpSupported || webMcpActiveTools.length)
        "
        data-testid="webmcp-menu"
    >
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
                    <!-- The same badge the panel's title carries, so the two
                         readings of "how much can my agent do here" match.
                         Without WebMCP there is no count to give: the badge
                         says why instead. -->
                    <Badge
                        v-if="webMcpSupported && listed.length"
                        variant="secondary"
                        class="font-mono text-[10px]"
                        data-testid="webmcp-sidebar-count"
                    >
                        {{ activeNames.size }}/{{ listed.length }}
                    </Badge>
                    <Badge
                        v-else-if="!webMcpSupported"
                        variant="outline"
                        class="text-[10px] font-medium text-orange-500"
                    >
                        {{ $t('Off') }}
                    </Badge>
                </SidebarMenuBadge>

                <DialogContent class="sm:max-w-lg" data-testid="webmcp-panel">
                    <DialogHeader class="-mx-6 border-b px-6 pb-4">
                        <DialogTitle class="flex items-center gap-2">
                            <component :is="icon" class="size-4" />
                            {{ $t('WebMCP') }}
                            <Badge
                                v-if="listed.length"
                                variant="secondary"
                                class="font-mono text-xs"
                                data-testid="webmcp-count"
                            >
                                {{ activeNames.size }}/{{ listed.length }}
                            </Badge>
                        </DialogTitle>
                        <DialogDescription class="p-0">
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
                                        'Your AI agent can open sign in or registration. Sign in to unlock trip planning tools.',
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

                    <div
                        class="-mx-6 max-h-80 min-h-0 overflow-y-auto px-6 py-2"
                    >
                        <section
                            v-for="group in groups"
                            :key="group.key"
                            :data-testid="`webmcp-group-${group.key}`"
                        >
                            <h3
                                v-if="group.key !== 'active'"
                                class="text-muted-foreground bg-background/95 sticky top-0 z-10 flex items-center gap-2 py-2 text-[11px] font-semibold tracking-wider uppercase"
                            >
                                <span
                                    class="bg-muted-foreground/40 size-2 shrink-0 rounded-full"
                                    aria-hidden="true"
                                />
                                {{ $t(group.label) }}
                                <span class="opacity-60">{{
                                    group.tools.length
                                }}</span>
                            </h3>

                            <ul class="divide-border/70 divide-y">
                                <li
                                    v-for="tool in group.tools"
                                    :key="tool.name"
                                    class="py-2"
                                    :class="
                                        group.key === 'active'
                                            ? undefined
                                            : 'opacity-70'
                                    "
                                    :data-testid="`webmcp-tool-${tool.name}`"
                                >
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="
                                                tool.readOnly
                                                    ? IconEye
                                                    : IconPencil
                                            "
                                            class="text-muted-foreground size-3.5 shrink-0"
                                            :aria-label="
                                                tool.readOnly
                                                    ? $t('Reads only')
                                                    : $t('Changes something')
                                            "
                                        />
                                        <code class="text-xs font-medium">{{
                                            tool.name
                                        }}</code>
                                    </div>
                                    <p
                                        class="text-muted-foreground mt-0.5 ml-5.5 text-xs"
                                    >
                                        {{ tool.description }}
                                    </p>
                                </li>
                            </ul>
                        </section>
                    </div>
                </DialogContent>
            </Dialog>
        </SidebarMenuItem>
    </SidebarMenu>
</template>

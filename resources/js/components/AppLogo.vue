<script setup lang="ts">
import { useSettings } from '@/composables/useSettings';
import { computed } from 'vue';

/**
 * The mark that ships with the application.
 *
 * Two files rather than one tinted at runtime: the shape is identical and only
 * the fill differs, and an <img> cannot be recoloured from here. `light` is for
 * the branded panels that put it on a dark background.
 */
const SHIPPED_MARK = '/images/logo.svg';
const SHIPPED_MARK_LIGHT = '/images/logo-white.svg';

/**
 * The application's identity, and the only thing this component reads.
 *
 * Anything may stand behind it — the settings a self-hosted install configures, or a
 * module that resolves them per request — so the logo never learns which of those is
 * answering.
 */
const settings = useSettings();
const brand = computed(() => settings.value.general);

const sizeClasses = {
    sm: 'h-8 w-8',
    md: 'h-12 w-12',
    lg: 'h-16 w-16',
    xl: 'h-20 w-20',
    xxl: 'h-30 w-30',
};

// A wordmark is wide, so it keeps the height and drops the square width.
const wordmarkSizeClasses = {
    sm: 'h-8',
    md: 'h-12',
    lg: 'h-16',
    xl: 'h-20',
    xxl: 'h-30',
};

const props = defineProps<{
    size?: 'sm' | 'md' | 'lg' | 'xl' | 'xxl';
    showText?: boolean;
    centered?: boolean;
    variant?: 'brand' | 'light';
    showSubtitle?: boolean;
    subtitleSize?: 'xs' | 'sm' | 'md' | 'xl' | 'xxl';
}>();

/**
 * A wordmark replaces the mark and the name together, but only where there is room for
 * one. In a collapsed sidebar it would be illegible, so the icon wins there regardless
 * of the preference.
 *
 * A brand with only a wordmark still gets it used, since the alternative is showing
 * somebody else's mark.
 */
const useWordmark = computed(
    () =>
        Boolean(props.showText) &&
        Boolean(brand.value.site_logo) &&
        (brand.value.prefer_logo || !brand.value.site_icon),
);

/**
 * The square slot: the icon by preference, the wordmark squeezed in if that is all
 * there is, and otherwise the mark that ships with the application.
 */
const markSrc = computed(
    () =>
        brand.value.site_icon ??
        brand.value.site_logo ??
        (props.variant === 'light' ? SHIPPED_MARK_LIGHT : SHIPPED_MARK),
);

/**
 * Is the square slot showing our own mark rather than a configured one?
 *
 * It is drawn tight to its own edges, so at the same box size it reads bigger
 * than the icon it replaced. The nudge belongs to our file alone -- an install
 * that uploaded its own icon has already chosen how much room it should fill.
 */
const isShippedMark = computed(
    () =>
        markSrc.value === SHIPPED_MARK || markSrc.value === SHIPPED_MARK_LIGHT,
);

/**
 * The shipped name is drawn in two tones, which is the mark's other half. An install
 * that has renamed itself gets its own name rendered plainly — colouring somebody
 * else's word on a seam we chose would read as a bug.
 */
const isShippedName = computed(
    () => brand.value.site_name.toLowerCase() === 'saucebase',
);

/**
 * The configured tagline, falling back to the shipped one only while the shipped name
 * is still in place. An install that renamed itself and set no tagline gets none, rather
 * than our slogan under its own name.
 */
const subtitle = computed(
    () =>
        brand.value.site_tagline ??
        (isShippedName.value ? 'the recipe that works' : null),
);

const textSizeClasses = {
    sm: 'text-xl',
    md: 'text-2xl',
    lg: 'text-3xl',
    xl: 'text-4xl',
    xxl: 'text-6xl',
};

const subtitleSizeClasses = {
    xs: 'text-xs',
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-lg',
    xl: 'text-xl',
    xxl: 'text-2xl',
};

const logoAlt = computed(() => `${brand.value.site_name} logo`);
</script>

<template>
    <div
        :class="
            centered
                ? 'flex flex-col items-center gap-1'
                : 'flex items-center gap-1'
        "
    >
        <!-- A brand with a wordmark and room to show it is named by the image. -->
        <img
            v-if="useWordmark && brand.site_logo"
            :src="brand.site_logo"
            :alt="brand.site_name"
            :class="[
                wordmarkSizeClasses[size || 'md'],
                'w-auto max-w-full object-contain',
            ]"
        />

        <!-- The square slot: the configured icon, or the mark that ships. -->
        <div v-else class="relative" :class="sizeClasses[size || 'md']">
            <img
                :src="markSrc"
                :alt="logoAlt"
                class="h-full w-full object-contain"
                :class="isShippedMark ? 'scale-85' : undefined"
            />
        </div>

        <!-- Text Logo. Skipped when a wordmark is already showing the name. -->
        <div
            v-if="showText && !useWordmark"
            :class="
                centered
                    ? 'flex flex-col items-center text-center'
                    : 'flex flex-col'
            "
        >
            <h1
                :class="[
                    textSizeClasses[size || 'md'],
                    variant === 'light'
                        ? 'leading-none font-bold text-white'
                        : 'leading-none font-bold text-gray-900 dark:text-white',
                ]"
            >
                <!-- The two tones belong to the shipped name. Anything else is the
                     install's own, and is rendered as written. -->
                <template v-if="isShippedName">
                    <span class="text-secondary dark:text-muted-foreground"
                        >sauce</span
                    >
                    <span class="text-primary dark:text-foreground">base</span>
                </template>
                <template v-else>{{ brand.site_name }}</template>
            </h1>
            <p
                v-if="showSubtitle && subtitle"
                :class="[
                    subtitleSizeClasses[subtitleSize || size || 'sm'],
                    'leading-tight',
                    variant === 'light'
                        ? 'text-white/80'
                        : 'text-muted-foreground',
                ]"
            >
                {{ subtitle }}
            </p>
        </div>
    </div>
</template>

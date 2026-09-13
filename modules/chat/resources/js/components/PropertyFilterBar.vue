<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PropertyPreferences } from '@modules/chat/resources/js/components/PropertyFiltersDialog.vue';
import IconBedDouble from '~icons/lucide/bed-double';
import IconEuro from '~icons/lucide/euro';
import IconLandPlot from '~icons/lucide/land-plot';
import IconMapPin from '~icons/lucide/map-pin';
import IconArrowDownWideNarrow from '~icons/lucide/arrow-down-wide-narrow';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconLoader2 from '~icons/lucide/loader-2';
import IconSlidersHorizontal from '~icons/lucide/sliders-horizontal';
import { computed, reactive, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        preferences: PropertyPreferences;
        saving: boolean;
        compact?: boolean;
    }>(),
    { compact: false },
);

const emit = defineEmits<{
    update: [PropertyPreferences];
    preferences: [];
}>();

const form = reactive<PropertyPreferences>({
    sort: 'price',
    ...props.preferences,
});

watch(
    () => props.preferences,
    (preferences) => Object.assign(form, { sort: 'price', ...preferences }),
    { deep: true },
);

const priceInEuros = computed({
    get: () => (form.max_price ? String(Math.round(form.max_price / 100)) : ''),
    set: (value: string) => {
        const price = Number(value.replace(/[^0-9]/g, ''));
        form.max_price = Number.isFinite(price) ? price * 100 : 0;
    },
});

function apply(): void {
    if (!form.location.trim() || form.max_price < 1) {
        return;
    }

    const land = form.property_type === 'land';

    emit('update', {
        ...form,
        location: form.location.trim(),
        county: form.county || null,
        min_bedrooms: land ? null : form.min_bedrooms,
        minimum_ber_rating: land ? null : form.minimum_ber_rating,
    });
}

const locationKey = computed(
    () => `${form.location_type}:${form.location}`,
);

function selectLocation(value: unknown): void {
    if (value === 'county:Cork') {
        form.location = 'Cork';
        form.location_type = 'county';
        form.county = null;
    } else if (value === 'town:Cork') {
        form.location = 'Cork';
        form.location_type = 'town';
        form.county = 'Cork';
    } else {
        return;
    }

    apply();
}

const typeKey = computed(() => {
    if (!form.property_type) {
        return 'Homes and land';
    }

    if (form.property_type === 'land') {
        return 'Land';
    }

    return (
        form.property_type.charAt(0).toUpperCase() + form.property_type.slice(1)
    );
});

const priceLabel = computed(() =>
    form.max_price
        ? `€${Math.round(form.max_price / 100).toLocaleString('en-IE')}`
        : '',
);
</script>

<template>
    <div
        class="border-border/40 from-background/70 to-background/40 flex min-w-0 items-center gap-1.5 border-b bg-gradient-to-r px-2 py-1 backdrop-blur-xl"
        data-testid="property-filter-bar"
    >
        <button
            v-if="compact"
            type="button"
            class="border-border/50 bg-background/80 hover:bg-primary/8 focus-visible:ring-ring group flex min-w-0 flex-1 items-center gap-2 rounded-full border px-3 py-1.5 text-left text-xs shadow-sm shadow-black/5 transition-all duration-200 outline-none hover:border-primary/25 hover:shadow-md hover:shadow-primary/10 focus-visible:ring-2 active:scale-[0.99]"
            data-testid="property-filter-summary"
            :aria-label="$t('Edit search filters')"
            :disabled="saving"
            @click="$emit('preferences')"
        >
            <IconMapPin class="text-primary size-3.5 shrink-0" />
            <span class="min-w-0 flex-1 truncate font-medium">
                {{ form.location || $t('Location') }}
                <template v-if="form.property_type">
                    · {{ $t(typeKey) }}
                </template>
                <template
                    v-if="
                        form.property_type !== 'land' &&
                        form.min_bedrooms !== null
                    "
                >
                    ·
                    {{ $t(':count+ beds', { count: form.min_bedrooms }) }}
                </template>
                <template v-if="priceLabel"> · {{ priceLabel }}</template>
                <template v-if="form.sort === 'price_per_sqm'">
                    · {{ $t('€ / m²') }}
                </template>
            </span>
            <IconLoader2
                v-if="saving"
                class="text-primary size-3.5 shrink-0 animate-spin"
                aria-hidden="true"
            />
            <IconChevronRight
                v-else
                class="text-muted-foreground size-3.5 shrink-0 transition-transform duration-200 group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </button>

        <div v-else class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
            <Select
                :model-value="locationKey"
                @update:model-value="selectLocation"
            >
                <SelectTrigger
                    class="h-7 w-auto shrink-0 gap-1 rounded-lg px-2 py-0 text-xs data-[size=default]:h-7"
                    data-testid="property-filter-location"
                >
                    <IconMapPin class="size-3" /><SelectValue
                        :placeholder="$t('Location')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-if="
                            locationKey !== 'town:Cork' &&
                            locationKey !== 'county:Cork'
                        "
                        :value="locationKey"
                    >
                        {{
                            form.location_type === 'county'
                                ? $t('County :place', { place: form.location })
                                : form.location
                        }}
                    </SelectItem>
                    <SelectItem value="town:Cork">{{ $t('Cork') }}</SelectItem>
                    <SelectItem value="county:Cork">{{
                        $t('County Cork')
                    }}</SelectItem>
                </SelectContent>
            </Select>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        size="xs"
                        class="h-7 shrink-0 rounded-lg"
                        data-testid="property-filter-price"
                    >
                        <IconEuro class="size-3.5" />{{
                            form.max_price
                                ? `€${Math.round(form.max_price / 100).toLocaleString('en-IE')}`
                                : $t('Asking price')
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="space-y-3">
                    <div class="grid gap-2">
                        <Label for="inline-property-price">{{
                            $t('Maximum asking price')
                        }}</Label>
                        <Input
                            id="inline-property-price"
                            v-model="priceInEuros"
                            inputmode="numeric"
                            @keydown.enter.prevent="apply"
                        />
                    </div>
                    <Button
                        class="w-full"
                        size="sm"
                        :disabled="saving"
                        @click="apply"
                        >{{ $t('Apply') }}</Button
                    >
                </PopoverContent>
            </Popover>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        size="xs"
                        class="h-7 shrink-0 rounded-lg"
                        data-testid="property-filter-type"
                    >
                        <IconLandPlot class="size-3.5" />{{
                            form.property_type
                                ? $t(
                                      form.property_type === 'land'
                                          ? 'Land'
                                          : form.property_type
                                                .charAt(0)
                                                .toUpperCase() +
                                                form.property_type.slice(1),
                                  )
                                : $t('Homes and land')
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.property_type = null;
                            apply();
                        "
                        >{{ $t('Any') }}</Button
                    >
                    <Button
                        v-for="type in ['house', 'apartment', 'bungalow', 'land']"
                        :key="type"
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.property_type = type;
                            if (type === 'land') {
                                form.min_bedrooms = null;
                                form.minimum_ber_rating = null;
                            }
                            apply();
                        "
                        >{{
                            $t(
                                type === 'land'
                                    ? 'Land'
                                    : type.charAt(0).toUpperCase() +
                                      type.slice(1),
                            )
                        }}</Button
                    >
                </PopoverContent>
            </Popover>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        size="xs"
                        class="h-7 shrink-0 rounded-lg"
                        :disabled="form.property_type === 'land'"
                        data-testid="property-filter-beds"
                    >
                        <IconBedDouble class="size-3.5" />{{
                            form.property_type === 'land'
                                ? $t('Beds not used')
                                : form.min_bedrooms === null
                                  ? $t('Beds')
                                  : $t(':count+ beds', {
                                        count: form.min_bedrooms,
                                    })
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="grid grid-cols-4 gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.min_bedrooms = null;
                            apply();
                        "
                        >{{ $t('Any') }}</Button
                    >
                    <Button
                        v-for="bedrooms in 6"
                        :key="bedrooms"
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.min_bedrooms = bedrooms;
                            apply();
                        "
                        >{{ bedrooms }}+</Button
                    >
                </PopoverContent>
            </Popover>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        size="xs"
                        class="h-7 shrink-0 rounded-lg"
                        data-testid="property-filter-sort"
                    >
                        <IconArrowDownWideNarrow class="size-3.5" />{{
                            form.sort === 'price_per_sqm'
                                ? $t('€ / m²')
                                : $t('Price')
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="grid gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.sort = 'price';
                            apply();
                        "
                        >{{ $t('Lowest price') }}</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="
                            form.sort = 'price_per_sqm';
                            apply();
                        "
                        >{{ $t('Lowest price per m²') }}</Button
                    >
                </PopoverContent>
            </Popover>
        </div>

        <Button
            variant="ghost"
            size="icon-xs"
            class="size-7 shrink-0 rounded-full transition-all duration-200 hover:bg-primary/10"
            :aria-label="$t('All buying preferences')"
            data-testid="open-buying-preferences"
            :disabled="saving"
            @click="$emit('preferences')"
        >
            <IconLoader2
                v-if="saving"
                class="text-primary size-4 animate-spin"
            />
            <IconSlidersHorizontal v-else class="size-4" />
        </Button>
    </div>
</template>

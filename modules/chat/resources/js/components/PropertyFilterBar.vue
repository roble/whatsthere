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
import IconMapPin from '~icons/lucide/map-pin';
import IconSlidersHorizontal from '~icons/lucide/sliders-horizontal';
import { computed, reactive, watch } from 'vue';

const props = defineProps<{
    preferences: PropertyPreferences;
    saving: boolean;
}>();

const emit = defineEmits<{
    update: [PropertyPreferences];
    preferences: [];
}>();

const form = reactive<PropertyPreferences>({ ...props.preferences });

watch(
    () => props.preferences,
    (preferences) => Object.assign(form, preferences),
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

    emit('update', {
        ...form,
        location: form.location.trim(),
        county: form.county || null,
    });
}

function bedroomsLabel(): string {
    return form.min_bedrooms === null ? 'Beds' : `${form.min_bedrooms}+ beds`;
}

function selectCork(): void {
    form.location = 'Cork';
    form.location_type = 'town';
    form.county = 'Cork';
    apply();
}
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1 border-b px-2 py-1"
        data-testid="property-filter-bar"
    >
        <div class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
            <Select
                :model-value="form.location"
                @update:model-value="selectCork"
            >
                <!-- The trigger sets its own height through a `data-size`
                     variant, which outranks a plain `h-6`. Overriding the same
                     variant is what actually lands it on the row height the
                     chips beside it use. -->
                <SelectTrigger
                    class="h-6 w-auto shrink-0 gap-1 rounded-md px-2 py-0 text-xs data-[size=default]:h-6"
                    data-testid="property-filter-location"
                >
                    <IconMapPin class="size-3" /><SelectValue
                        :placeholder="$t('Location')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="Cork">{{ $t('Cork') }}</SelectItem>
                </SelectContent>
            </Select>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        size="xs"
                        class="shrink-0"
                        data-testid="property-filter-price"
                    >
                        <IconEuro class="size-3.5" />{{
                            form.max_price
                                ? `Up to €${Math.round(form.max_price / 100).toLocaleString('en-IE')}`
                                : $t('Asking price')
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="space-y-3">
                    <div class="grid gap-2">
                        <Label for="inline-property-price">{{
                            $t('Maximum asking price (€)')
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
                        class="shrink-0"
                        data-testid="property-filter-beds"
                    >
                        <IconBedDouble class="size-3.5" />{{
                            $t(bedroomsLabel())
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
        </div>

        <Button
            variant="ghost"
            size="icon-xs"
            class="shrink-0"
            :aria-label="$t('All buying preferences')"
            data-testid="open-buying-preferences"
            @click="$emit('preferences')"
        >
            <IconSlidersHorizontal class="size-4" />
        </Button>
    </div>
</template>

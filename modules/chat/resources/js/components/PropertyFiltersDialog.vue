<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import IconBedDouble from '~icons/lucide/bed-double';
import IconHeart from '~icons/lucide/heart';
import IconMapPin from '~icons/lucide/map-pin';
import IconWalletCards from '~icons/lucide/wallet-cards';
import IconArrowDownWideNarrow from '~icons/lucide/arrow-down-wide-narrow';
import IconZap from '~icons/lucide/zap';
import { computed, reactive, watch } from 'vue';

export type PropertyPreferences = {
    location: string;
    location_type: 'town' | 'county';
    county: string | null;
    max_price: number;
    min_bedrooms: number | null;
    property_type: string | null;
    minimum_ber_rating: string | null;
    sort: 'price' | 'price_per_sqm';
};

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    preferences: PropertyPreferences | null;
    saving: boolean;
}>();
const emit = defineEmits<{ save: [PropertyPreferences] }>();

const form = reactive<PropertyPreferences>({
    location: '',
    location_type: 'town',
    county: null,
    max_price: 0,
    min_bedrooms: null,
    property_type: null,
    minimum_ber_rating: null,
    sort: 'price',
});

watch(
    () => [open.value, props.preferences] as const,
    () => {
        if (!open.value || !props.preferences) return;
        Object.assign(form, { sort: 'price', ...props.preferences });
    },
    { immediate: true, deep: true },
);

const priceInEuros = computed({
    get: () => (form.max_price ? String(Math.round(form.max_price / 100)) : ''),
    set: (value: string) => {
        const amount = Number(value.replace(/[^0-9]/g, ''));
        form.max_price = Number.isFinite(amount) ? amount * 100 : 0;
    },
});

function submit(): void {
    const land = form.property_type === 'land';

    emit('save', {
        ...form,
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
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[min(36rem,calc(100dvh-2rem))] gap-3 overflow-y-auto p-4 sm:max-w-md"
            data-testid="property-filters-dialog"
        >
            <DialogHeader class="gap-1">
                <DialogTitle class="text-base">{{ $t('Your buying preferences') }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'Update the search filters, or ask the assistant in the chat.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label class="flex items-center gap-2"
                        ><IconMapPin class="text-primary size-4" />{{
                            $t('Location')
                        }}</Label
                    >
                    <Select
                        :model-value="locationKey"
                        @update:model-value="selectLocation"
                    >
                        <SelectTrigger><SelectValue /></SelectTrigger>
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
                                        ? $t('County :place', {
                                              place: form.location,
                                          })
                                        : form.location
                                }}
                            </SelectItem>
                            <SelectItem value="town:Cork">{{
                                $t('Cork')
                            }}</SelectItem>
                            <SelectItem value="county:Cork">{{
                                $t('County Cork')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label
                            for="property-price"
                            class="flex items-center gap-2"
                            ><IconWalletCards class="text-primary size-4" />{{
                                $t('Maximum price in euro')
                            }}</Label
                        >
                        <Input
                            id="property-price"
                            v-model="priceInEuros"
                            inputmode="numeric"
                            required
                        />
                    </div>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-2"
                            ><IconBedDouble class="text-primary size-4" />{{
                                $t('Bedrooms')
                            }}</Label
                        >
                        <Select
                            v-model="form.min_bedrooms"
                            :disabled="form.property_type === 'land'"
                        >
                            <SelectTrigger
                                ><SelectValue :placeholder="$t('Any')"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="null">{{
                                    $t('Any')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="bedroom in 6"
                                    :key="bedroom"
                                    :value="bedroom"
                                    >{{ bedroom }}+</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-2"
                            ><IconHeart class="text-primary size-4" />{{
                                $t('Property type')
                            }}</Label
                        >
                        <Select v-model="form.property_type">
                            <SelectTrigger
                                ><SelectValue :placeholder="$t('Any')"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="null">{{
                                    $t('Any')
                                }}</SelectItem>
                                <SelectItem value="house">{{
                                    $t('House')
                                }}</SelectItem>
                                <SelectItem value="apartment">{{
                                    $t('Apartment')
                                }}</SelectItem>
                                <SelectItem value="bungalow">{{
                                    $t('Bungalow')
                                }}</SelectItem>
                                <SelectItem value="land">{{
                                    $t('Land')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-2"
                            ><IconZap class="text-primary size-4" />{{
                                $t('Minimum BER')
                            }}</Label
                        >
                        <Select
                            v-model="form.minimum_ber_rating"
                            :disabled="form.property_type === 'land'"
                        >
                            <SelectTrigger
                                ><SelectValue :placeholder="$t('Any')"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="null">{{
                                    $t('Any')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="rating in [
                                        'A1',
                                        'A2',
                                        'A3',
                                        'B1',
                                        'B2',
                                        'B3',
                                        'C1',
                                        'C2',
                                        'C3',
                                        'D1',
                                        'D2',
                                        'E1',
                                        'E2',
                                        'F',
                                        'G',
                                    ]"
                                    :key="rating"
                                    :value="rating"
                                    >{{ rating }}
                                    {{ $t('or better') }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label class="flex items-center gap-2"
                        ><IconArrowDownWideNarrow class="text-primary size-4" />{{
                            $t('Sort listings')
                        }}</Label
                    >
                    <Select v-model="form.sort">
                        <SelectTrigger data-testid="property-filter-sort">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="price">{{
                                $t('Lowest price')
                            }}</SelectItem>
                            <SelectItem value="price_per_sqm">{{
                                $t('Lowest price per m²')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-muted-foreground text-xs">
                        {{
                            $t(
                                'Price per m² uses floor area for homes and plot size for land.',
                            )
                        }}
                    </p>
                </div>
                <p
                    v-if="form.property_type === 'land'"
                    class="text-muted-foreground text-xs"
                >
                    {{ $t('Bedrooms and BER apply to homes, not land.') }}
                </p>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="saving"
                        data-testid="apply-property-filters"
                        >{{
                            saving ? $t('Searching…') : $t('Apply filters')
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

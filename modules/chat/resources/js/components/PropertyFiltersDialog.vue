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
});

watch(
    () => [open.value, props.preferences] as const,
    () => {
        if (!open.value || !props.preferences) return;
        Object.assign(form, props.preferences);
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
    emit('save', { ...form, county: form.county || null });
}

function selectCork(): void {
    form.location = 'Cork';
    form.location_type = 'town';
    form.county = 'Cork';
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="property-filters-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('Your buying preferences') }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'Update the search filters, or ask the assistant in the chat.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label class="flex items-center gap-2"
                        ><IconMapPin class="text-primary size-4" />{{
                            $t('Location')
                        }}</Label
                    >
                    <Select
                        :model-value="form.location"
                        @update:model-value="selectCork"
                    >
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="Cork">{{
                                $t('Cork')
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
                                $t('Maximum price (€)')
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
                        <Select v-model="form.min_bedrooms">
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
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-2"
                            ><IconZap class="text-primary size-4" />{{
                                $t('Minimum BER')
                            }}</Label
                        >
                        <Select v-model="form.minimum_ber_rating">
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

import type { MapMarker } from '@modules/chat/resources/js/map';
import { safeHttpUrl } from '@/lib/safeHttpUrl';
import type { Component } from 'vue';
import IconBus from '~icons/lucide/bus';
import IconCoffee from '~icons/lucide/coffee';
import IconGraduationCap from '~icons/lucide/graduation-cap';
import IconHospital from '~icons/lucide/hospital';
import IconMapPin from '~icons/lucide/map-pin';
import IconPill from '~icons/lucide/pill';
import IconShoppingBasket from '~icons/lucide/shopping-basket';
import IconStethoscope from '~icons/lucide/stethoscope';
import IconTrainFront from '~icons/lucide/train-front';
import IconTrees from '~icons/lucide/trees';
import IconUtensils from '~icons/lucide/utensils';

export function isLand(marker: MapMarker): boolean {
    return marker.property_type === 'land';
}

export function typeLabelKey(marker: MapMarker): string {
    if (isLand(marker)) {
        return 'Land';
    }

    const type = marker.property_type?.replaceAll('_', ' ').trim();

    if (!type || type === 'other') {
        return 'Home';
    }

    return type.charAt(0).toUpperCase() + type.slice(1);
}

export function formatPrice(marker: MapMarker): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: marker.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format((marker.asking_price ?? 0) / 100);
}

export function highlightLabelKey(
    highlight: MapMarker['highlight'],
): string | null {
    const labels: Record<string, string> = {
        value: 'Value',
        match: 'Bedroom match',
        premium: 'Near budget',
    };

    return highlight && labels[highlight] ? labels[highlight] : null;
}

export function highlightTone(
    highlight: MapMarker['highlight'],
): string | null {
    const tones: Record<string, string> = {
        value: 'bg-emerald-500',
        match: 'bg-sky-500',
        premium: 'bg-amber-500',
    };

    return highlight && tones[highlight] ? tones[highlight] : null;
}

/** Glass pill styling for highlight badges on listing cards. */
export function highlightBadgeClass(
    highlight: MapMarker['highlight'],
): string | null {
    const classes: Record<string, string> = {
        value: 'border-emerald-300/25 bg-emerald-950/70 text-emerald-50 shadow-emerald-500/20',
        match: 'border-sky-300/25 bg-sky-950/70 text-sky-50 shadow-sky-500/20',
        premium: 'border-amber-300/25 bg-amber-950/70 text-amber-50 shadow-amber-500/20',
    };

    return highlight && classes[highlight] ? classes[highlight] : null;
}

export function highlightShortLabelKey(
    highlight: MapMarker['highlight'],
): string | null {
    const labels: Record<string, string> = {
        value: 'Value',
        match: 'Match',
        premium: 'Top pick',
    };

    return highlight && labels[highlight] ? labels[highlight] : null;
}

export function formatPricePerSqm(marker: MapMarker): string | null {
    if (marker.price_per_sqm == null || marker.price_per_sqm <= 0) {
        return null;
    }

    return `${new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: marker.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format(marker.price_per_sqm / 100)}/m²`;
}

export function formatDistance(metres: number): string {
    return metres < 1000
        ? `${Math.round(metres)} m`
        : `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`;
}

export function nearbyCategoryLabel(category: string | undefined): string {
    const labels: Record<string, string> = {
        school: 'School',
        college: 'College',
        university: 'University',
        hospital: 'Hospital',
        clinic: 'Clinic',
        bus_stop: 'Bus stop',
        bus_station: 'Bus station',
        train_station: 'Train',
        supermarket: 'Shop',
        pharmacy: 'Pharmacy',
        park: 'Park',
        cafe: 'Cafe',
        restaurant: 'Restaurant',
    };

    return labels[category ?? ''] ?? 'Place';
}

export function nearbyCategoryIcon(category: string | undefined): Component {
    const icons: Record<string, Component> = {
        school: IconGraduationCap,
        college: IconGraduationCap,
        university: IconGraduationCap,
        hospital: IconHospital,
        clinic: IconStethoscope,
        bus_stop: IconBus,
        bus_station: IconBus,
        train_station: IconTrainFront,
        supermarket: IconShoppingBasket,
        pharmacy: IconPill,
        park: IconTrees,
        cafe: IconCoffee,
        restaurant: IconUtensils,
    };

    return icons[category ?? ''] ?? IconMapPin;
}

export function nearestByCategory(
    places: MapMarker[],
    limit = 8,
): MapMarker[] {
    const seen = new Set<string>();
    const nearest: MapMarker[] = [];

    for (const place of [...places].sort(
        (left, right) => (left.distance_m ?? 1e9) - (right.distance_m ?? 1e9),
    )) {
        const category = place.categoryKey ?? '';

        if (seen.has(category)) {
            continue;
        }

        seen.add(category);
        nearest.push(place);

        if (nearest.length >= limit) {
            break;
        }
    }

    return nearest;
}

/** The selected pin's id. Listing facts are loaded server-side from that id. */
export function slimSelectedProperty(
    marker: MapMarker | null,
): { id: number } | null {
    return typeof marker?.id === 'number' ? { id: marker.id } : null;
}

const PORTALS: Record<string, string> = {
    daft: 'Daft.ie',
    myhome: 'MyHome.ie',
    ppr: 'Property Price Register',
};

/** Live portal link, only when the stored URL is a safe http(s) address. */
export function listingSource(
    marker: MapMarker | null | undefined,
): { provider: string; url: string; label: string } | null {
    const source = marker?.source;
    const url = source ? safeHttpUrl(source.url) : null;

    if (!source || url === null) {
        return null;
    }

    return {
        provider: source.provider,
        url,
        label:
            PORTALS[source.provider] ??
            source.provider.replace(/^./, (character) => character.toUpperCase()),
    };
}

export function headingKey(markers: MapMarker[] | undefined): string {
    const listings = markers ?? [];
    const landCount = listings.filter(isLand).length;

    if (listings.length > 0 && landCount === listings.length) {
        return 'Land for sale';
    }

    if (landCount > 0) {
        return 'Homes and land for sale';
    }

    return 'Homes for sale';
}

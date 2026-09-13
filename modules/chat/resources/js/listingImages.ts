import { safeHttpUrl, safeHttpUrls } from '@/lib/safeHttpUrl';
import type { MapMarker } from '@modules/chat/resources/js/map';
import type { InjectionKey, Ref } from 'vue';

export const CHAT_LISTING_MARKERS: InjectionKey<Ref<MapMarker[]>> = Symbol(
    'chat-listing-markers',
);

function normalizeImageUrl(url: string): string {
    try {
        const parsed = new URL(url);

        return `${parsed.origin}${parsed.pathname}`;
    } catch {
        return url;
    }
}

export function resolveListingImages(
    src: string | null | undefined,
    markers: MapMarker[],
): { images: string[]; name: string; startIndex: number } | null {
    const safe = safeHttpUrl(src);

    if (!safe) {
        return null;
    }

    const needle = normalizeImageUrl(safe);

    for (const marker of markers) {
        const images = safeHttpUrls(marker.images ?? []);
        const startIndex = images.findIndex(
            (image) =>
                image === safe || normalizeImageUrl(image) === needle,
        );

        if (startIndex >= 0) {
            return {
                images,
                name: marker.name,
                startIndex,
            };
        }
    }

    return null;
}

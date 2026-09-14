import { safeListingImageUrl, safeListingImageUrls } from '@/lib/safeHttpUrl';
import type { MapMarker } from '@modules/chat/resources/js/map';
import type { InjectionKey, Ref } from 'vue';

export const CHAT_LISTING_MARKERS: InjectionKey<Ref<MapMarker[]>> = Symbol(
    'chat-listing-markers',
);

/** Origin + path, no query or hash, so CDN cache-busters still match. */
export function normalizeImageUrl(url: string): string {
    try {
        const parsed = new URL(url);

        return `${parsed.origin}${parsed.pathname}`;
    } catch {
        return url.split('?')[0]?.split('#')[0] ?? url;
    }
}

/**
 * MyHome (and similar) serve the same photo as `_l.jpg`, `_s.jpg`, etc.
 * Matching those variants lets a markdown embed open the full gallery.
 */
export function listingPhotoKey(url: string): string {
    return normalizeImageUrl(url).replace(/_[a-z](?=\.[a-z0-9]+$)/i, '');
}

/**
 * Directory that holds every photo for one listing.
 *
 * MyHome uses `/media/a/b/c/{listingId}/file.jpg`. Daft puts a single
 * signed blob at the origin (`/eyJ...`), so stripping the filename would
 * leave `https://media.daft.ie` and attach the first Daft gallery to every
 * later Daft photo. Require enough path depth that the folder is listing-specific.
 */
export function listingPhotoFolder(url: string): string | null {
    try {
        const parsed = new URL(normalizeImageUrl(url));
        const segments = parsed.pathname.split('/').filter(Boolean);

        if (segments.length < 3) {
            return null;
        }

        segments.pop();

        return `${parsed.origin}/${segments.join('/')}`;
    } catch {
        return null;
    }
}

export function resolveListingImages(
    src: string | null | undefined,
    markers: MapMarker[],
): { images: string[]; name: string; startIndex: number } | null {
    const safe = safeListingImageUrl(src);

    if (!safe) {
        return null;
    }

    const needle = normalizeImageUrl(safe);
    const needleKey = listingPhotoKey(safe);
    const needleFolder = listingPhotoFolder(safe);

    for (const marker of markers) {
        const images = safeListingImageUrls(marker.images ?? []);

        if (!images.length) {
            continue;
        }

        let startIndex = images.findIndex(
            (image) =>
                image === safe ||
                normalizeImageUrl(image) === needle ||
                listingPhotoKey(image) === needleKey,
        );

        if (startIndex < 0 && needleFolder !== null) {
            startIndex = images.findIndex(
                (image) => listingPhotoFolder(image) === needleFolder,
            );
        }

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

/** Prefer the fuller gallery when a streamed tool payload only kept one photo. */
export function richerImages(
    incoming: string[] | undefined,
    previous: string[] | undefined,
): string[] {
    const next = incoming ?? [];
    const prev = previous ?? [];

    if (prev.length > next.length) {
        return prev;
    }

    return next.length ? next : prev;
}

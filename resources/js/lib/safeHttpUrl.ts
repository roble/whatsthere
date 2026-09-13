/** Allow only absolute http(s) URLs in rendered links and images. */
export function safeHttpUrl(url: string | null | undefined): string | null {
    if (!url || /[\u0000-\u001f\u007f]/.test(url)) {
        return null;
    }

    try {
        const parsed = new URL(url);

        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return null;
        }

        if (parsed.username || parsed.password) {
            return null;
        }

        if (!/^https?:\/\//i.test(url)) {
            return null;
        }

        return url;
    } catch {
        return null;
    }
}

/** Drop anything that is not a safe http(s) image URL. */
export function safeHttpUrls(urls: string[] | undefined): string[] {
    return (urls ?? []).filter((url): url is string => safeHttpUrl(url) !== null);
}

<?php

namespace Modules\Properties;

use Illuminate\Support\Str;

class PropertyKind
{
    /** @var list<string> */
    public const array TYPES = ['house', 'apartment', 'bungalow', 'land'];

    public static function fromRaw(mixed $value, ?string $address = null): string
    {
        $type = Str::lower(trim((string) ($value ?? '')));

        if ($type !== '' && $type !== 'none' && $type !== 'other') {
            if (Str::contains($type, ['site', 'plot', 'woodland'])
                || preg_match('/\b(lands?|agricultural)\b/', $type) === 1) {
                return 'land';
            }

            if (Str::contains($type, ['apartment', 'penthouse', 'studio', 'duplex'])) {
                return 'apartment';
            }

            if (Str::contains($type, 'bungalow') || $type === 'dormer') {
                return 'bungalow';
            }

            if (Str::contains($type, ['house', 'cottage', 'terrace', 'townhouse', 'mews', 'farm', 'holiday'])) {
                return 'house';
            }
        }

        return self::addressLooksLikeLand($address) ? 'land' : 'other';
    }

    public static function resolve(?string $stored, mixed $raw, ?string $address = null): string
    {
        if (self::addressLooksLikeLand($address)) {
            return 'land';
        }

        $fromRaw = self::fromRaw($raw, $address);

        if ($fromRaw !== 'other') {
            return $fromRaw;
        }

        return in_array($stored, self::TYPES, true) ? $stored : 'other';
    }

    public static function addressLooksLikeLand(?string $address): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }

        return preg_match(
            '/^\s*(c\.?\s*)?(development\s+)?(site|plot|lands?)\b|\bsite\s*@|^\s*(c\.?\s*)?\d+(?:[.,]\d+)?\s*(acres?|hectares?|ha)\b/i',
            $address,
        ) === 1;
    }

    /** @return array{sqm: float, label: string}|null */
    public static function plotSize(
        ?float $sizeSqm,
        ?string $sizeDisplay,
        ?string $address,
        ?string $description,
        string $kind,
    ): ?array {
        $text = trim(implode(' ', array_filter([$sizeDisplay, $address, $description], filled(...))));

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(hectares?|ha)\b/i', $text, $match) === 1) {
            $hectares = (float) str_replace(',', '.', $match[1]);

            return [
                'sqm' => $hectares * 10000,
                'label' => self::number($hectares).' ha',
            ];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*acres?\b/i', $text, $match) === 1) {
            $acres = (float) str_replace(',', '.', $match[1]);

            return [
                'sqm' => $acres * 4046.8564224,
                'label' => self::acres($acres),
            ];
        }

        if ($kind === 'land' && $sizeSqm !== null && $sizeSqm > 0) {
            $acres = $sizeSqm / 4046.8564224;

            if ($acres >= 0.1) {
                return [
                    'sqm' => $sizeSqm,
                    'label' => self::acres($acres),
                ];
            }

            return [
                'sqm' => $sizeSqm,
                'label' => number_format((int) round($sizeSqm)).' m²',
            ];
        }

        return null;
    }

    private static function acres(float $acres): string
    {
        return self::number($acres).($acres == 1.0 ? ' acre' : ' acres');
    }

    private static function number(float $value): string
    {
        $formatted = number_format($value, $value >= 10 ? 1 : 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

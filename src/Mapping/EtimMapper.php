<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

/**
 * Normalise Skwirrel's _etim[] payload into a compact structure for storage
 * and display. Two product-side rules:
 *
 *  - Skip features marked not_applicable=true. There is no separate
 *    "show on website" flag in the API; Gavilar's workflow is to mark
 *    features they don't want shown as NVT.
 *  - Skip features without any value (no value code, numeric, boolean
 *    or range). They have nothing meaningful to render.
 *
 * The output stores labels per language so the renderer can pick the right
 * one at display time. ETIM-side translations in Gavilar's tenant currently
 * only carry English; rendering must fall back from requested locale to
 * default locale to English.
 */
final class EtimMapper
{
    public function build(mixed $etim): array
    {
        if (!is_array($etim)) {
            return [];
        }
        $classes = [];
        foreach ($etim as $block) {
            if (!is_array($block)) {
                continue;
            }

            $features = [];
            foreach ((array) ($block['_etim_features'] ?? []) as $featureRaw) {
                if (!is_array($featureRaw)) {
                    continue;
                }
                $feature = $this->buildFeature($featureRaw);
                if ($feature !== null) {
                    $features[] = $feature;
                }
            }

            if (empty($features)) {
                continue;
            }
            usort($features, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            $classes[] = [
                'class_code' => (string) ($block['etim_class_code'] ?? ''),
                'class_label_by_lang' => $this->extractLabels(
                    $block['_etim_class_translations'] ?? [],
                    'etim_class_description',
                ),
                'group_code' => (string) ($block['etim_group_code'] ?? ''),
                'group_label_by_lang' => $this->extractLabels(
                    $block['_etim_group_translations'] ?? [],
                    'etim_group_description',
                ),
                'features' => $features,
            ];
        }
        return $classes;
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>|null
     */
    private function buildFeature(array $f): ?array
    {
        if (!empty($f['not_applicable'])) {
            return null;
        }

        $value = [];
        $hasValue = false;

        $valueCode = $f['etim_value_code'] ?? null;
        if ($valueCode !== null && $valueCode !== '') {
            $value['code'] = (string) $valueCode;
            $value['label_by_lang'] = $this->extractLabels(
                $f['_etim_value_translations'] ?? [],
                'etim_value_description',
            );
            $hasValue = true;
        }
        $numeric = $f['numeric_value'] ?? null;
        if ($numeric !== null && $numeric !== '') {
            $value['numeric'] = is_numeric($numeric) ? $numeric + 0 : (string) $numeric;
            $hasValue = true;
        }
        if (isset($f['logical_value']) && $f['logical_value'] !== null) {
            $value['logical'] = (bool) $f['logical_value'];
            $hasValue = true;
        }
        $rangeMin = $f['range_min'] ?? null;
        $rangeMax = $f['range_max'] ?? null;
        if ($rangeMin !== null || $rangeMax !== null) {
            $value['range'] = [
                'min' => is_numeric($rangeMin) ? $rangeMin + 0 : $rangeMin,
                'max' => is_numeric($rangeMax) ? $rangeMax + 0 : $rangeMax,
            ];
            $hasValue = true;
        }
        if (!$hasValue) {
            return null;
        }

        return [
            'code' => (string) ($f['etim_feature_code'] ?? ''),
            'type' => (string) ($f['etim_feature_type'] ?? ''),
            'order' => (int) ($f['order_number'] ?? 0),
            'label_by_lang' => $this->extractLabels(
                $f['_etim_feature_translations'] ?? [],
                'etim_feature_description',
            ),
            'value' => $value,
            'unit_code' => $f['etim_unit_code'] ?? null,
            'unit_label_by_lang' => $this->extractLabels(
                $f['_etim_unit_translations'] ?? [],
                'etim_unit_description',
            ),
            'unit_abbr_by_lang' => $this->extractLabels(
                $f['_etim_unit_translations'] ?? [],
                'etim_unit_abbreviation',
            ),
        ];
    }

    /**
     * @param mixed $translations
     * @return array<string, string> language code => value
     */
    private function extractLabels(mixed $translations, string $field): array
    {
        if (!is_array($translations)) {
            return [];
        }
        $out = [];
        foreach ($translations as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $lang = (string) ($entry['language'] ?? '');
            $val = (string) ($entry[$field] ?? '');
            if ($lang !== '' && $val !== '') {
                $out[$lang] = $val;
            }
        }
        return $out;
    }

    /**
     * Pick the best label across requested language → fallbacks. Static so the
     * display layer can use the same priority without re-creating the mapper.
     *
     * @param array<string, string> $byLang
     */
    public static function pickLabel(array $byLang, string $preferredLang, string $defaultLang = 'en'): string
    {
        if (isset($byLang[$preferredLang]) && $byLang[$preferredLang] !== '') {
            return $byLang[$preferredLang];
        }
        if (isset($byLang[$defaultLang]) && $byLang[$defaultLang] !== '') {
            return $byLang[$defaultLang];
        }
        if (isset($byLang['en']) && $byLang['en'] !== '') {
            return $byLang['en'];
        }
        foreach ($byLang as $val) {
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    /**
     * Format a normalised feature as a human-readable "label: value" string for
     * the given display language.
     *
     * @param array<string, mixed> $feature
     * @return array{label:string,value:string}
     */
    public static function format(array $feature, string $displayLang, string $defaultLang = 'en'): array
    {
        $label = self::pickLabel((array) ($feature['label_by_lang'] ?? []), $displayLang, $defaultLang);
        $unitAbbr = self::pickLabel((array) ($feature['unit_abbr_by_lang'] ?? []), $displayLang, $defaultLang);

        $value = $feature['value'] ?? [];
        $rendered = '';

        if (isset($value['code'])) {
            $rendered = self::pickLabel((array) ($value['label_by_lang'] ?? []), $displayLang, $defaultLang)
                ?: (string) $value['code'];
        } elseif (isset($value['numeric'])) {
            $rendered = (string) $value['numeric'];
            if ($unitAbbr !== '') {
                $rendered .= ' ' . $unitAbbr;
            }
        } elseif (isset($value['logical'])) {
            $rendered = $value['logical']
                ? (string) __('Yes', 'skwirrel-gavilar')
                : (string) __('No', 'skwirrel-gavilar');
        } elseif (isset($value['range'])) {
            $min = $value['range']['min'] ?? null;
            $max = $value['range']['max'] ?? null;
            if ($min !== null && $max !== null) {
                $rendered = $min === $max ? (string) $min : "{$min} – {$max}";
            } elseif ($min !== null) {
                $rendered = "≥ {$min}";
            } elseif ($max !== null) {
                $rendered = "≤ {$max}";
            }
            if ($rendered !== '' && $unitAbbr !== '') {
                $rendered .= ' ' . $unitAbbr;
            }
        }

        return ['label' => $label, 'value' => $rendered];
    }
}

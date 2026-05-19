<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Mapping;

final class FeatureMapper
{
    public const META_PREFIX = '_pim_feature_';
    public const META_INDEX = '_pim_features_index';

    /**
     * Write Skwirrel custom features as structured meta on the product.
     *
     * Skwirrel "custom features" group under "custom classes" with one or more
     * "values" each. The shape varies a little across products, so we store:
     *  - One meta per feature using a stable key `_pim_feature_<class_slug>_<feature_slug>`.
     *  - A flat searchable index in `_pim_features_index` (newline-joined value labels).
     *
     * @param array<mixed> $customClasses Raw `custom_classes` array from Skwirrel.
     */
    public function apply(int $postId, array $customClasses): void
    {
        if (empty($customClasses)) {
            $this->clearExisting($postId);
            return;
        }

        $newKeys = [];
        $indexValues = [];

        foreach ($customClasses as $class) {
            if (!is_array($class)) {
                continue;
            }
            $classSlug = $this->slugify((string) ($class['name'] ?? $class['code'] ?? 'class'));

            foreach ((array) ($class['features'] ?? []) as $feature) {
                if (!is_array($feature)) {
                    continue;
                }
                $featureSlug = $this->slugify((string) ($feature['name'] ?? $feature['code'] ?? 'feature'));
                $values = [];
                foreach ((array) ($feature['values'] ?? []) as $value) {
                    if (is_array($value)) {
                        $values[] = (string) ($value['label'] ?? $value['value'] ?? '');
                    } elseif (is_scalar($value)) {
                        $values[] = (string) $value;
                    }
                }
                $values = array_values(array_filter($values, static fn ($v) => $v !== ''));
                if (empty($values)) {
                    continue;
                }

                $metaKey = self::META_PREFIX . $classSlug . '_' . $featureSlug;
                update_post_meta($postId, $metaKey, count($values) === 1 ? $values[0] : $values);
                $newKeys[] = $metaKey;
                $indexValues = array_merge($indexValues, $values);
            }
        }

        $this->pruneStaleKeys($postId, $newKeys);
        update_post_meta($postId, self::META_INDEX, implode("\n", $indexValues));
    }

    private function clearExisting(int $postId): void
    {
        $this->pruneStaleKeys($postId, []);
        delete_post_meta($postId, self::META_INDEX);
    }

    /** @param string[] $keepKeys */
    private function pruneStaleKeys(int $postId, array $keepKeys): void
    {
        $all = get_post_meta($postId);
        if (!is_array($all)) {
            return;
        }
        foreach ($all as $key => $_) {
            if (!is_string($key)) {
                continue;
            }
            if (str_starts_with($key, self::META_PREFIX) && !in_array($key, $keepKeys, true)) {
                delete_post_meta($postId, $key);
            }
        }
    }

    private function slugify(string $value): string
    {
        $slug = sanitize_key($value);
        return $slug !== '' ? $slug : 'x';
    }
}

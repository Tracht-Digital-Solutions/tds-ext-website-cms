<?php
declare(strict_types=1);

namespace Tds\Ext\WebsiteCms\Service;

/**
 * Extracts the human-copy string leaves from a content block's value array (and
 * re-inserts their translations) so a whole block costs one batched DeepL
 * request. Ported verbatim from tds-content-api. Non-copy fields are skipped two
 * ways: by key (hrefs, URLs, icon names, slugs, ids…) and by value shape
 * (anything that looks like a URL, path or e-mail). collect()'s wire order is
 * depth-first + deterministic, so a same-length translations array maps back 1:1
 * in apply().
 */
final class TranslatableJsonWalker
{
    /** Keys whose string values are never copy. */
    private const SKIP_KEYS = [
        'href', 'url', 'icon', 'image', 'img', 'slug', 'slugs', 'id',
        'email', 'phone', 'kind', 'variant', 'anchor', 'target',
    ];

    /** @param array<string,mixed> $value @return list<string> */
    public function collect(array $value): array
    {
        $out = [];
        $this->walk($value, static function (string $text) use (&$out): string {
            $out[] = $text;
            return $text;
        });
        return $out;
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $translations same length/order as collect()
     * @return array<string,mixed>
     */
    public function apply(array $value, array $translations): array
    {
        $i = 0;
        return $this->walk($value, static function (string $text) use (&$i, $translations): string {
            return $translations[$i++] ?? $text;
        });
    }

    /**
     * @param array<string,mixed> $value
     * @param callable(string):string $visit
     * @return array<string,mixed>
     */
    private function walk(array $value, callable $visit): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->walk($item, $visit);
                continue;
            }
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            if (is_string($key) && in_array(strtolower($key), self::SKIP_KEYS, true)) {
                continue;
            }
            if (self::looksNonCopy($item)) {
                continue;
            }
            $value[$key] = $visit($item);
        }
        return $value;
    }

    private static function looksNonCopy(string $s): bool
    {
        $t = trim($s);
        return preg_match('~^(https?://|/|#|mailto:|tel:)~i', $t) === 1
            || preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $t) === 1;
    }
}

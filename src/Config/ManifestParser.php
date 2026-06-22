<?php

declare(strict_types=1);

namespace XPHP\Config;

use JsonException;
use RuntimeException;

/**
 * Parses an `xphp.json` manifest string into a {@see Manifest}. Pure (string → value object): no
 * filesystem access, so it's unit-testable in isolation; {@see ManifestResolver} handles reading
 * files and resolving paths.
 *
 * Plain JSON via the built-in `json_decode` — no JSON5 / extra dependency. Malformed JSON or a
 * wrong-typed known key (`sources`/`include` not arrays of strings, `target`/`cache` not strings)
 * is a hard error. Unknown keys are ignored (forward-compatible with future schema additions).
 */
final class ManifestParser
{
    /**
     * @param string $label Human-readable source name for error messages (e.g. the manifest path).
     * @throws RuntimeException on malformed JSON or an invalid field shape.
     */
    public function parse(string $json, string $label = 'xphp.json'): Manifest
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid %s: %s', $label, $e->getMessage()), previous: $e);
        }

        // `{}` and `[]` both decode to `[]`; treat the empty case as an empty object (defaults).
        // Only a non-empty JSON array (a list) is a genuine "not an object" error.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException(sprintf('Invalid %s: top level must be a JSON object.', $label));
        }

        return new Manifest(
            self::stringList($data, 'sources', $label) ?? ['.'],
            self::stringList($data, 'include', $label) ?? [],
            self::optionalString($data, 'target', $label),
            self::optionalString($data, 'cache', $label),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return ?list<string> null when the key is absent (caller applies its default).
     */
    private static function stringList(array $data, string $key, string $label): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('Invalid %s: "%s" must be an array of strings.', $label, $key));
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new RuntimeException(sprintf('Invalid %s: "%s" must contain only strings.', $label, $key));
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key, string $label): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Invalid %s: "%s" must be a string.', $label, $key));
        }

        return $value;
    }
}

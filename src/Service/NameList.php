<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parsing of the comma-separated author and tag inputs.
 */
final class NameList
{
    /**
     * Splits on commas, trims, collapses inner whitespace, drops empties and
     * de-duplicates ignoring case (the first spelling wins).
     *
     * @return list<string>
     */
    public static function parse(string $input): array
    {
        $names = [];
        $seen = [];

        foreach (explode(',', $input) as $part) {
            $name = self::collapse($part);
            if ('' === $name) {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Splits one author entry at its last space; a single word becomes a last
     * name with an empty first name.
     *
     * @return array{firstName: string, lastName: string}
     */
    public static function splitAuthor(string $entry): array
    {
        $entry = self::collapse($entry);
        $position = strrpos($entry, ' ');

        if (false === $position) {
            return ['firstName' => '', 'lastName' => $entry];
        }

        return [
            'firstName' => substr($entry, 0, $position),
            'lastName' => substr($entry, $position + 1),
        ];
    }

    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

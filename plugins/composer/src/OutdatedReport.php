<?php

declare(strict_types=1);

namespace Porta\Composer;

use Porta\Support\Decoded;

/**
 * What `composer outdated --format=json` printed, read: `{"installed": [...]}`,
 * one entry per package, `latest-status` saying how far behind it is.
 */
final class OutdatedReport
{
    /**
     * @return list<OutdatedPackage> what is behind or abandoned, by name
     *
     * @throws \RuntimeException when $json is not such a report
     */
    public static function parse(string $json): array
    {
        try {
            // @mago-expect analysis:mixed-assignment -- decoded JSON, narrowed on the next line
            $decoded = \json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('composer outdated printed no report', previous: $error);
        }
        if (!\is_array($decoded) || !\is_array($decoded['installed'] ?? null)) {
            throw new \RuntimeException('composer outdated printed no report');
        }
        $packages = [];
        foreach (Decoded::objects($decoded['installed']) as $entry) {
            $package = self::package($entry);
            if (null !== $package && ($package->behind() || null !== $package->abandoned)) {
                $packages[$package->name] = $package;
            }
        }
        \ksort($packages);

        return \array_values($packages);
    }

    /** @param array<array-key, mixed> $entry */
    private static function package(array $entry): ?OutdatedPackage
    {
        [$name, $version, $latest, $status] = [self::text($entry, 'name'), self::text($entry, 'version'), self::text($entry, 'latest'), self::text($entry, 'latest-status')];
        if (\in_array('', [$name, $version, $latest], strict: true)) {
            return null;
        }
        // @mago-expect analysis:mixed-assignment -- decoded JSON, narrowed by the match below
        $abandoned = $entry['abandoned'] ?? false;

        return new OutdatedPackage(
            \strtolower($name),
            $version,
            $latest,
            '' === $status ? 'update-possible' : $status,
            match (true) {
                \is_string($abandoned) => $abandoned,
                true === $abandoned => '',
                default => null,
            },
        );
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return string '' when it is missing or not a string
     */
    private static function text(array $entry, string $key): string
    {
        return \is_string($entry[$key] ?? null) ? $entry[$key] : '';
    }
}

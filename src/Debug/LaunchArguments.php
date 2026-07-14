<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Builds one DAP `launch` request body: the built-in template (run the current
 * file in the project root) merged under the user's per-language overrides,
 * with `${file}` / `${workspaceRoot}` substituted into every string value.
 */
final class LaunchArguments
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function build(string $file, string $rootDir, array $overrides): array
    {
        $arguments = array_replace(
            ['program' => '${file}', 'cwd' => '${workspaceRoot}', 'stopOnEntry' => false],
            $overrides,
        );

        return self::substitute($arguments, $file, $rootDir);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function substitute(array $values, string $file, string $rootDir): array
    {
        /** @var mixed $value */
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = str_replace(['${file}', '${workspaceRoot}'], [$file, $rootDir], $value);
            } elseif (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $values[$key] = self::substitute($value, $file, $rootDir);
            }
        }

        return $values;
    }
}

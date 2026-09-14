<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Builds one DAP `launch` request body: the built-in template (run the current
 * file in the project root) merged under the user's per-language overrides,
 * with `${file}` / `${workspaceRoot}` substituted into every string value. A
 * configured `runCommand` (e.g. `frankenphp php-cli`) seeds `runtimeExecutable`
 * / `runtimeArgs` in the template, which the user's `launch` block can still
 * override.
 */
final class LaunchArguments
{
    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $runCommandArgv
     *
     * @return array<string, mixed>
     */
    public static function build(string $file, string $rootDir, array $overrides, array $runCommandArgv = []): array
    {
        $template = ['program' => '${file}', 'cwd' => '${workspaceRoot}', 'stopOnEntry' => false];
        if ([] !== $runCommandArgv) {
            $template['runtimeExecutable'] = $runCommandArgv[0];
            if (\count($runCommandArgv) > 1) {
                $template['runtimeArgs'] = \array_slice($runCommandArgv, offset: 1);
            }
        }
        $arguments = \array_replace($template, $overrides);

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
                $values[$key] = \str_replace(['${file}', '${workspaceRoot}'], [$file, $rootDir], $value);

                continue;
            }
            if (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $values[$key] = self::substitute($value, $file, $rootDir);
            }
        }

        return $values;
    }
}

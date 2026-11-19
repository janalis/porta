<?php

declare(strict_types=1);

/*
 * Updates an installed desktop application from a package on disk, through the
 * real update code and the real system tools: only GitHub is played by this
 * script. CI runs it to update one build with the next.
 *
 * Usage: php update-harness.php <installed porta.phar> <new package> <tag>
 */

use Porta\Console\DesktopUpdate;
use Porta\Console\SelfUpdate;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';

[, $phar, $package, $tag] = $argv;
$name = trim((string) file_get_contents(dirname($phar).'/desktop-package'));
$sums = sys_get_temp_dir().'/porta-harness-'.bin2hex(random_bytes(4)).'.sha256';
file_put_contents($sums, hash_file('sha256', $package).'  '.$name."\n");
$assets = [$name => $package, 'porta-desktop.sha256' => $sums];

$run = static function (array $command, ?string $stdin = null) use ($assets, $tag): array {
    if ('curl' === $command[0]) {
        $out = array_search('-o', $command, strict: true);
        if (false !== $out) {
            $asset = substr((string) end($command), strlen('https://harness/'));

            return [copy($assets[$asset], $command[$out + 1]) ? 0 : 1, ''];
        }
        $list = array_map(static fn (string $asset): array => ['name' => $asset, 'url' => 'https://harness/'.$asset], array_keys($assets));

        return [0, (string) json_encode(['tag_name' => $tag, 'assets' => $list], JSON_THROW_ON_ERROR)];
    }
    $process = new Process($command, timeout: 600);
    $process->run();
    fwrite(STDERR, '  ran '.implode(' ', $command).' → '.$process->getExitCode()."\n");

    return [(int) $process->getExitCode(), $process->getOutput()];
};

new DesktopUpdate(new SelfUpdate($run, []), $run)->install($tag, new Installation(InstallMethod::Desktop, $phar));
unlink($sums);
echo "update-harness: installed {$tag}\n";

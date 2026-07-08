<?php

declare(strict_types=1);

use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Git\GitInfo;
use Porta\History\LocalHistory;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/**
 * Porta's service definitions. Lives outside src/ so this wiring stays off the
 * 100%-line-coverage gate (phpunit's <source> includes only src/). Every
 * service is shared (the DI default), matching the app-lifetime-singleton
 * lifecycle; never use lazy: true — it would proxy-subclass a final class.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Public: Ide resolves these from the container by id (service-locator
    // boundary), so they must survive compilation rather than being inlined.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->public()
        ->bind('$rootDir', param('porta.root_dir'))
        ->bind('$locale', param('porta.locale'));

    // Closure-free leaves: only scalar (rootDir/locale) dependencies.
    $services->set(Translator::class);
    $services->set(GitInfo::class);
    $services->set(LocalHistory::class);
    $services->set(SessionStore::class);
    $services->set(EditorConfigResolver::class);
};

<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * A feature core uses when a plugin lends it one, and does without when none
 * does — {@see PluginApi::provide()}. Each case says the shape of the closure
 * a plugin provides for it; the first plugin to provide a capability is the
 * one core uses, and switching that plugin off hands it to the next.
 */
enum Capability: string
{
    /**
     * An SVG document drawn as a PNG:
     * `\Closure(string $svg, int $width, \Closure(?string): void $onPng): void`.
     * It draws the document $width pixels wide and answers exactly once, with
     * the PNG or with null when it cannot. Each call owns its own process.
     *
     * Core draws the SVG pictures of plugin pages with it, before it falls
     * back to `rsvg-convert`. Those documents come off the network: core
     * hands over only one that references nothing outside itself.
     */
    case SvgRasterizer = 'svg-rasterizer';
}

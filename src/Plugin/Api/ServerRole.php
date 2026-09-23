<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * What a plugin means by claiming a language:
 * "I am the language server for this", or "I have things to add to whoever
 * is".
 *
 * Before this the answer was load order, which is the directory glob, which is
 * alphabetical — so `symfony-lsp` lost `php` to `phpantom` because of its
 * name, and would have won it back by being renamed. Symfony's language tools
 * is built to run BESIDE a general PHP server: what it adds is route names,
 * service ids, translation ids and Twig paths, not a second opinion on where
 * a method is defined.
 */
enum ServerRole
{
    /**
     * The server for this language. The first plugin to claim it this way
     * wins and the rest are dropped — two general servers for one language is
     * double the processes for one answer, and neither is the right one to
     * ask.
     */
    case Primary;

    /**
     * A server that adds to the primary rather than replacing it.
     *
     * It is PROMOTED to primary when nothing else claims the language, which
     * is what keeps symfony-lsp serving php on a project with no PHP server
     * enabled — the behaviour its documentation already promised, minus the
     * accident that produced it.
     */
    case Supplementary;
}

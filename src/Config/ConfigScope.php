<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Which of the two config files a write lands in.
 *
 * `Project` is `<root>/.porta.json`, the file that has always existed and that
 * a team usually means to share. `Global` is `<home>/.porta/config.json`, the
 * one nobody had — which is why changing your theme used to dirty the
 * repository.
 *
 * Deliberately NOT `<home>/.porta.json`: `porta ~` is a thing people do, and it
 * would collapse the two layers into one file, where a "project" write and a
 * "global" write would silently be the same write.
 *
 * The plugin installer's scope vocabulary looks like this one and is not it: it
 * has a third value, `embedded`, for plugins shipped inside the package. There
 * is no embedded CONFIG — nothing writes to the package — so widening this enum
 * to absorb that string would add a case every consumer must handle and no
 * consumer can reach. The two stay separate.
 */
enum ConfigScope: string
{
    case Global = 'global';
    case Project = 'project';

    /**
     * Lowest precedence first, which is the order a merge applies them in:
     * global sets the baseline, project overrides it.
     *
     * @return list<self>
     */
    public static function inPrecedenceOrder(): array
    {
        return [self::Global, self::Project];
    }
}

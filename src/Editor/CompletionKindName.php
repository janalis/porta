<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\I18n\Translator;

/**
 * The completion popup's kind tags that are abbreviations, in words. The tags
 * are cut to keep the popup narrow ({@see \Porta\Lsp\LspMapper::COMPLETION_KINDS}),
 * which is fine for the ones everyone reads at a glance and a guess for the
 * rest; a tag that is already a word has no entry here.
 */
final class CompletionKindName
{
    /** The kind tag $tag in words, or null when the tag already is one. */
    public static function of(string $tag, Translator $translator): ?string
    {
        return match ($tag) {
            'fn' => $translator->trans('Function'),
            'ctor' => $translator->trans('Constructor'),
            'iface' => $translator->trans('Interface'),
            'prop' => $translator->trans('Property'),
            'var' => $translator->trans('Variable'),
            'const' => $translator->trans('Constant'),
            'ref' => $translator->trans('Reference'),
            'op' => $translator->trans('Operator'),
            'case' => $translator->trans('Enum case'),
            default => null,
        };
    }
}

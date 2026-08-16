<?php

declare(strict_types=1);

namespace Porta\Config;

use Symfony\Component\Config\Definition\Builder\EnumNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\Exception as NodeException;

/**
 * Validates one .porta.json leaf against a closed set of allowed strings,
 * via symfony/config's own EnumNode rather than a hand-rolled `in_array`.
 * Returns null instead of throwing on a value outside the set: every
 * caller here wants to drop just this one bad leaf and keep validating its
 * siblings, never to reject the whole document.
 *
 * {@see \Symfony\Component\Config\Definition\Processor::process()} is not
 * used here: it merges a LIST of config layers into an array root, and its
 * merge step chokes on a bare scalar/enum root (there is no prior layer to
 * merge a single leaf against). Calling normalize()/finalize() directly on
 * the built node is the documented single-value path.
 */
final class FieldNode
{
    /** @param list<string> $values */
    public static function enum(array $values, mixed $raw): ?string
    {
        $builder = new TreeBuilder('value', 'enum');
        /** @var EnumNodeDefinition $root */
        $root = $builder->getRootNode();
        $root->values($values);
        $node = $builder->buildTree();
        try {
            /** @var string */
            return $node->finalize($node->normalize($raw));
        } catch (NodeException) {
            return null;
        }
    }
}

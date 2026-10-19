<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Which theme variables paint a family's unstyled elements, following each pinned `styles.ts`.
 * Each role lists variables in `a || b` order; authored styles keep their own colors.
 */
final readonly class MermaidRoles
{
    /**
     * @param list<string> $nodeFill
     * @param list<string> $nodeStroke
     * @param list<string> $nodeText
     * @param list<string> $edge
     * @param list<string> $edgeText
     * @param list<string> $caption
     * @param list<string> $clusterFill
     * @param list<string> $clusterStroke
     * @param list<string> $clusterText
     */
    public function __construct(
        public array $nodeFill = ['mainBkg'],
        public array $nodeStroke = ['nodeBorder'],
        public array $nodeText = ['nodeTextColor', 'textColor'],
        public array $edge = ['lineColor'],
        public array $edgeText = ['nodeTextColor', 'textColor'],
        public array $caption = ['edgeLabelBackground'],
        public array $clusterFill = ['clusterBkg'],
        public array $clusterStroke = ['clusterBorder'],
        public array $clusterText = ['titleColor'],
        public float $captionAlpha = 0.5,
        public bool $shadows = false,
        public bool $frameShadows = true,
        public bool $neoFrames = true,
        /** Whether outlines and edges take the theme `strokeWidth`: always, under the neo look, or never. */
        public string $widths = '',
        /** Whether the pinned renderer sketches the family under the hand-drawn look. */
        public bool $rough = false,
    ) {}

    public static function forKind(string $kind): self
    {
        return match ($kind) {
            'flowchart' => new self(shadows: true, widths: 'theme', rough: true),
            // Pinned class styles: class text and labels in `classText`, opaque label boxes.
            'class' => new self(nodeText: ['classText'], edgeText: ['classText'], caption: ['mainBkg'], captionAlpha: 1.0, shadows: true, widths: 'theme', rough: true),
            // Pinned agentflow styles: `.flow-cluster rect { fill: none; }`; slots come from the layout.
            'agentflow' => new self(clusterFill: ['=none'], shadows: true, widths: 'theme', rough: true),
            // Pinned neo styles: `[data-look="neo"].swimlane.cluster rect { filter: none; }`, and the
            // lane rules outrank the neo frame stroke, so lanes keep the cluster border.
            // Pinned ER styles: neo label boxes fade the tertiary color.
            'er' => new self(caption: ['tertiaryColor'], shadows: true, widths: 'neo', rough: true),
            // Pinned requirement styles: relationships in `relationColor`, opaque label boxes.
            'requirement' => new self(edge: ['relationColor', 'lineColor'], caption: ['requirementEdgeLabelBackground', 'edgeLabelBackground'], captionAlpha: 1.0, widths: 'neo', rough: true),
            // Pinned state styles; composite bodies take the composite background.
            'state' => new self(
                nodeFill: ['stateBkg', 'mainBkg'],
                nodeStroke: ['stateBorder', 'nodeBorder'],
                nodeText: ['stateLabelColor'],
                edge: ['transitionColor'],
                edgeText: ['transitionLabelColor', 'tertiaryTextColor'],
                captionAlpha: 1.0,
                clusterFill: ['compositeBackground', 'background'],
                clusterStroke: ['stateBorder', 'nodeBorder'],
                clusterText: ['stateLabelColor'],
                shadows: true,
                widths: 'theme',
                rough: true,
            ),
            // Pinned use-case styles: labels in the primary text color on opaque boxes.
            'usecase' => new self(
                edgeText: ['primaryTextColor'],
                captionAlpha: 1.0,
                clusterFill: ['usecaseBoundaryBkg', 'clusterBkg'],
                clusterStroke: ['usecaseBoundaryBorder', 'clusterBorder'],
                clusterText: ['titleColor', 'primaryTextColor'],
                shadows: true,
                rough: true,
            ),
            // Pinned sequence styles: messages in the signal colors; other parts are layout tokens.
            'sequence' => new self(edge: ['signalColor'], edgeText: ['signalTextColor'], shadows: true, widths: 'theme'),
            'swimlane' => new self(shadows: true, frameShadows: false, neoFrames: false, widths: 'theme', rough: true),
            // Pinned event modeling: relations in `emRelationStroke`, else the renderer's black.
            'eventmodeling' => new self(nodeText: ['textColor'], edge: ['emRelationStroke', '=#000000']),
            // The pinned info version line has no rule of its own: it inherits the root `fill: textColor`.
            'info' => new self(nodeText: ['textColor']),
            // Pinned renderers that sketch under the hand-drawn look and keep the default roles.
            'c4', 'mindmap', 'block', 'kanban', 'venn', 'ishikawa' => new self(rough: true),
            default => new self(),
        };
    }
}

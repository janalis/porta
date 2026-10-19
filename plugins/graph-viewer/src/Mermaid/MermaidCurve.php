<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** The pinned flowchart curve vocabulary, independent of browser layout engines. */
enum MermaidCurve: string
{
    case Linear = 'linear';
    case Basis = 'basis';
    case BumpX = 'bumpX';
    case BumpY = 'bumpY';
    case Cardinal = 'cardinal';
    case CatmullRom = 'catmullRom';
    case MonotoneX = 'monotoneX';
    case MonotoneY = 'monotoneY';
    case Natural = 'natural';
    case Step = 'step';
    case StepBefore = 'stepBefore';
    case StepAfter = 'stepAfter';
    case Rounded = 'rounded';

    /** Pinned insertEdge falls back to basis for every absent or unknown name, without an error. */
    public static function resolve(?string $name): self
    {
        return self::tryFrom($name ?? '') ?? self::Basis;
    }
}

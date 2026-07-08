<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor widget's outbound callbacks, built once by the coordinator's
 * wiring (and by tests, with only the hooks a scenario needs). CodeEditorWidget
 * and EditorActionRouter read this as one flat surface of ~two dozen hooks
 * (some by dynamic property name), so the class keeps every hook as a public
 * property; the constructor takes the five cohesive groups — one per receiving
 * controller — and fans them out, turning the old two-dozen-argument bag into
 * five intention-revealing objects at the construction sites.
 */
final readonly class EditorCallbacks
{
    public ?\Closure $onSaveRequested;
    public ?\Closure $onSaveAllRequested;
    public ?\Closure $onCopy;
    public ?\Closure $onFindRequested;
    public ?\Closure $onGotoFile;

    public ?\Closure $onDefinitionRequested;
    public ?\Closure $onImplementationRequested;
    public ?\Closure $onTypeDefinitionRequested;
    public ?\Closure $onNavigateBack;
    public ?\Closure $onQuickDoc;
    public ?\Closure $onFindUsages;
    public ?\Closure $onFileStructure;
    public ?\Closure $onPeek;

    public ?\Closure $onCompletionRequested;
    public ?\Closure $onCompletionResolve;
    public ?\Closure $onSignatureHelp;
    public ?\Closure $onChanged;

    public ?\Closure $onRename;
    public ?\Closure $onRenameRequested;
    public ?\Closure $onCodeAction;
    public ?\Closure $onReformat;

    public ?\Closure $onBufferCycle;
    public ?\Closure $onRecentFiles;
    public ?\Closure $onCloseBuffer;

    public function __construct(
        FileActionCallbacks $file = new FileActionCallbacks(),
        NavigationCallbacks $navigation = new NavigationCallbacks(),
        CompletionCallbacks $completion = new CompletionCallbacks(),
        RefactorCallbacks $refactor = new RefactorCallbacks(),
        BufferCallbacks $buffer = new BufferCallbacks(),
    ) {
        $this->onSaveRequested = $file->onSaveRequested;
        $this->onSaveAllRequested = $file->onSaveAllRequested;
        $this->onCopy = $file->onCopy;
        $this->onFindRequested = $file->onFindRequested;
        $this->onGotoFile = $file->onGotoFile;

        $this->onDefinitionRequested = $navigation->onDefinitionRequested;
        $this->onImplementationRequested = $navigation->onImplementationRequested;
        $this->onTypeDefinitionRequested = $navigation->onTypeDefinitionRequested;
        $this->onNavigateBack = $navigation->onNavigateBack;
        $this->onQuickDoc = $navigation->onQuickDoc;
        $this->onFindUsages = $navigation->onFindUsages;
        $this->onFileStructure = $navigation->onFileStructure;
        $this->onPeek = $navigation->onPeek;

        $this->onCompletionRequested = $completion->onCompletionRequested;
        $this->onCompletionResolve = $completion->onCompletionResolve;
        $this->onSignatureHelp = $completion->onSignatureHelp;
        $this->onChanged = $completion->onChanged;

        $this->onRename = $refactor->onRename;
        $this->onRenameRequested = $refactor->onRenameRequested;
        $this->onCodeAction = $refactor->onCodeAction;
        $this->onReformat = $refactor->onReformat;

        $this->onBufferCycle = $buffer->onBufferCycle;
        $this->onRecentFiles = $buffer->onRecentFiles;
        $this->onCloseBuffer = $buffer->onCloseBuffer;
    }
}

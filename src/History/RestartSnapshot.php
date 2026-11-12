<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\OpenAs;
use Porta\Editor\PreviewMode;
use Porta\Editor\ReloadMode;
use Porta\Support\Files;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** A private restart handoff. It never writes edited text over a project file. */
final readonly class RestartSnapshot
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    /** Capture before shutdown; the caller retains the directory until restore succeeds. */
    public function save(string $directory, string $root, BufferManager $buffers): bool
    {
        if (\is_link($directory) || \file_exists($directory)) {
            return false;
        }
        $this->filesystem->mkdir($directory, mode: 0o700);
        $entries = [];
        foreach ($buffers->all() as $buffer) {
            $document = $buffer->document;
            $entries[] = [
                'path' => $buffer->path,
                'bytes' => \base64_encode(($document->hasByteOrderMark ? "\xEF\xBB\xBF" : '').\str_replace("\n", $document->eol, $document->getText())),
                'dirty' => $document->isDirty(),
                'viewer' => $buffer->isViewer(),
                'preview' => $buffer->previewArrangement()?->value,
                'line' => $document->cursorLine,
                'col' => $document->cursorCol,
                'pinned' => $buffer->pinned,
                'disk' => \is_file($buffer->path) && \is_readable($buffer->path) ? \hash_file('sha256', $buffer->path) : null,
            ];
        }

        $written = JsonFile::write($this->filesystem, $directory.'/session.json', [
            'version' => 1,
            'root' => Files::canonical($root),
            'directory' => Files::canonical($directory),
            'active' => $buffers->active()?->path,
            'buffers' => $entries,
        ]);
        if ($written) {
            $this->filesystem->chmod($directory.'/session.json', mode: 0o600);
        }

        return $written;
    }

    /**
     * Restore into an empty buffer set. Validate the whole handoff before changing it.
     * The directory must be the caller's private handoff, never project-controlled input.
     *
     * @param (\Closure(string, OpenAs): ?Buffer)|null $open reopen through the live viewer and preview routing
     * @return list<string>|null paths changed on disk, or null for an invalid handoff
     */
    // @noinspection MultipleReturnStatementsInspection -- Reject the whole handoff before opening any buffer.
    public function restore(string $directory, string $root, BufferManager $buffers, ?\Closure $open = null): ?array
    {
        $data = JsonFile::read($directory.'/session.json');
        if (
            1 !== ($data['version'] ?? null)
            || Files::canonical($root) !== ($data['root'] ?? null)
            || Files::canonical($directory) !== ($data['directory'] ?? null)
            || !\is_array($data['buffers'] ?? null)
        ) {
            return null;
        }
        $entries = [];
        /** @var mixed $entry */
        foreach ($data['buffers'] as $entry) {
            if (
                !\is_array($entry)
                || !\is_string($entry['path'] ?? null)
                || !\is_string($entry['bytes'] ?? null)
                || !\is_bool($entry['dirty'] ?? null)
                || !\is_bool($entry['pinned'] ?? null)
                || !\is_int($entry['line'] ?? null)
                || !\is_int($entry['col'] ?? null)
            ) {
                return null;
            }
            $bytes = \base64_decode($entry['bytes'], strict: true);
            if (false === $bytes) {
                return null;
            }
            $entries[] = [$entry, $bytes];
        }
        $conflicts = [];
        foreach ($entries as [$entry, $bytes]) {
            $path = $entry['path'];
            $disk = \is_readable($path) ? Files::tryRead($path) : null;
            if (null === $disk && true === ($entry['viewer'] ?? false)) {
                $conflicts[] = $path;
                continue;
            }
            $as = true === ($entry['viewer'] ?? false) ? OpenAs::Viewer : OpenAs::Text;
            $opened = null === $open ? null : $open($path, $as);
            $buffer = $opened ?? $buffers->open($path, $disk ?? '');
            if ($entry['dirty'] || null === $disk) {
                $buffer->document->reload($bytes, ReloadMode::Recovery);
            }
            $buffer->document->setCursor($entry['line'], $entry['col']);
            $buffer->pinned = $entry['pinned'];
            $preview = \is_string($entry['preview'] ?? null) ? PreviewMode::tryFrom($entry['preview']) : null;
            if (null !== $preview) {
                $buffer->previewMode = $preview;
            }
            $fingerprint = \is_file($path) && \is_readable($path) ? \hash_file('sha256', $path) : null;
            if (($entry['dirty'] || null === $disk) && $fingerprint !== ($entry['disk'] ?? null)) {
                $conflicts[] = $path;
            }
        }
        /** @var mixed $active */
        $active = $data['active'] ?? null;
        if (\is_string($active) && $buffers->has($active)) {
            $buffers->setActive($active);
        }

        return $conflicts;
    }

    /** Remove only this handoff's known files; never recursively remove a supplied directory. */
    public function acknowledge(string $directory): bool
    {
        $removed = false;
        if (!\is_link($directory) && \is_dir($directory)) {
            try {
                foreach (['session.json', 'launch.json', 'restart.php', 'update-failed'] as $file) {
                    $path = $directory.'/'.$file;
                    if (\is_file($path)) {
                        $this->filesystem->remove($path);
                    }
                }
                $entries = \scandir($directory);
                $removed = false !== $entries && [] === \array_diff($entries, ['.', '..']) && \rmdir($directory);
            } catch (IOExceptionInterface) {
                return false;
            }
        }

        return $removed;
    }
}

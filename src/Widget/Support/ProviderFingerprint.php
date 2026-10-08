<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * The provider-closure pattern the strip widgets share: content is pulled from
 * a provider on each frame so the strip always reflects the model's state, and
 * a serialized fingerprint of what the provider answered detects "did the
 * content change?" for the repaint decision. The widget's beforeRender decides
 * WHAT to fingerprint (the whole answer, or a mapped slice of it) and calls
 * {@see refreshFingerprint} with it.
 *
 * @template TProvided
 */
trait ProviderFingerprint
{
    /** @var (callable(): TProvided)|null */
    private $provider;

    /** Serialized provider snapshot from the last repaint — content change-detection. */
    private string $lastFingerprint = '';

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /** @param callable(): TProvided $provider */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /** Repaint when the provided content changed since the last frame. */
    private function refreshFingerprint(mixed $provided): void
    {
        // @noinspection JsonEncodingApiUsageInspection -- a key, not a payload: a value that cannot be encoded maps to the same empty key, it does not throw
        $fingerprint = (string) \json_encode($provided);
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->invalidate();
        }
    }
}

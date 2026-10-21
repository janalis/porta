<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * Pure knowledge about one repository's GitHub releases: which asset fits
 * this machine ({@see GithubAsset}), where it downloads from, and how its
 * checksum is read out of the release's checksum file. No I/O —
 * {@see GithubInstaller} does the doing, which is what keeps every naming and
 * parsing rule unit-testable.
 */
final readonly class GithubRelease
{
    /** @param string $repo `owner/repo`, already checked to be nothing else ({@see ToolRequirement}) */
    public function __construct(
        public string $repo,
        public GithubAsset $asset,
    ) {}

    /**
     * The latest release tag out of GitHub's releases/latest JSON, or null
     * when unreadable — or when it does not look like a release tag. The tag
     * flows into the download URL and the on-disk tarball name, so a hostile
     * value (`../../x`) must die here, not become a path.
     */
    public function latestTag(string $json): ?string
    {
        /** @var array{tag_name?: mixed}|bool|int|float|string|null $decoded */
        // @noinspection JsonEncodingApiUsageInspection -- a peer's line that is not JSON is dropped, not thrown: it comes off the network
        $decoded = \json_decode($json, associative: true);
        $tag = \is_array($decoded) && \is_string($decoded['tag_name'] ?? null) ? $decoded['tag_name'] : '';

        return self::validTag($tag);
    }

    /**
     * The tag out of the `releases/latest` redirect's Location header, or null
     * when the header is absent or does not end in a release tag.
     *
     * Reads the LAST `location:` in the response, because the caller follows
     * redirects and earlier hops (http→https, a repo rename) carry their own.
     */
    public function tagFromLocation(string $headers): ?string
    {
        $matches = [];
        if (1 > (int) \preg_match_all('~^location:\s*(\S+)~mi', $headers, $matches)) {
            return null;
        }
        $last = (string) \end($matches[1]);
        $tag = \substr($last, (int) \strrpos($last, needle: '/') + 1);

        return \str_contains($last, '/releases/tag/') ? self::validTag($tag) : null;
    }

    /**
     * The web URL whose redirect names the latest release.
     *
     * Deliberately NOT `api.github.com`: that is 60 requests per hour per IP
     * unauthenticated, and spending the whole budget on a tag lookup is what
     * made symfony-lsp's "Install Server" fail with `could not resolve the
     * latest release` while nothing was wrong. This is ordinary release
     * traffic on the host {@see assetUrl()} and {@see sumsUrl()} already use.
     */
    public function latestUrl(): string
    {
        return 'https://github.com/'.$this->repo.'/releases/latest';
    }

    public function apiUrl(): string
    {
        return 'https://api.github.com/repos/'.$this->repo.'/releases/latest';
    }

    /**
     * $tag when it looks like a release tag, else null.
     *
     * Shared by both resolvers so they cannot disagree: the tag flows into the
     * download URL and the on-disk tarball name, so a hostile value
     * (`../../x`) must die here rather than become a path.
     */
    private static function validTag(string $tag): ?string
    {
        return 1 === \preg_match('/^v\d[0-9A-Za-z.\-]*$/', $tag) ? $tag : null;
    }

    /**
     * $tag as a bare version, so a tag and a `--version` line compare as one
     * spelling. A tag that is not shaped like one answers null rather than a
     * value that would silently never match.
     */
    public function versionOfTag(string $tag): ?string
    {
        $version = null === self::validTag($tag) ? null : \substr($tag, offset: 1);

        return null === $version || '' === $version ? null : $version;
    }

    public function assetUrl(string $tag, string $asset): string
    {
        return 'https://github.com/'.$this->repo.'/releases/download/'.$tag.'/'.$asset;
    }

    public function sumsUrl(string $tag): string
    {
        return $this->assetUrl($tag, $this->asset->sums);
    }

    /**
     * The sha256 the checksum file declares for $asset, or null when the file does
     * not mention it. Lines are the coreutils shape: `<hex><spaces><name>`.
     */
    public function expectedSha(string $sums, string $asset): ?string
    {
        foreach (\explode("\n", $sums) as $line) {
            $parts = \preg_split('/\s+/', \trim($line));
            if (\is_array($parts) && 2 === \count($parts) && \ltrim($parts[1], characters: '*') === $asset && 1 === \preg_match('/^[0-9a-f]{64}$/', $parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }
}

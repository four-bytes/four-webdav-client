<?php

declare(strict_types=1);

namespace Four\WebDav;

/**
 * Represents a file or directory entry from a WebDAV server.
 *
 * Populated from PROPFIND responses. All properties beyond name/path/isDirectory
 * are optional and may be zero/empty if the server doesn't provide them.
 */
readonly class WebDavItem
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDirectory,
        public int $size = 0,
        public string $etag = '',
        public int $mtime = 0,
        public string $contentType = '',
        public string $mountType = '',
    ) {}

    /**
     * Get human-readable file size.
     */
    public function getFormattedSize(): string
    {
        if ($this->size === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($this->size, 1024));

        return round($this->size / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'isDirectory' => $this->isDirectory,
            'size' => $this->size,
            'etag' => $this->etag,
            'mtime' => $this->mtime,
            'contentType' => $this->contentType,
            'mountType' => $this->mountType,
        ];
    }
}
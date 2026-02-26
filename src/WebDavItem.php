<?php

declare(strict_types=1);

namespace Four\WebDav;

use DateTime;

/**
 * WebDAV Item
 * 
 * Represents a file or directory in a WebDAV server.
 */
readonly class WebDavItem
{
    /**
     * Create a new WebDAV item.
     */
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDirectory,
        public int $size = 0,
        public ?DateTime $lastModified = null,
        public string $contentType = ''
    ) {}

    /**
     * Check if this item is a markdown file.
     */
    public function isMarkdownFile(): bool
    {
        return !$this->isDirectory && 
               (str_ends_with(strtolower($this->name), '.md') || 
                str_ends_with(strtolower($this->name), '.markdown'));
    }

    /**
     * Get human-readable file size.
     */
    public function getFormattedSize(): string
    {
        if ($this->size === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->size;
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Get formatted last modified date.
     */
    public function getFormattedLastModified(): ?string
    {
        return $this->lastModified?->format('Y-m-d H:i:s');
    }

    /**
     * Convert to array representation.
     */
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'isDirectory' => $this->isDirectory,
            'size' => $this->size,
            'formattedSize' => $this->getFormattedSize(),
            'lastModified' => $this->getFormattedLastModified(),
            'contentType' => $this->contentType,
            'isMarkdownFile' => $this->isMarkdownFile()
        ];
    }
}
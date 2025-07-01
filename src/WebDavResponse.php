<?php

declare(strict_types=1);

namespace Four\WebDav;

/**
 * WebDAV Response
 * 
 * Response object returned by WebDAV operations.
 */
readonly class WebDavResponse
{
    /**
     * Create a new WebDAV response.
     * 
     * @param bool $success Operation success status
     * @param string $message Status message
     * @param WebDavItem[] $items Array of WebDAV items
     * @param string|null $content File content (for downloads)
     * @param string|null $contentType MIME type (for downloads)
     */
    public function __construct(
        public bool $success = false,
        public string $message = '',
        public array $items = [],
        public ?string $content = null,
        public ?string $contentType = null
    ) {}
}
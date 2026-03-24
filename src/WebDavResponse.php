<?php

declare(strict_types=1);

namespace Four\WebDav;

/**
 * Response object returned by WebDAV operations.
 */
readonly class WebDavResponse
{
    /**
     * @param bool $success Operation success status
     * @param string $message Status message
     * @param WebDavItem[] $items Array of WebDAV items (from PROPFIND)
     * @param string|null $content File content (for downloads)
     * @param string|null $contentType MIME type (for downloads)
     * @param int $statusCode HTTP status code of the response
     */
    public function __construct(
        public bool $success = false,
        public string $message = '',
        public array $items = [],
        public ?string $content = null,
        public ?string $contentType = null,
        public int $statusCode = 0,
    ) {}
}
<?php

declare(strict_types=1);

namespace Four\WebDav;

use Psr\Log\LoggerInterface;

/**
 * Interface for WebDAV client operations.
 *
 * All paths are relative to the base URL provided at construction time.
 */
interface WebDavClientInterface
{
    public function setLogger(LoggerInterface $logger): void;
    public function getBaseUrl(): string;

    /** @return WebDavItem[] */
    public function listDirectory(string $path = '/'): array;

    /** @return WebDavItem[] */
    public function listRecursive(string $path = '/', ?callable $onDirectory = null): array;

    /** @return WebDavItem[] */
    public function listBreadthFirst(string $path = '/', ?array $initialEntries = null, ?callable $onDirectory = null): array;

    public function downloadFile(string $path): string;
    public function uploadFile(string $path, string $content, array $extraHeaders = []): void;
    public function createDirectory(string $path): void;
    public function createDirectoryRecursive(string $path): void;
    public function exists(string $path): bool;

    /** @return array{size: int, etag: string}|null */
    public function getFileInfo(string $path): ?array;

    public function setMtime(string $path, int $mtime): void;
}

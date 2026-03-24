<?php

declare(strict_types=1);

namespace Four\WebDav;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * WebDAV client using PSR-18 HTTP Client.
 *
 * All paths are relative to the base URL. The injected HTTP client
 * can be retrieved via getHttpClient() for advanced usage (e.g. casting
 * to Guzzle for async operations in a wrapper/subclass).
 */
class WebDavClient implements WebDavClientInterface
{
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected string $baseUrl;
    protected LoggerInterface $logger;
    private string $username;
    private string $password;

    public function __construct(
        string $baseUrl,
        string $username,
        string $password,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->logger = new NullLogger();
    }

    /**
     * Static factory using HttpClientFactory from four-http-client.
     * Falls back to constructor with explicit PSR interfaces.
     */
    public static function create(
        string $baseUrl,
        string $username,
        string $password,
    ): static {
        $factory = new \Four\Http\Factory\HttpClientFactory();
        $config = new \Four\Http\Configuration\ClientConfig(baseUri: rtrim($baseUrl, '/') . '/');
        $psrClient = $factory->create($config);

        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new static($baseUrl, $username, $password, $psrClient, $psr17, $psr17);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the injected PSR-18 HTTP client.
     * Callers can cast this to the concrete type (e.g. \GuzzleHttp\Client)
     * for async/streaming features.
     */
    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient;
    }

    // ─────────────────────────────────────────────────────────────
    // Directory Listing (PROPFIND)
    // ─────────────────────────────────────────────────────────────

    public function listDirectory(string $path = '/'): array
    {
        $encodedPath = $this->encodePath($path);
        $url = $this->buildFullUrl($encodedPath);

        $propfindBody = '<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:resourcetype/>
    <d:getcontentlength/>
    <d:getetag/>
    <d:getlastmodified/>
    <d:getcontenttype/>
    <nc:mount-type/>
  </d:prop>
</d:propfind>';

        $request = $this->createRequest('PROPFIND', $url)
            ->withHeader('Depth', '1')
            ->withHeader('Content-Type', 'application/xml')
            ->withBody($this->streamFactory->createStream($propfindBody));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV PROPFIND %s failed: HTTP %d — %s',
                    $path,
                    $statusCode,
                    (string) $response->getBody()
                ));
            }

            $fullUrlPath = parse_url($url, PHP_URL_PATH) ?? $encodedPath;
            return $this->parsePropfindResponse($response->getBody()->getContents(), $fullUrlPath);
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException(
                sprintf('WebDAV PROPFIND %s failed: %s', $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    public function listRecursive(string $path = '/', ?callable $onDirectory = null): array
    {
        $fileCount = 0;
        $visited = [];
        return $this->doListRecursive($path, $onDirectory, $fileCount, $visited);
    }

    public function listBreadthFirst(
        string $path = '/',
        ?array $initialEntries = null,
        ?callable $onDirectory = null,
    ): array {
        $files = [];
        $fileCount = 0;
        $queue = [];
        $visited = [];

        $entries = $initialEntries ?? $this->listDirectory($path);

        foreach ($entries as $entry) {
            if ($entry->isDirectory) {
                $normalized = rtrim($entry->path, '/');
                if (!isset($visited[$normalized])) {
                    $visited[$normalized] = true;
                    $queue[] = $entry->path;
                }
            } else {
                $files[] = $entry;
                $fileCount++;
            }
        }

        $dirsTotal = count($queue);
        $dirsProcessed = 0;

        while (!empty($queue)) {
            $dirPath = array_shift($queue);
            $dirsProcessed++;

            try {
                $dirEntries = $this->listDirectory($dirPath);
            } catch (\RuntimeException $e) {
                $this->logger->warning('PROPFIND failed during BFS, skipping directory', [
                    'dirPath' => $dirPath,
                    'error' => $e->getMessage(),
                ]);
                if ($onDirectory !== null) {
                    $onDirectory($dirPath, $dirsProcessed, $dirsTotal, $fileCount);
                }
                continue;
            }

            foreach ($dirEntries as $entry) {
                if ($entry->isDirectory) {
                    $normalized = rtrim($entry->path, '/');
                    if (!isset($visited[$normalized])) {
                        $visited[$normalized] = true;
                        $queue[] = $entry->path;
                        $dirsTotal++;
                    }
                } else {
                    $files[] = $entry;
                    $fileCount++;
                }
            }

            if ($onDirectory !== null) {
                $onDirectory($dirPath, $dirsProcessed, $dirsTotal, $fileCount);
            }
        }

        return $files;
    }

    // ─────────────────────────────────────────────────────────────
    // Download
    // ─────────────────────────────────────────────────────────────

    public function downloadFile(string $path): string
    {
        $encodedPath = $this->encodePath($path);
        $request = $this->createRequest('GET', $this->buildFullUrl($encodedPath));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV GET %s failed: HTTP %d',
                    $path,
                    $statusCode
                ));
            }

            return $response->getBody()->getContents();
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException(
                sprintf('WebDAV GET %s failed: %s', $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Upload
    // ─────────────────────────────────────────────────────────────

    public function uploadFile(string $path, string $content, array $extraHeaders = []): void
    {
        $encodedPath = $this->encodePath($path);
        $request = $this->createRequest('PUT', $this->buildFullUrl($encodedPath))
            ->withBody($this->streamFactory->createStream($content));

        foreach ($extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV PUT %s failed: HTTP %d — %s',
                    $path,
                    $statusCode,
                    (string) $response->getBody()
                ));
            }
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException(
                sprintf('WebDAV PUT %s failed: %s', $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Directory Operations
    // ─────────────────────────────────────────────────────────────

    public function createDirectory(string $path): void
    {
        $encodedPath = $this->encodePath($path);
        $request = $this->createRequest('MKCOL', $this->buildFullUrl($encodedPath));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            // 405 = already exists — that's OK
            if ($statusCode === 405) {
                return;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV MKCOL %s failed: HTTP %d — %s',
                    $path,
                    $statusCode,
                    (string) $response->getBody()
                ));
            }
        } catch (ClientExceptionInterface $e) {
            // Some PSR-18 clients throw on 4xx/5xx — check if it's a 405
            $message = $e->getMessage();
            if (str_contains($message, '405')) {
                return;
            }
            throw new \RuntimeException(
                sprintf('WebDAV MKCOL %s failed: %s', $path, $message),
                $e->getCode(),
                $e
            );
        }
    }

    public function createDirectoryRecursive(string $path): void
    {
        $parts = explode('/', trim($path, '/'));
        $current = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            $this->createDirectory($current);
        }
    }

    public function exists(string $path): bool
    {
        $encodedPath = $this->encodePath($path);
        $request = $this->createRequest('PROPFIND', $this->buildFullUrl($encodedPath))
            ->withHeader('Depth', '0')
            ->withBody($this->streamFactory->createStream(
                '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>'
            ));

        try {
            $response = $this->httpClient->sendRequest($request);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (ClientExceptionInterface) {
            return false;
        }
    }

    public function getFileInfo(string $path): ?array
    {
        $encodedPath = $this->encodePath($path);

        $propfindBody = '<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:getcontentlength/>
    <d:getetag/>
  </d:prop>
</d:propfind>';

        $request = $this->createRequest('PROPFIND', $this->buildFullUrl($encodedPath))
            ->withHeader('Depth', '0')
            ->withHeader('Content-Type', 'application/xml')
            ->withBody($this->streamFactory->createStream($propfindBody));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return null;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV PROPFIND %s failed: HTTP %d',
                    $path,
                    $statusCode
                ));
            }

            $xml = $response->getBody()->getContents();

            $prevUseErrors = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            libxml_use_internal_errors($prevUseErrors);

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');

            $size = 0;
            $sizeNode = $xpath->query('//d:getcontentlength')->item(0);
            if ($sizeNode) {
                $size = (int) $sizeNode->textContent;
            }

            $etag = '';
            $etagNode = $xpath->query('//d:getetag')->item(0);
            if ($etagNode) {
                $etag = trim($etagNode->textContent, '"');
            }

            return ['size' => $size, 'etag' => $etag];
        } catch (ClientExceptionInterface $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new \RuntimeException(
                sprintf('WebDAV PROPFIND %s failed: %s', $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    public function setMtime(string $path, int $mtime): void
    {
        $encodedPath = $this->encodePath($path);

        $body = '<?xml version="1.0"?>
<d:propertyupdate xmlns:d="DAV:">
  <d:set>
    <d:prop>
      <d:lastmodified>' . $mtime . '</d:lastmodified>
    </d:prop>
  </d:set>
</d:propertyupdate>';

        $request = $this->createRequest('PROPPATCH', $this->buildFullUrl($encodedPath))
            ->withHeader('Content-Type', 'application/xml')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'WebDAV PROPPATCH %s failed: HTTP %d — %s',
                    $path,
                    $statusCode,
                    (string) $response->getBody()
                ));
            }
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException(
                sprintf('WebDAV PROPPATCH %s failed: %s', $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Generic request (for extensions/subclasses)
    // ─────────────────────────────────────────────────────────────

    /**
     * Send a raw WebDAV request. For custom methods (MOVE, COPY, LOCK etc.).
     *
     * @param array<string, string> $headers
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendRequest(string $method, string $path, array $headers = [], ?string $body = null): \Psr\Http\Message\ResponseInterface
    {
        $encodedPath = $this->encodePath($path);
        $request = $this->createRequest($method, $this->buildFullUrl($encodedPath));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException(
                sprintf('WebDAV %s %s failed: %s', $method, $path, $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Protected: URL Building (accessible to subclasses)
    // ─────────────────────────────────────────────────────────────

    /**
     * Encode a file path for WebDAV, encoding each segment individually.
     * Uses SabreDAV-compatible encoding.
     */
    protected function encodePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $segments = explode('/', $path);
        $encoded = array_map(function (string $segment): string {
            if ($segment === '') {
                return '';
            }
            return strtr(rawurlencode($segment), [
                '%21' => '!',
                '%24' => '$',
                '%26' => '&',
                '%27' => "'",
                '%28' => '(',
                '%29' => ')',
                '%2A' => '*',
                '%2B' => '+',
                '%2C' => ',',
                '%3B' => ';',
                '%3D' => '=',
                '%3A' => ':',
                '%40' => '@',
            ]);
        }, $segments);
        return implode('/', $encoded);
    }

    /**
     * Build a full absolute URL from an encoded path.
     */
    protected function buildFullUrl(string $encodedPath): string
    {
        return $this->baseUrl . $encodedPath;
    }

    // ─────────────────────────────────────────────────────────────
    // Protected: PROPFIND XML Parsing (accessible to subclasses)
    // ─────────────────────────────────────────────────────────────

    /**
     * Parse a PROPFIND multistatus XML response into WebDavItem[].
     *
     * @param string $xml          Raw XML response body
     * @param string $requestPath  Full URL path of the PROPFIND request (for parent-entry filtering)
     * @return WebDavItem[]
     */
    protected function parsePropfindResponse(string $xml, string $requestPath): array
    {
        $prevUseErrors = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        libxml_use_internal_errors($prevUseErrors);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('oc', 'http://owncloud.org/ns');
        $xpath->registerNamespace('nc', 'http://nextcloud.org/ns');

        $entries = [];
        $responses = $xpath->query('//d:response');

        foreach ($responses as $response) {
            $href = $xpath->query('d:href', $response)->item(0)?->textContent ?? '';

            // Skip the parent directory entry
            $decodedHref = urldecode($href);
            $decodedRequest = urldecode($requestPath);
            if (rtrim($decodedHref, '/') === rtrim($decodedRequest, '/')) {
                continue;
            }

            $resourceType = $xpath->query('.//d:resourcetype/d:collection', $response);
            $isDirectory = $resourceType->length > 0;

            $size = 0;
            $sizeNode = $xpath->query('.//d:getcontentlength', $response)->item(0);
            if ($sizeNode) {
                $size = (int) $sizeNode->textContent;
            }

            $etag = '';
            $etagNode = $xpath->query('.//d:getetag', $response)->item(0);
            if ($etagNode) {
                $etag = trim($etagNode->textContent, '"');
            }

            $contentType = '';
            $contentTypeNode = $xpath->query('.//d:getcontenttype', $response)->item(0);
            if ($contentTypeNode) {
                $contentType = $contentTypeNode->textContent;
            }

            $mountType = '';
            $mountTypeNode = $xpath->query('.//nc:mount-type', $response)->item(0);
            if ($mountTypeNode) {
                $mountType = $mountTypeNode->textContent;
            }

            $mtime = 0;
            $mtimeNode = $xpath->query('.//d:getlastmodified', $response)->item(0);
            if ($mtimeNode) {
                $mtime = strtotime($mtimeNode->textContent) ?: 0;
            }

            $relativePath = $this->extractRelativePath($href);
            $name = basename(urldecode($href));

            $entries[] = new WebDavItem(
                name: $name,
                path: $relativePath,
                isDirectory: $isDirectory,
                size: $size,
                etag: $etag,
                mtime: $mtime,
                contentType: $contentType,
                mountType: $mountType,
            );
        }

        return $entries;
    }

    /**
     * Extract a clean relative path from a WebDAV href.
     * Strips the base URL path prefix.
     */
    protected function extractRelativePath(string $href): string
    {
        $decoded = urldecode($href);

        $basePath = parse_url($this->baseUrl, PHP_URL_PATH) ?? '';
        $basePath = rtrim($basePath, '/');

        if ($basePath !== '' && str_starts_with($decoded, $basePath)) {
            $relative = substr($decoded, strlen($basePath));
            return $relative === '' ? '/' : rtrim($relative, '/');
        }

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Create a PSR-7 request with Basic Auth header.
     */
    private function createRequest(string $method, string $url): \Psr\Http\Message\RequestInterface
    {
        return $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password));
    }

    /**
     * @return WebDavItem[]
     */
    private function doListRecursive(string $path, ?callable $onDirectory, int &$fileCount, array &$visited = []): array
    {
        $entries = $this->listDirectory($path);
        $files = [];

        foreach ($entries as $entry) {
            if ($entry->isDirectory) {
                $normalized = rtrim($entry->path, '/');
                if (isset($visited[$normalized])) {
                    continue;
                }
                $visited[$normalized] = true;

                if ($onDirectory !== null) {
                    $onDirectory($entry->path, $fileCount);
                }
                $subFiles = $this->doListRecursive($entry->path, $onDirectory, $fileCount, $visited);
                $files = array_merge($files, $subFiles);
            } else {
                $files[] = $entry;
                $fileCount++;
            }
        }

        return $files;
    }
}

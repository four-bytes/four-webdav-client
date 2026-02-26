<?php

declare(strict_types=1);

namespace Four\WebDav;

use DateTime;
use Four\Http\Configuration\ClientConfig;
use Four\Http\Factory\HttpClientFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SimpleXMLElement;

/**
 * WebDAV Client for PHP
 *
 * A modern WebDAV client for Nextcloud, ownCloud, and other WebDAV servers.
 */
class WebDavClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * Create a new WebDAV client instance.
     */
    public function __construct(
        string $baseUrl,
        string $username,
        string $password,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->username = $username;
        $this->password = $password;

        if ($httpClient === null || $requestFactory === null || $streamFactory === null) {
            $factory = new HttpClientFactory();
            $config = new ClientConfig(baseUri: $this->baseUrl);
            $builtClient = $factory->create($config);

            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

            $this->httpClient = $httpClient ?? $builtClient;
            $this->requestFactory = $requestFactory ?? $psr17;
            $this->streamFactory = $streamFactory ?? $psr17;
        } else {
            $this->httpClient = $httpClient;
            $this->requestFactory = $requestFactory;
            $this->streamFactory = $streamFactory;
        }
    }

    /**
     * Create a WebDAV client using HttpClientFactory defaults.
     */
    public static function create(string $baseUrl, string $username, string $password): self
    {
        $factory = new HttpClientFactory();
        $config = new ClientConfig(baseUri: rtrim($baseUrl, '/') . '/');
        $psrClient = $factory->create($config);

        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new self($baseUrl, $username, $password, $psrClient, $psr17, $psr17);
    }

    /**
     * Get full URL for a path.
     */
    public function getFullPath(string $path): string
    {
        return $this->baseUrl . ltrim($path, '/');
    }

    /**
     * List directory contents.
     */
    public function list(string $path = ''): WebDavResponse
    {
        try {
            $response = $this->request('PROPFIND', $path);

            if (intval($response->getStatusCode() / 100) !== 2) {
                return new WebDavResponse(
                    success: false,
                    message: $response->getReasonPhrase()
                );
            }

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return new WebDavResponse(
                    success: false,
                    message: 'Failed to parse XML response'
                );
            }

            $childResponses = $xml->children('DAV:');
            $items = [];
            $basePath = '';

            foreach ($childResponses as $childResponse) {
                $childPath = urldecode((string) $childResponse->href);

                if (!$basePath) {
                    $basePath = $childPath;
                } else {
                    $prop = $childResponse->propstat->prop;
                    $relativePath = trim(substr($childPath, strlen($basePath)), '/');
                    if ($relativePath) {
                        $items[] = $this->propToWebDavItem($prop, $relativePath);
                    }
                }
            }

            return new WebDavResponse(
                success: true,
                items: $items
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to list directory: ' . $e->getMessage()
            );
        }
    }

    /**
     * Download file content.
     */
    public function download(string $path): WebDavResponse
    {
        try {
            $response = $this->request('GET', $path);

            if (intval($response->getStatusCode() / 100) !== 2) {
                return new WebDavResponse(
                    success: false,
                    message: $response->getReasonPhrase()
                );
            }

            $content = $response->getBody()->getContents();
            $contentType = $response->getHeaderLine('Content-Type');

            return new WebDavResponse(
                success: true,
                content: $content,
                contentType: $contentType
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to download file: ' . $e->getMessage()
            );
        }
    }

    /**
     * Upload file from local path.
     */
    public function upload(string $remotePath, string $localPath): WebDavResponse
    {
        if (!file_exists($localPath)) {
            return new WebDavResponse(
                success: false,
                message: 'Local file does not exist: ' . $localPath
            );
        }

        $content = file_get_contents($localPath);
        if ($content === false) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to read local file: ' . $localPath
            );
        }

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

        return $this->uploadContent($remotePath, $content, $mimeType);
    }

    /**
     * Upload content directly.
     */
    public function uploadContent(string $path, string $content, string $mimeType = 'text/plain'): WebDavResponse
    {
        try {
            $headers = ['Content-Type' => $mimeType];
            $response = $this->request('PUT', $path, $headers, $content);

            $success = intval($response->getStatusCode() / 100) === 2;

            return new WebDavResponse(
                success: $success,
                message: $success ? 'File uploaded successfully' : $response->getReasonPhrase()
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to upload file: ' . $e->getMessage()
            );
        }
    }

    /**
     * Delete file or directory.
     */
    public function delete(string $path): WebDavResponse
    {
        try {
            $response = $this->request('DELETE', $path);

            $success = intval($response->getStatusCode() / 100) === 2;

            return new WebDavResponse(
                success: $success,
                message: $success ? 'File deleted successfully' : $response->getReasonPhrase()
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to delete file: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create directory.
     */
    public function createDirectory(string $path): WebDavResponse
    {
        try {
            $response = $this->request('MKCOL', $path);

            $success = intval($response->getStatusCode() / 100) === 2;

            return new WebDavResponse(
                success: $success,
                message: $success ? 'Directory created successfully' : $response->getReasonPhrase()
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to create directory: ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if path exists.
     */
    public function exists(string $path): bool
    {
        try {
            $response = $this->request('HEAD', $path);
            return intval($response->getStatusCode() / 100) === 2;
        } catch (ClientExceptionInterface $e) {
            return false;
        }
    }

    /**
     * Search for files by name pattern.
     */
    public function searchFiles(string $directory, string $pattern): WebDavResponse
    {
        $listResponse = $this->list($directory);

        if (!$listResponse->success) {
            return $listResponse;
        }

        $matchingFiles = array_filter($listResponse->items, function ($item) use ($pattern) {
            return !$item->isDirectory && fnmatch($pattern, $item->name);
        });

        return new WebDavResponse(
            success: true,
            items: array_values($matchingFiles)
        );
    }

    /**
     * Get file information.
     */
    public function getFileInfo(string $path): WebDavResponse
    {
        try {
            $response = $this->request('PROPFIND', $path, ['Depth' => '0']);

            if (intval($response->getStatusCode() / 100) !== 2) {
                return new WebDavResponse(
                    success: false,
                    message: $response->getReasonPhrase()
                );
            }

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return new WebDavResponse(
                    success: false,
                    message: 'Failed to parse XML response'
                );
            }

            $childResponses = $xml->children('DAV:');

            if (count($childResponses) > 0) {
                $prop = $childResponses[0]->propstat->prop;
                $filename = basename($path);
                $item = $this->propToWebDavItem($prop, $filename);

                return new WebDavResponse(
                    success: true,
                    items: [$item]
                );
            }

            return new WebDavResponse(
                success: false,
                message: 'File not found'
            );

        } catch (ClientExceptionInterface $e) {
            return new WebDavResponse(
                success: false,
                message: 'Failed to get file info: ' . $e->getMessage()
            );
        }
    }

    /**
     * Make HTTP request to WebDAV server.
     *
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): ResponseInterface {
        $url = $this->baseUrl . ltrim($path, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password))
            ->withHeader('User-Agent', 'Four-WebDAV-Client-PHP/2.0');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $stream = $this->streamFactory->createStream($body);
            $request = $request->withBody($stream);
        }

        return $this->httpClient->sendRequest($request);
    }

    /**
     * Convert WebDAV property to WebDavItem.
     */
    private function propToWebDavItem(SimpleXMLElement $element, string $path): WebDavItem
    {
        $dav = $element->children('DAV:');

        $isDirectory = isset($dav->resourcetype->collection);
        $lastModified = null;
        $size = 0;

        if (isset($dav->{'last-modified'})) {
            try {
                $lastModified = new DateTime((string) $dav->{'last-modified'});
            } catch (\Exception $e) {
                // Ignore invalid date formats
            }
        }

        if (isset($dav->{'content-length'})) {
            $size = intval((string) $dav->{'content-length'});
        }

        return new WebDavItem(
            name: basename($path),
            path: $path,
            isDirectory: $isDirectory,
            size: $size,
            lastModified: $lastModified,
            contentType: (string) ($dav->{'content-type'} ?? '')
        );
    }
}

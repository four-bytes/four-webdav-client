<?php

declare(strict_types=1);

namespace Four\WebDav\Tests\Unit;

use Four\WebDav\WebDavClient;
use Four\WebDav\WebDavItem;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

class WebDavClientTest extends TestCase
{
    private MockClient $mockHttpClient;
    private Psr17Factory $psr17Factory;
    private WebDavClient $client;

    private const BASE_URL = 'https://nextcloud.example.com/remote.php/dav/files/user';

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockClient();
        $this->psr17Factory = new Psr17Factory();
        $this->client = new WebDavClient(
            self::BASE_URL,
            'user',
            'password',
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Constructor / getters
    // ─────────────────────────────────────────────────────────────

    public function testGetBaseUrlStripsTrailingSlash(): void
    {
        $client = new WebDavClient(
            'https://example.com/dav/',
            'u',
            'p',
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
        );
        $this->assertSame('https://example.com/dav', $client->getBaseUrl());
    }

    public function testGetHttpClientReturnsInjectedClient(): void
    {
        $this->assertSame($this->mockHttpClient, $this->client->getHttpClient());
    }

    // ─────────────────────────────────────────────────────────────
    // downloadFile
    // ─────────────────────────────────────────────────────────────

    public function testDownloadFileReturnsContent(): void
    {
        $body = $this->psr17Factory->createStream('file content here');
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'text/plain'], $body)
        );

        $result = $this->client->downloadFile('test.txt');

        $this->assertSame('file content here', $result);
    }

    public function testDownloadFileThrowsOnError(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WebDAV GET.*failed.*404/');

        $this->client->downloadFile('missing.txt');
    }

    // ─────────────────────────────────────────────────────────────
    // uploadFile
    // ─────────────────────────────────────────────────────────────

    public function testUploadFileSucceeds(): void
    {
        $this->mockHttpClient->addResponse(new Response(201));

        // Must not throw
        $this->client->uploadFile('test.txt', 'Hello World');

        $requests = $this->mockHttpClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('PUT', $requests[0]->getMethod());
    }

    public function testUploadFileThrowsOnError(): void
    {
        $this->mockHttpClient->addResponse(new Response(500));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WebDAV PUT.*failed.*500/');

        $this->client->uploadFile('test.txt', 'content');
    }

    public function testUploadFileSendsExtraHeaders(): void
    {
        $this->mockHttpClient->addResponse(new Response(204));

        $this->client->uploadFile('test.txt', 'data', ['X-OC-Mtime' => '1700000000']);

        $requests = $this->mockHttpClient->getRequests();
        $this->assertSame('1700000000', $requests[0]->getHeaderLine('X-OC-Mtime'));
    }

    // ─────────────────────────────────────────────────────────────
    // createDirectory
    // ─────────────────────────────────────────────────────────────

    public function testCreateDirectorySucceeds(): void
    {
        $this->mockHttpClient->addResponse(new Response(201));

        $this->client->createDirectory('new-folder');

        $requests = $this->mockHttpClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('MKCOL', $requests[0]->getMethod());
    }

    public function testCreateDirectoryIgnores405(): void
    {
        $this->mockHttpClient->addResponse(new Response(405));

        // Must not throw
        $this->client->createDirectory('existing-folder');

        $this->assertTrue(true);
    }

    public function testCreateDirectoryThrowsOnOtherError(): void
    {
        $this->mockHttpClient->addResponse(new Response(403));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WebDAV MKCOL.*failed.*403/');

        $this->client->createDirectory('forbidden-folder');
    }

    // ─────────────────────────────────────────────────────────────
    // createDirectoryRecursive
    // ─────────────────────────────────────────────────────────────

    public function testCreateDirectoryRecursiveCreatesAllSegments(): void
    {
        // 3 MKCOL calls for a/b/c
        $this->mockHttpClient->addResponse(new Response(201));
        $this->mockHttpClient->addResponse(new Response(201));
        $this->mockHttpClient->addResponse(new Response(201));

        $this->client->createDirectoryRecursive('a/b/c');

        $requests = $this->mockHttpClient->getRequests();
        $this->assertCount(3, $requests);
        $this->assertSame('MKCOL', $requests[0]->getMethod());
        $this->assertSame('MKCOL', $requests[1]->getMethod());
        $this->assertSame('MKCOL', $requests[2]->getMethod());
    }

    // ─────────────────────────────────────────────────────────────
    // exists
    // ─────────────────────────────────────────────────────────────

    public function testExistsReturnsTrueOn200(): void
    {
        $this->mockHttpClient->addResponse(new Response(200));

        $this->assertTrue($this->client->exists('test.txt'));
    }

    public function testExistsReturnsTrueOn207(): void
    {
        $this->mockHttpClient->addResponse(new Response(207));

        $this->assertTrue($this->client->exists('test.txt'));
    }

    public function testExistsReturnsFalseOn404(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));

        $this->assertFalse($this->client->exists('missing.txt'));
    }

    // ─────────────────────────────────────────────────────────────
    // getFileInfo
    // ─────────────────────────────────────────────────────────────

    public function testGetFileInfoReturnsNullOn404(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));

        $result = $this->client->getFileInfo('missing.txt');

        $this->assertNull($result);
    }

    public function testGetFileInfoReturnsSizeAndEtag(): void
    {
        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/user/test.txt</d:href>
    <d:propstat>
      <d:prop>
        <d:getcontentlength>1234</d:getcontentlength>
        <d:getetag>"abc123"</d:getetag>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $body = $this->psr17Factory->createStream($xml);
        $this->mockHttpClient->addResponse(new Response(207, [], $body));

        $result = $this->client->getFileInfo('test.txt');

        $this->assertNotNull($result);
        $this->assertSame(1234, $result['size']);
        $this->assertSame('abc123', $result['etag']);
    }

    // ─────────────────────────────────────────────────────────────
    // listDirectory (PROPFIND)
    // ─────────────────────────────────────────────────────────────

    public function testListDirectoryReturnsItems(): void
    {
        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/files/user/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/></d:resourcetype>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/user/Documents/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/></d:resourcetype>
        <d:getlastmodified>Mon, 01 Jan 2024 00:00:00 GMT</d:getlastmodified>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/user/photo.jpg</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype/>
        <d:getcontentlength>204800</d:getcontentlength>
        <d:getetag>"deadbeef"</d:getetag>
        <d:getcontenttype>image/jpeg</d:getcontenttype>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $body = $this->psr17Factory->createStream($xml);
        $this->mockHttpClient->addResponse(new Response(207, [], $body));

        $items = $this->client->listDirectory('/');

        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(WebDavItem::class, $items);

        $dir = $items[0];
        $this->assertTrue($dir->isDirectory);
        $this->assertSame('Documents', $dir->name);

        $file = $items[1];
        $this->assertFalse($file->isDirectory);
        $this->assertSame('photo.jpg', $file->name);
        $this->assertSame(204800, $file->size);
        $this->assertSame('deadbeef', $file->etag);
        $this->assertSame('image/jpeg', $file->contentType);
    }

    public function testListDirectoryThrowsOnError(): void
    {
        $this->mockHttpClient->addResponse(new Response(403));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WebDAV PROPFIND.*failed.*403/');

        $this->client->listDirectory('/');
    }

    public function testListDirectorySendsPropfindDepth1(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"></d:multistatus>';
        $body = $this->psr17Factory->createStream($xml);
        $this->mockHttpClient->addResponse(new Response(207, [], $body));

        $this->client->listDirectory('/');

        $requests = $this->mockHttpClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('PROPFIND', $requests[0]->getMethod());
        $this->assertSame('1', $requests[0]->getHeaderLine('Depth'));
    }

    // ─────────────────────────────────────────────────────────────
    // setMtime
    // ─────────────────────────────────────────────────────────────

    public function testSetMtimeSucceeds(): void
    {
        $this->mockHttpClient->addResponse(new Response(207));

        $this->client->setMtime('file.txt', 1700000000);

        $requests = $this->mockHttpClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('PROPPATCH', $requests[0]->getMethod());
    }

    public function testSetMtimeThrowsOnError(): void
    {
        $this->mockHttpClient->addResponse(new Response(403));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WebDAV PROPPATCH.*failed.*403/');

        $this->client->setMtime('file.txt', 1700000000);
    }

    // ─────────────────────────────────────────────────────────────
    // sendRequest (generic)
    // ─────────────────────────────────────────────────────────────

    public function testSendRequestPassesThroughMethod(): void
    {
        $this->mockHttpClient->addResponse(new Response(204));

        $response = $this->client->sendRequest('DELETE', 'file.txt');

        $this->assertSame(204, $response->getStatusCode());

        $requests = $this->mockHttpClient->getRequests();
        $this->assertSame('DELETE', $requests[0]->getMethod());
    }

    public function testSendRequestPassesHeadersAndBody(): void
    {
        $this->mockHttpClient->addResponse(new Response(201));

        $this->client->sendRequest('COPY', 'file.txt', ['Destination' => '/other/file.txt'], 'body');

        $requests = $this->mockHttpClient->getRequests();
        $this->assertSame('/other/file.txt', $requests[0]->getHeaderLine('Destination'));
        $this->assertSame('body', (string) $requests[0]->getBody());
    }

    // ─────────────────────────────────────────────────────────────
    // Basic Auth header
    // ─────────────────────────────────────────────────────────────

    public function testRequestsSendBasicAuthHeader(): void
    {
        $this->mockHttpClient->addResponse(new Response(200));

        $this->client->downloadFile('file.txt');

        $requests = $this->mockHttpClient->getRequests();
        $expected = 'Basic ' . base64_encode('user:password');
        $this->assertSame($expected, $requests[0]->getHeaderLine('Authorization'));
    }

    // ─────────────────────────────────────────────────────────────
    // Path encoding
    // ─────────────────────────────────────────────────────────────

    public function testPathWithUmlautsIsEncoded(): void
    {
        $this->mockHttpClient->addResponse(new Response(200));

        $this->client->downloadFile('Ordner mit Umlauten/Datei ä ö ü.txt');

        $requests = $this->mockHttpClient->getRequests();
        $uri = (string) $requests[0]->getUri();
        $this->assertStringContainsString('%C3%A4', $uri);
        $this->assertStringNotContainsString(' ', $uri);
    }
}

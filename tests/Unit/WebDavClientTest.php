<?php

declare(strict_types=1);

namespace Four\WebDav\Tests\Unit;

use Four\WebDav\WebDavClient;
use Four\WebDav\WebDavItem;
use Four\WebDav\WebDavResponse;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

class WebDavClientTest extends TestCase
{
    private MockClient $mockHttpClient;
    private Psr17Factory $psr17Factory;
    private WebDavClient $client;

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockClient();
        $this->psr17Factory = new Psr17Factory();
        $this->client = new WebDavClient(
            'https://nextcloud.example.com/remote.php/dav/files/user/',
            'user',
            'password',
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
        );
    }

    public function testDownloadSuccess(): void
    {
        $body = $this->psr17Factory->createStream('file content here');
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'text/plain'], $body)
        );
        $result = $this->client->download('test.txt');
        $this->assertTrue($result->success);
        $this->assertSame('file content here', $result->content);
        $this->assertStringContainsString('text/plain', $result->contentType ?? '');
    }

    public function testDownloadFailure(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));
        $result = $this->client->download('missing.txt');
        $this->assertFalse($result->success);
    }

    public function testUploadContentSuccess(): void
    {
        $this->mockHttpClient->addResponse(new Response(201));
        $result = $this->client->uploadContent('test.txt', 'Hello World', 'text/plain');
        $this->assertTrue($result->success);
    }

    public function testUploadContentFailure(): void
    {
        $this->mockHttpClient->addResponse(new Response(500));
        $result = $this->client->uploadContent('test.txt', 'content', 'text/plain');
        $this->assertFalse($result->success);
    }

    public function testDeleteSuccess(): void
    {
        $this->mockHttpClient->addResponse(new Response(204));
        $result = $this->client->delete('test.txt');
        $this->assertTrue($result->success);
    }

    public function testDeleteFailure(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));
        $result = $this->client->delete('missing.txt');
        $this->assertFalse($result->success);
    }

    public function testCreateDirectorySuccess(): void
    {
        $this->mockHttpClient->addResponse(new Response(201));
        $result = $this->client->createDirectory('new-folder');
        $this->assertTrue($result->success);
    }

    public function testExistsReturnsTrue(): void
    {
        $this->mockHttpClient->addResponse(new Response(200));
        $this->assertTrue($this->client->exists('test.txt'));
    }

    public function testExistsReturnsFalse(): void
    {
        $this->mockHttpClient->addResponse(new Response(404));
        $this->assertFalse($this->client->exists('missing.txt'));
    }

    public function testGetFullPath(): void
    {
        $fullPath = $this->client->getFullPath('documents/file.txt');
        $this->assertStringContainsString('documents/file.txt', $fullPath);
    }
}

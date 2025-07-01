# Four WebDAV Client PHP

A modern PHP WebDAV client library for Nextcloud, ownCloud, and other WebDAV servers.

## Features

- **Modern PHP 8.1+** - Built with modern PHP features and type safety
- **PSR-4 Autoloading** - Standard PHP package structure
- **Comprehensive WebDAV Support** - All essential WebDAV operations
- **Server Compatibility** - Works with Nextcloud, ownCloud, Apache WebDAV, and more
- **Easy to Use** - Simple, intuitive API design
- **Well Tested** - Comprehensive test suite
- **Production Ready** - Used in production applications

## Installation

Install via Composer:

```bash
composer require four-bytes/four-webdav-client-php
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Four\WebDav\WebDavClient;

// Create client instance
$client = new WebDavClient(
    baseUrl: 'https://your-nextcloud.com/remote.php/dav/files/username/',
    username: 'your-username',
    password: 'your-password-or-app-token'
);

// List directory contents
$response = $client->list('/');
if ($response->success) {
    foreach ($response->items as $item) {
        echo $item->name . ($item->isDirectory ? '/' : '') . "\n";
    }
}

// Upload a file
$response = $client->uploadContent('/notes.md', '# My Notes', 'text/markdown');
if ($response->success) {
    echo "File uploaded successfully!\n";
}

// Download a file
$response = $client->download('/notes.md');
if ($response->success) {
    echo "File content: " . $response->content . "\n";
}
```

## API Reference

### WebDavClient

#### Constructor

```php
public function __construct(string $baseUrl, string $username, string $password)
```

- `$baseUrl` - WebDAV server base URL
- `$username` - Username for authentication
- `$password` - Password or app token for authentication

#### Methods

##### list(string $path = ''): WebDavResponse

List directory contents.

```php
$response = $client->list('/documents');
foreach ($response->items as $item) {
    echo "{$item->name} ({$item->formattedSize})\n";
}
```

##### download(string $path): WebDavResponse

Download file content.

```php
$response = $client->download('/document.txt');
if ($response->success) {
    file_put_contents('local-copy.txt', $response->content);
}
```

##### upload(string $remotePath, string $localPath): WebDavResponse

Upload file from local filesystem.

```php
$response = $client->upload('/remote/file.txt', '/local/file.txt');
```

##### uploadContent(string $path, string $content, string $mimeType = 'text/plain'): WebDavResponse

Upload content directly.

```php
$response = $client->uploadContent('/notes.md', '# Notes', 'text/markdown');
```

##### delete(string $path): WebDavResponse

Delete file or directory.

```php
$response = $client->delete('/old-file.txt');
```

##### createDirectory(string $path): WebDavResponse

Create directory.

```php
$response = $client->createDirectory('/new-folder');
```

##### exists(string $path): bool

Check if path exists.

```php
if ($client->exists('/important-file.txt')) {
    echo "File exists!\n";
}
```

##### searchFiles(string $directory, string $pattern): WebDavResponse

Search for files by name pattern.

```php
$response = $client->searchFiles('/', '*.md');
foreach ($response->items as $item) {
    echo "Found markdown file: {$item->name}\n";
}
```

##### getFileInfo(string $path): WebDavResponse

Get detailed file information.

```php
$response = $client->getFileInfo('/document.pdf');
if ($response->success) {
    $file = $response->items[0];
    echo "Size: {$file->formattedSize}\n";
    echo "Modified: {$file->getFormattedLastModified()}\n";
}
```

### WebDavResponse

Response object returned by all WebDAV operations.

```php
class WebDavResponse
{
    public bool $success;           // Operation success status
    public string $message;         // Status message
    public array $items;           // Array of WebDavItem objects
    public ?string $content;       // File content (for downloads)
    public ?string $contentType;   // MIME type (for downloads)
}
```

### WebDavItem

Represents a file or directory.

```php
class WebDavItem
{
    public string $name;               // File/directory name
    public string $path;               // Full path
    public bool $isDirectory;          // Is directory flag
    public int $size;                  // Size in bytes
    public ?DateTime $lastModified;    // Last modified date
    public string $contentType;        // MIME type
    
    // Helper methods
    public function isMarkdownFile(): bool;
    public function getFormattedSize(): string;
    public function getFormattedLastModified(): ?string;
    public function toArray(): array;
}
```

## Server Configuration

### Nextcloud

1. Generate an app password: Settings → Security → App passwords
2. Use URL format: `https://your-nextcloud.com/remote.php/dav/files/username/`

```php
$client = new WebDavClient(
    baseUrl: 'https://cloud.example.com/remote.php/dav/files/john/',
    username: 'john',
    password: 'generated-app-password'
);
```

### ownCloud

1. Use your regular credentials or app password if available
2. Use URL format: `https://your-owncloud.com/remote.php/webdav/`

```php
$client = new WebDavClient(
    baseUrl: 'https://owncloud.example.com/remote.php/webdav/',
    username: 'john',
    password: 'your-password'
);
```

### Apache WebDAV

Configure Apache with mod_dav and use the WebDAV URL:

```php
$client = new WebDavClient(
    baseUrl: 'https://example.com/webdav/',
    username: 'john',
    password: 'your-password'
);
```

## Error Handling

All methods return a `WebDavResponse` object with success status:

```php
$response = $client->upload('/file.txt', '/local/file.txt');

if (!$response->success) {
    echo "Upload failed: " . $response->message . "\n";
    exit(1);
}

echo "Upload successful!\n";
```

For boolean methods like `exists()`, exceptions are caught internally:

```php
// This will return false if the file doesn't exist or there's an error
$exists = $client->exists('/some-file.txt');
```

## Testing

Run the test suite:

```bash
composer test
```

Run with coverage:

```bash
composer test-coverage
```

Static analysis:

```bash
composer phpstan
```

Code style check:

```bash
composer cs-check
composer cs-fix
```

## Requirements

- PHP 8.1 or higher
- Guzzle HTTP client library
- SimpleXML extension
- LibXML extension

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

- **Issues:** [GitHub Issues](https://github.com/four-bytes/four-webdav-client-php/issues)
- **Documentation:** This README and inline documentation
- **Email:** info@4bytes.de
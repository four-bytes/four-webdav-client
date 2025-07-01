<?php

/**
 * Basic usage example for Four WebDAV Client PHP
 */

require __DIR__ . '/../vendor/autoload.php';

use Four\WebDav\WebDavClient;

// Configuration
$baseUrl = 'https://your-nextcloud.com/remote.php/dav/files/username/';
$username = 'your-username';
$password = 'your-app-password';

// Create client
$client = new WebDavClient($baseUrl, $username, $password);

echo "Four WebDAV Client PHP - Basic Usage Example\n";
echo "==========================================\n\n";

// Test connection by listing root directory
echo "1. Testing connection...\n";
$response = $client->list('/');
if ($response->success) {
    echo "✓ Connection successful!\n";
    echo "Found " . count($response->items) . " items in root directory\n\n";
} else {
    echo "✗ Connection failed: " . $response->message . "\n";
    exit(1);
}

// List directory contents
echo "2. Directory listing:\n";
foreach ($response->items as $item) {
    $type = $item->isDirectory ? 'DIR ' : 'FILE';
    $size = $item->isDirectory ? '' : ' (' . $item->getFormattedSize() . ')';
    echo "  {$type} {$item->name}{$size}\n";
}
echo "\n";

// Create a test directory
echo "3. Creating test directory...\n";
$testDir = '/webdav-test-' . date('Y-m-d-H-i-s');
$response = $client->createDirectory($testDir);
if ($response->success) {
    echo "✓ Directory created: {$testDir}\n";
} else {
    echo "✗ Failed to create directory: " . $response->message . "\n";
}
echo "\n";

// Upload a test file
echo "4. Uploading test file...\n";
$testContent = "# WebDAV Test File\n\nThis is a test file created by Four WebDAV Client PHP.\n\nCreated: " . date('Y-m-d H:i:s') . "\n";
$testFile = $testDir . '/test-file.md';
$response = $client->uploadContent($testFile, $testContent, 'text/markdown');
if ($response->success) {
    echo "✓ File uploaded: {$testFile}\n";
} else {
    echo "✗ Failed to upload file: " . $response->message . "\n";
}
echo "\n";

// Check if file exists
echo "5. Checking if file exists...\n";
if ($client->exists($testFile)) {
    echo "✓ File exists: {$testFile}\n";
} else {
    echo "✗ File does not exist: {$testFile}\n";
}
echo "\n";

// Get file info
echo "6. Getting file information...\n";
$response = $client->getFileInfo($testFile);
if ($response->success && !empty($response->items)) {
    $file = $response->items[0];
    echo "✓ File info retrieved:\n";
    echo "  Name: {$file->name}\n";
    echo "  Size: {$file->getFormattedSize()}\n";
    echo "  Type: {$file->contentType}\n";
    echo "  Modified: {$file->getFormattedLastModified()}\n";
    echo "  Is Markdown: " . ($file->isMarkdownFile() ? 'Yes' : 'No') . "\n";
} else {
    echo "✗ Failed to get file info: " . $response->message . "\n";
}
echo "\n";

// Download the file
echo "7. Downloading file...\n";
$response = $client->download($testFile);
if ($response->success) {
    echo "✓ File downloaded successfully\n";
    echo "Content preview:\n";
    echo str_repeat('-', 40) . "\n";
    echo substr($response->content, 0, 200) . (strlen($response->content) > 200 ? '...' : '') . "\n";
    echo str_repeat('-', 40) . "\n";
} else {
    echo "✗ Failed to download file: " . $response->message . "\n";
}
echo "\n";

// Search for markdown files
echo "8. Searching for markdown files...\n";
$response = $client->searchFiles($testDir, '*.md');
if ($response->success) {
    echo "✓ Found " . count($response->items) . " markdown files:\n";
    foreach ($response->items as $item) {
        echo "  - {$item->name} ({$item->getFormattedSize()})\n";
    }
} else {
    echo "✗ Search failed: " . $response->message . "\n";
}
echo "\n";

// Clean up - delete test file and directory
echo "9. Cleaning up...\n";
$response = $client->delete($testFile);
if ($response->success) {
    echo "✓ Test file deleted\n";
} else {
    echo "✗ Failed to delete test file: " . $response->message . "\n";
}

$response = $client->delete($testDir);
if ($response->success) {
    echo "✓ Test directory deleted\n";
} else {
    echo "✗ Failed to delete test directory: " . $response->message . "\n";
}

echo "\nExample completed!\n";
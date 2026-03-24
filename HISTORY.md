## [3.0.0] - 2026-03-24

### Added
- `WebDavClientInterface` — interface for all sync WebDAV operations
- `WebDavItem`: new fields `etag`, `mtime` (int), `mountType`, `contentType`
- `WebDavClient::getHttpClient()` — exposes injected PSR-18 client for advanced usage
- `WebDavClient::sendRequest()` — generic WebDAV request method for extensions
- `WebDavClient::getFileInfo()` — PROPFIND Depth:0 for size/etag
- `WebDavClient::setMtime()` — PROPPATCH lastmodified
- `WebDavClient::listBreadthFirst()` — BFS directory traversal
- `WebDavClient::listRecursive()` — depth-first directory traversal
- `WebDavClient::createDirectoryRecursive()` — MKCOL with parent creation
- `WebDavClient::exists()` — PROPFIND Depth:0 existence check
- SabreDAV-compatible URL encoding (`encodePath`)
- PROPFIND XML parsing with nc:mount-type support

### Changed
- `WebDavClient` now accepts PSR-18 `ClientInterface` via DI (Guzzle can be injected)
- `WebDavItem`: removed `lastModified` (DateTime), replaced with `mtime` (int unix timestamp)
- `WebDavItem`: removed `isMarkdownFile()` (app-specific)
- `WebDavResponse`: added `statusCode` field
- All internal helper methods are `protected` for subclass access
- `composer.json`: added `psr/log`, `ext-dom` dependencies

### Removed
- `WebDavItem::isMarkdownFile()` — was app-specific
- `WebDavItem::getFormattedLastModified()` — use `mtime` directly


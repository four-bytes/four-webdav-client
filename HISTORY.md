# Changelog

## [2.0.0] - 2026-02-26

### Changed
- Replaced GuzzleHTTP with PSR-18 HTTP client interface
- `WebDavClient` constructor now accepts optional PSR-18 `ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface`
- Added `WebDavClient::create()` static factory using four-http-client
- Added PHPUnit 11 test suite (10 tests, 12 assertions)
- Fixed `WebDavItem::toArray()` return type annotation
- Fixed `WebDavItem::getFormattedSize()` float-to-int cast for array key

### Removed
- Hard dependency on `guzzlehttp/guzzle`

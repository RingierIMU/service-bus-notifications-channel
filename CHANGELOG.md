# Changelog

All notable changes to `ringierimu/service-bus-notifications-channel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Laravel 13 support (released 2026-03-17 — zero breaking changes from 12.x)
- `debounce_key` field on v2 event params: joins every payload entity's `reference` into `key1=ref1_key2=ref2_...` for downstream message deduplication on the full reference combination. Returns `null` if any entity is missing a reference.
- Per-event-type `MessageGroupId` for `ServiceBusSQSChannel`: appends a primary-entity tag (e.g. `listing={reference}`, `user={reference}`) so SQS FIFO ordering partitions by the entity that owns the event. Unknown event types fall back to bare `from`.
- Full test coverage for `ServiceBusChannel` and `ServiceBusSQSChannel`
- GitHub Actions CI workflow replacing legacy `build.yml`
- README badges for CI status, PHP version, and Laravel version
- `ServiceBusSQSChannel` rebuilds its SQS client and retries once when AWS returns a stale-credential error (`ExpiredToken`, `ExpiredTokenException`, `InvalidClientTokenId`, `UnrecognizedClientException`, `RequestExpired`, `TokenRefreshRequired`); non-credential errors are not retried and bubble up. Note: if Laravel's config is cached (`php artisan config:cache`), the rebuild reads the same cached credentials — clear the config cache when rotating keys.

### Changed
- **BREAKING:** Minimum PHP version raised to 8.3
- **BREAKING:** Minimum Laravel version raised to 11.0 (supports 11.x, 12.x, and 13.x)
- Migrated test suite from PHPUnit to Pest 4
- Applied PHP 8.3 modernization via Rector (readonly properties, match expressions, named arguments)
- Refactored `ServiceBusChannel` and `ServiceBusSQSChannel` to accept optional HTTP/SQS clients via constructor injection
- `ServiceBusSQSChannel` now auto-detects FIFO queues by `.fifo` URL suffix and only sets `MessageGroupId`/`MessageDeduplicationId` for FIFO queues; standard queues are now supported
- `ServiceBusSQSChannel::send()` no longer double-resolves the event (`toServiceBus()` was being called twice).
- `ServiceBusEvent::getPayload()` is now null-safe (returns `[]` when no payload was set), so v2 events built without `withPayload()` no longer throw on `getParams()`.
- Bumped GitHub Actions: `actions/checkout@v4` → `@v6`, `actions/cache@v4` → `@v5`.

### Removed
- Support for PHP < 8.3
- Support for Laravel < 11.0
- Legacy `build.yml` GitHub Actions workflow
- `helpers.php` test helper file (inlined into test files)
- Legacy constraints on dev/optional deps: `nunomaduro/collision ^7.0`, `tightenco/tlint ^8`, `guzzlehttp/psr7 ^1` — all narrowed to the latest major.

## [3.10.0] - 2025-02-26

### Added
- Laravel 12 support

## [3.9.0] - 2024-10-25

### Added
- Laravel 11 support

## [3.8.0] - 2024-03-05

### Added
- SQS channel for high-volume notifications (`ServiceBusSQSChannel`)

## [3.7.1] - 2023-10-24

### Changed
- Log response from event ingestion endpoint

## [3.7.0] - 2023-05-31

### Added
- Laravel 10 support

## [3.6.1] - 2023-05-08

### Changed
- Updated `from` to `node_id`
- Updated test suite

## [3.6.0] - 2022-09-14

### Changed
- Event Bus v2.0.0 support

## [3.5.0] - 2022-03-17

### Added
- Laravel 9 support

## [3.4.0] - 2021-07-14

### Changed
- Preserve microseconds in event timestamps

## [3.3.0] - 2021-06-23

### Added
- `withResource()` method on `ServiceBusEvent`
- Enhanced `withPayload()` method

### Deprecated
- `withResources()` method (use `withResource()` instead)

## [3.2.1] - 2021-01-25

### Fixed
- Status code issue with auth endpoint

## [3.2.0] - 2021-01-20

### Added
- PHP 8.0 support

## [3.1.0] - 2020-12-14

### Changed
- Altered auth token strategy

## [3.0.0] - 2020-11-05

### Changed
- **BREAKING:** Updated to latest event payload spec

## [2.2.0] - 2020-09-29

### Added
- Laravel 8.0 support

## [2.1.1] - 2020-08-05

### Fixed
- Missing dependency for Laravel 7.0 support

## [2.1.0] - 2020-08-05

### Added
- Laravel 7.0 support

## [2.0.0] - 2020-06-10

### Changed
- **BREAKING:** Config and logging changes

## [1.6.0] - 2020-05-31

### Changed
- Improved notification sent log

## [1.5.0] - 2020-05-26

### Changed
- Enhanced disabled log

## [1.4.0] - 2020-03-24

### Changed
- Logging improvements

## [1.3.0] - 2020-03-20

### Changed
- Logging improvements

## [1.2.2] - 2020-02-20

### Fixed
- Reverted change to Carbon class

## [1.2.1] - 2020-02-20

### Fixed
- Default venture reference and created at

## [1.2.0] - 2020-02-20

### Changed
- Quieten log and use UUIDv4

## [1.1.0] - 2020-02-04

### Changed
- Updated Laravel dependencies to allow 6.x, removed composer.lock

## [1.0.5] - 2020-01-14

### Changed
- Allow config to be passed into Channel constructor

## [1.0.4] - 2019-08-19

### Changed
- Date time format in Service Bus Event updated

## [1.0.3] - 2019-08-07

### Added
- `withPayload()` method to format the payload

## [1.0.2] - 2019-07-31

### Fixed
- Bug fixes

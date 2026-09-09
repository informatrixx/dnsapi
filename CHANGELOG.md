# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.1] - 2026-09-09

### Changed

- Support PHP 8.4 and 8.5 in CI and documentation; CLI and Certbot executable scripts retain the generic `/usr/bin/php` interpreter.

### Fixed

- Remove deprecated `curl_close()` usage in HetznerCloud and declare nullable record types explicitly in Hetzner and Netcup adapters.

## [0.1.0] - 2026-08-16

### Added

- Initial versioned baseline for the dynamic DNS CLI, HTTP endpoint, provider adapters, and Certbot hooks.
- Automated PHP syntax checks and tag-based GitHub release packaging.

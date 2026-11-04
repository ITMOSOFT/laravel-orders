# Changelog

## [1.0.2] - 2026-11-04
- Fixed migration closure scope so the configured table prefix is available when defining foreign-key constraints.

## [1.0.1] - 2026-11-04
- Fixed PSR-4 autoload-dev namespace mapping for the test suite.

## [1.0.0] - 2026-11-04
- Initial release.
- Added orders, order items, product snapshots, and status history.
- Added order creation from normalized checkout payloads and a basic checkout-session adapter.
- Added configurable order statuses and validated status transitions.
- Added order lifecycle events and Laravel 12/13 CI matrix.

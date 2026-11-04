# GitHub Release

**Repository:** `ITMOSOFT/laravel-orders`

**Description:** Order management for Laravel applications with immutable item snapshots and auditable status transitions.

**Tag:** `v1.0.2`

## Release title

`v1.0.2 — Fix Migration Table Prefix Scope`

## Release description

```markdown
## ITMOSOFT Laravel Orders v1.0.2

Patch release fixing the migration closures so the configured table prefix is captured when creating foreign-key constraints.

### Features
- Create orders from validated checkout payloads.
- Store order item details and immutable product snapshots.
- Associate orders with customers through a polymorphic relation.
- Track order status history.
- Validate configurable status transitions.
- Dispatch `OrderCreated` and `OrderStatusChanged` events.
- Support configurable table prefixes.
- Laravel 12 and 13 compatibility target.

### Scope
Payments, inventory reservation, tax calculation, promotions, and shipping integrations are intentionally outside this package.
```

## Packagist

Submit: https://packagist.org/packages/itmosoft/laravel-orders

After creating the GitHub repository and pushing the release tag:
1. Submit the repository URL on Packagist.
2. Configure the Packagist GitHub webhook (or enable the Packagist GitHub integration).
3. Confirm that `v1.0.0` appears as a package version.
4. Install in a clean Laravel application with `composer require itmosoft/laravel-orders`.

Do not publish until the local PHPUnit suite and GitHub Actions matrix pass.

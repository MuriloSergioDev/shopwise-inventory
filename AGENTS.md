# shopwise-inventory

Stock levels per SKU and the reservations checkout's saga makes (ADR-0004). Catalog owns SKUs; inventory learns them
from catalog's events and keeps stock only for those. Created from `shopwise-service-template`: structure, checks and
how to run PHP are described there and still apply.

## Service facts
- **Owns**: `stock_levels` (one row per catalog SKU: `active` + `catalog_changed_at` from catalog, `on_hand` and `reserved` its own), `reservations` (ULID id, unique `checkout_id`, status `held`/`committed`/`released`/`failed`, `expires_at`, release or failure reason), `reservation_lines` (`sku`, `quantity`). Database `inventory`.
- **Publishes** (outbox):
  - key = checkoutId: `inventory.stock-reserved`, `inventory.reservation-failed`, `inventory.reservation-committed`, `inventory.reservation-released`.
  - key = sku: `inventory.stock-level-changed`, full state, on every change of `on_hand`/`reserved` (admin set, reserve, commit, release, expiry).
- **Consumes**:
  - consumer `catalog-skus`: `catalog.variant-changed` → `App\Actions\RecordSku` (upsert, ignores a state older than `catalog_changed_at`).
  - consumer `checkout-commands`: `inventory.reserve-stock`, `inventory.commit-reservation`, `inventory.release-reservation` (topics owned here, ADR-0004).
- **HTTP** (BFF only) — contract `shopwise-contracts/openapi/inventory.yaml`. Public: `GET /api/stock?skus[]=`. Admin (`auth.jwt:admin`): `GET /api/admin/stock?q&lowStock&page&perPage`, `GET|PUT /api/admin/stock/{sku}`.
- **Scheduled**: `inventory:expire-reservations` every minute (plus the kit's outbox relay every second).
- **Config**: `INVENTORY_RESERVATION_TTL_MINUTES` (default 15).
- **REST dependencies**: identity's JWKS (kit `Jwks`, `IDENTITY_JWKS_URL`) for admin routes only.

## Rules
- `on_hand` includes held units; `available = on_hand - reserved`. Commit takes units off both; releasing a held reservation lowers `reserved`; releasing a committed one puts units back on `on_hand`.
- Every stock change goes through `App\Actions\Support\StockLedger`: rows are locked with `lockForUpdate()` **in SKU order** (no deadlocks between reservations over the same SKUs) and written with a compare-and-set on the values read, so a missed lock fails instead of overselling. Each change records `stock-level-changed`.
- Reservations are all or nothing. Unknown or catalog-deactivated SKUs fail with `unknown_sku` before stock is checked; otherwise `insufficient_stock` lists only the short lines. A SKU listed twice is summed.
- One reservation per checkout. A repeated `reserve-stock` re-publishes the stored outcome (held/committed → `stock-reserved`, failed → `reservation-failed`, released → `reservation-released` with empty lines), even if stock changed since.
- `commit-reservation` on an expired held reservation expires it now (`reservation-released`, reason `expired`, with lines); on an unknown, failed or released one it replies `reservation-released` (expired, empty lines).
- `release-reservation` with nothing to return replies with empty lines; `reservationId` is null when the checkout never had a held reservation (unknown or failed).
- `ReservationReleasedV1` from contracts types `reservationId` as a non-null string, so `ReservationReplies::released()` builds that payload by hand.
- `PUT /api/admin/stock/{sku}` sets a count (not a delta), 404 for an unknown SKU, 422 (`errors.onHand`) below `reserved`, records an event only when the count changed.

## Tests
- `tests/Feature/Messaging/ConcurrentReservationsTest.php` starts 12 PHP processes (`tests/Support/reserve-stock.php`) that reserve the last units at once; Postgres only (skipped on SQLite). The children print `ready` straight to STDOUT (`echo` is buffered by the image's php.ini) and wait for `go` on stdin.
- jsonb reorders keys: compare outbox payloads with `toEqual`, not `toBe`.

## Data
- `db:seed` stocks every SKU of catalog's demo seeder (28 SKUs, e.g. `TEE-MER-BLA-M` 50, `SKL-CST` 3, `COF-POUR-*` 2) and writes them as catalog SKUs with a year-2000 timestamp, so it works without Kafka and real catalog events still win. Goes through the actions (records `stock-level-changed`); does nothing once any SKU has stock.

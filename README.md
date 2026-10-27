# shopwise-inventory

Stock levels per SKU and checkout reservations for Shopwise. Conventions: `AGENTS.md`.

Run it from `shopwise-platform` with Tilt (inventory + catalog), then

```sh
kubectl exec deploy/inventory-http -- php artisan db:seed --force   # demo stock, once
curl -s 'localhost:<port>/api/stock?skus[]=TEE-MER-BLA-M&skus[]=SKL-CST'
```

Reservations are made only by checkout's Kafka commands (`inventory.reserve-stock`, `inventory.commit-reservation`,
`inventory.release-reservation`). Admin endpoints (`/api/admin/stock...`) need an access token with the `admin` role
from identity (`admin@shopwise.test` after seeding identity).

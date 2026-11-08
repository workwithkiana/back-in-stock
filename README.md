# Restock. | Back-in-stock email alerts

A polished PHP + MySQL back-in-stock app built for the challenge. Includes an iframe-friendly form, JSON subscription API, authenticated stock webhook, admin dashboard, durable MySQL storage and a Resend-compatible HTML email worker.

## Endpoints
- `GET /embed` customer form (optionally `?sku=ABC-123`)
- `POST /api/requests` JSON `{ "email": "you@example.com", "sku": "ABC-123" }`
- `POST /webhooks/stock` JSON `{ "event_id": "evt-1", "sku": "ABC-123", "quantity": 5 }` with `X-Webhook-Secret`
- `GET /admin` HTTP Basic auth: any username, `ADMIN_PASSWORD` as password
- `GET /health` database health check

## Quick start: Windows + XAMPP
1. Copy `.env.example` to `.env` and configure MySQL, stock API and email gateway values.
2. Start MySQL in XAMPP; create `back_in_stock` database using phpMyAdmin.
3. Import `database/schema.sql` into the database. PHP also initializes the schema automatically when run through the Docker startup script.
4. From the project root run `C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public public/router.php`.
5. Open `http://127.0.0.1:8000/embed` and `http://127.0.0.1:8000/admin`.
6. Start the worker separately: `C:\xampp\php\php.exe bin-worker.php` (run periodically, e.g. Task Scheduler). Docker runs it automatically every 5 seconds.

## Docker: one command, built-in MariaDB
Build: `docker build -t restock-alerts .`

Run (example):
```
docker run --rm -p 8000:8000 -v restock_mysql:/var/lib/mysql --env-file .env restock-alerts
```

The Docker image includes MariaDB (MySQL-compatible) and creates the database automatically. No external database is needed. Set `PORT` and map the same port, e.g. `-p 9000:9000 -e PORT=9000`. Use a named volume for database persistence across container restarts. Do **not** use `.env.example` as real credentials. The container can also be run with only the challenge environment variables, because DB configuration defaults are provided.

## Challenge configuration
Get `STOCK_API_URL`, `STOCK_API_KEY`, `RESEND_API_KEY`, `RESEND_BASE_URL`, and `WEBHOOK_SECRET` from the challenge harness after signing up with the invite code. Set `ADMIN_PASSWORD` to a strong password. The app **must** use the harness `RESEND_BASE_URL`, not production Resend. Optional `MAIL_FROM` sets the sender.

## Reliability model
- A generated unique open-slot constraint prevents concurrent duplicate subscriptions for the same email + SKU.
- Webhook secret is compared with `hash_equals`. Events are stored by unique `event_id`.
- Stock is verified against the authenticated stock API, not the untrusted webhook quantity.
- Rows are queued transactionally with a unique outbox entry per request. Worker uses `FOR UPDATE SKIP LOCKED` and durable outbox states.
- The worker retries failures and reclaims abandoned sending jobs after 2 minutes.
- Resend `Idempotency-Key` uses the request ID, helping avoid duplicate emails on network retries. **Exactly-once delivery still depends on the harness honoring idempotency keys**; no HTTP-based email sender can guarantee it alone after a timeout.
- A Docker volume preserves subscriptions and queued messages through restarts.

## Important testing notes
This project is a ready-to-configure implementation, not a verified challenge submission. Before submitting, register with the harness, check its exact Resend-compatible endpoint and `MAIL_FROM` requirements, run the harness tests, inspect webhook payloads and validate end-to-end delivery. The stock endpoint is assumed to return `quantity` or `in_stock` as documented. Docker build/run and the live harness were not executed by the assistant.

## Manual smoke tests
```
curl -X POST http://localhost:8000/api/requests -H "Content-Type: application/json" -d '{"email":"test@example.com","sku":"SKU-1"}'
curl -X POST http://localhost:8000/webhooks/stock -H "Content-Type: application/json" -H "X-Webhook-Secret: your-secret" -d '{"event_id":"evt-1","sku":"SKU-1","quantity":5}'
```

## Security notes
Do not commit `.env` or expose admin credentials. Use HTTPS when deployed. Basic auth is suitable only over HTTPS. Consider rate limiting and CAPTCHA for a public deployment. Avoid storing real customer information in challenge submissions.

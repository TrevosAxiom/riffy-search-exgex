# Rifnote Data Engine

This is the external data warehouse setup for Rifnote.

It runs:

- PostgreSQL for imported RSS, social and video data
- Redis for queues, locks and fast cache
- Rifnote Data API for safe WordPress access to warehouse data

WordPress should not connect directly to Postgres. It should call the Rifnote Data API with a bearer token.

## 1. Install Docker on AlmaLinux

Copy this folder to the VPS, then run:

```bash
cd infra/data-engine
chmod +x scripts/bootstrap-almalinux.sh
./scripts/bootstrap-almalinux.sh
```

Log out and back in after the script finishes so your user is added to the Docker group.

## 2. Configure secrets

```bash
cp env.example env.local
nano env.local
```

Change every `change-this...` value before starting the stack.

## 3. Start Postgres and Redis

```bash
docker compose --env-file env.local up -d
docker compose --env-file env.local ps
```

## 4. Verify Postgres

```bash
docker exec -it rifnote-postgres psql -U rifnote -d rifnote_data -c "\dt"
```

You should see tables including `sources`, `feed_channels`, `external_items`, `ingest_runs`, and `trending_terms`.

## 5. Start the Data API

If Postgres/Redis already exist from the base stack, start only the API:

```bash
docker compose --env-file env.local -f docker-compose.api.yml up -d --build
docker logs --tail=80 rifnote-data-api
```

Verify locally on the VPS:

```bash
curl http://127.0.0.1:3010/health
curl -H "Authorization: Bearer $RIFNOTE_DATA_API_TOKEN" http://127.0.0.1:3010/v1/health
```

Primary endpoints:

- `GET /v1/health`
- `GET /v1/stories/search?q=football`
- `GET /v1/stories/category/football`
- `GET /v1/trending`
- `GET /v1/sources`
- `GET /v1/admin/items`
- `GET /v1/admin/items/:id`
- `PATCH /v1/admin/items/:id`
- `DELETE /v1/admin/items/:id`
- `GET /v1/admin/feeds`
- `POST /v1/admin/feeds`
- `PATCH /v1/admin/feeds/:id`
- `DELETE /v1/admin/feeds/:id`
- `GET /v1/admin/settings/rss-worker`
- `PATCH /v1/admin/settings/rss-worker`

## 6. Warehouse CRUD console

The Data API also ships with a browser-based CRUD console for the warehouse.
This keeps high-volume RSS, social and video records out of WordPress admin.

Open:

```text
https://data.rifnote.com/admin
```

Login with the `RIFNOTE_DATA_API_TOKEN` value from `env.local`.

The console includes:

- Dashboard counts and recent ingest runs
- Warehouse item search, edit and delete
- RSS feed create, edit, pause and delete
- RSS worker interval, batch, timeout and cleanup settings
- Ingest log review

## 7. RSS worker

The Data API container runs the RSS worker itself, so WordPress does not need to
poll every feed. WordPress can manage feeds and read/search warehouse results,
while the VPS pulls due RSS feeds into PostgreSQL.

The worker respects each feed's `poll_interval_seconds`. Runtime worker controls
live in PostgreSQL and can be edited at:

```text
https://data.rifnote.com/admin/settings
```

The saved settings control worker wake interval, feeds checked per pass, max
items per feed, feed fetch timeout, and RSS cleanup after days. These environment
values are fallback defaults:

```bash
RIFNOTE_RSS_WORKER_ENABLED=true
RIFNOTE_RSS_WORKER_TICK_SECONDS=60
RIFNOTE_RSS_WORKER_BATCH_SIZE=10
RIFNOTE_RSS_ITEMS_PER_FEED=10
RIFNOTE_RSS_FETCH_TIMEOUT_SECONDS=12
RIFNOTE_RSS_CLEANUP_AFTER_DAYS=30
```

After changing worker values:

```bash
docker compose --env-file env.local -f docker-compose.api.yml up -d
```

After changing anything in `infra/data-engine/api`, rebuild the API container:

```bash
cd /home/rifnoteops/rifnote-data-engine
git pull origin main
docker compose --env-file env.local -f docker-compose.api.yml up -d --build
```

## 8. Expose through HTTPS

See [proxy/README.md](proxy/README.md).

## 9. Optional: browser database GUI

pgAdmin can run privately on the VPS and be exposed through a locked-down proxy.
Postgres still stays bound to `127.0.0.1` and is not opened to the internet.

Add these values to `env.local`:

```bash
PGADMIN_DEFAULT_EMAIL=admin@rifnote.com
PGADMIN_DEFAULT_PASSWORD=change-this-long-pgadmin-password
PGADMIN_PORT=5050
```

Start pgAdmin:

```bash
docker compose --env-file env.local -f docker-compose.pgadmin.yml up -d
```

Inside pgAdmin, add the server:

- Host: `postgres`
- Port: `5432`
- Maintenance database: `rifnote_data`
- Username: `rifnote`
- Password: the `POSTGRES_PASSWORD` value from `env.local`

For Webuzo/Apache, copy `proxy/apache/db-rifnote.conf` to Apache's `conf.d`
folder and point `db.rifnote.com` at the VPS.

## Notes

- Postgres and Redis are bound to `127.0.0.1`, so they are not publicly exposed.
- The Data API is also bound to `127.0.0.1` by default. Put Nginx/Caddy in front of it when exposing it to WordPress.
- Keep this VPS behind SSH/firewall access only.
- The WordPress plugin will connect later through the Rifnote Data API, not with the raw Postgres password.

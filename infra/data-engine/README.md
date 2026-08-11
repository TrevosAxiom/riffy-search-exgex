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

## 6. Expose through HTTPS

See [proxy/README.md](proxy/README.md).

## 7. Optional: browser database GUI

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

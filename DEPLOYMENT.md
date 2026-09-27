# Deploying Linkweaver

Two pieces deploy separately: a **Docker container** for the API (nginx + php-fpm + queue
worker under Supervisor) and a **static bundle** for the frontend.

Both targets below are free tiers. The API image is built for `linux/amd64` and
`linux/arm64`, because Oracle Cloud's Always Free tier is ARM.

---

## 1. The database

You need MySQL 8 reachable from the API. Any of these work:

| Option | Notes |
| --- | --- |
| **TiDB Cloud Serverless** | Free tier, MySQL-compatible, requires TLS |
| **Aiven for MySQL** | Free plan, requires TLS |
| **On the VM itself** | Simplest on Oracle Cloud; no TLS needed over loopback |

Managed providers require a CA certificate. Set `MYSQL_ATTR_SSL_CA` to its path and the
connection verifies; leave it empty for a local database.

```sql
CREATE DATABASE linkweaver CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Migrations run automatically on container boot.

---

## 2. Environment

Generate a key once, locally:

```bash
php artisan key:generate --show   # copy the base64:... value
```

Minimum set for the API container:

```env
APP_NAME=Linkweaver
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://your-api-host

DB_HOST=...
DB_PORT=3306
DB_DATABASE=linkweaver
DB_USERNAME=...
DB_PASSWORD=...
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt   # managed MySQL only

# Origin of the SPA. Drives the CORS allow-list — without this the browser
# blocks every request.
FRONTEND_URL=https://your-frontend-host

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Optional. Without these the crawl and link analysis still work; only the
# embedding and anchor stages are skipped.
GEMINI_API_KEY=
GEMINI_EMBED_MODEL=gemini-embedding-2
GEMINI_TEXT_MODEL=gemini-3.5-flash-lite
```

`APP_DEBUG=false` matters: Laravel's debug page prints environment variables, including
your database password and API key.

---

## 3. Option A — Render (free web service)

Render's free tier gives you one service and no separate worker, which is exactly why
this image runs the queue worker inside it under Supervisor.

1. Push the repository to GitHub.
2. **New → Web Service**, connect the repo, choose **Docker**.
3. Leave the Dockerfile path as `./Dockerfile` and the context as the repo root.
4. Add the environment variables above. Render sets `PORT` itself — do not override it.
5. Deploy.

The container runs migrations and seeds the demo project on every boot, so the first
deploy comes up with a working public demo.

**The free tier sleeps after 15 minutes idle.** The first request afterwards takes tens of
seconds while the container wakes. The frontend already handles this — `apiFetch`
classifies a timeout distinctly and the UI says "the server is waking up" rather than
showing an error. A cron ping to `/api/health` every 10 minutes avoids it if you prefer.

Two consequences of a sleeping container worth knowing:

- **A crawl in progress stops when the container sleeps.** Jobs stay in the queue and
  resume on the next request that wakes it.
- The queue-staleness check on `/api/health` returns `degraded` if jobs sit unprocessed
  for more than five minutes, which is the symptom of a worker that died rather than slept.

---

## 4. Option B — Oracle Cloud Always Free (ARM VM)

The Always Free ARM instance (4 cores, 24 GB) is far more capable than Render's free tier
and does not sleep.

```bash
# On the VM (Ubuntu 22.04+)
sudo apt update && sudo apt install -y docker.io git
sudo usermod -aG docker "$USER" && newgrp docker

git clone https://github.com/<you>/linkweaver.git
cd linkweaver

# MySQL on the same host, if you are not using a managed provider
docker run -d --name linkweaver-db --restart unless-stopped \
  -e MYSQL_ROOT_PASSWORD='choose-something-strong' \
  -e MYSQL_DATABASE=linkweaver \
  -v linkweaver-mysql:/var/lib/mysql \
  mysql:8.0

docker build -t linkweaver-api .

docker run -d --name linkweaver-api --restart unless-stopped \
  --link linkweaver-db:db \
  --env-file .env.production \
  -p 8080:8080 \
  linkweaver-api
```

Then put a TLS terminator in front — Caddy is the least work:

```caddyfile
api.yourdomain.com {
    reverse_proxy 127.0.0.1:8080
}
```

Open port 443 in **both** the Oracle security list and the instance firewall. Oracle's
images ship with `iptables` rules that block traffic even when the security list allows
it, which is the single most common reason a new instance appears unreachable:

```bash
sudo iptables -I INPUT -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

---

## 5. The frontend

A static bundle. Build it with the API URL baked in — Vite inlines `VITE_*` at build time,
so this is a build-time setting, not a runtime one.

### Cloudflare Pages

| Setting | Value |
| --- | --- |
| Build command | `npm run build` |
| Output directory | `dist` |
| Root directory | `frontend` |
| Environment variable | `VITE_API_URL=https://your-api-host/api` |

### Netlify

Same values. Add `frontend/public/_redirects` so client-side routing survives a refresh:

```
/*  /index.html  200
```

Cloudflare Pages does this automatically for SPAs.

After deploying, set `FRONTEND_URL` on the API to the frontend's origin and redeploy the
API — CORS is an explicit allow-list, so a mismatch blocks every request with an error the
browser reports only in its console.

---

## 6. Verifying a deployment

```bash
curl https://your-api-host/api/health
```

```json
{ "status": "ok", "checks": { "database": { "ok": true }, "queue": { "pending": 0 } } }
```

- `"status": "degraded"` with a non-null `worker_running: false` means the queue worker is
  not draining jobs — check Supervisor's output in the container logs.
- `"database": { "ok": false }` means the DSN or TLS settings are wrong.

Then open the frontend. The landing page renders the demo graph from
`GET /api/demo`; if it shows "the live demo is not available", the container booted without
seeding — check `LINKWEAVER_SEED_DEMO` and the boot logs.

---

## 7. Costs

Everything above is free tier. The only metered dependency is Gemini, and the app is
designed to stay inside its free quota: embeddings are cached by content hash so unchanged
pages are never re-embedded, anchor generation is capped per project, and both stages are
rate limited from config. A 200-page site costs roughly one embedding batch per 50 pages
plus up to 100 text calls, once.

# Linkweaver

Finds the internal links a website is missing. Point it at a sitemap and it
crawls the pages, extracts the main content and the links that live inside it,
identifies orphan and weakly linked pages, uses embeddings to find pages that
are topically related but not linked, and proposes anchor text that already
appears verbatim on the source page. Suggestions are reviewed, exported to CSV,
or pushed straight into WordPress.

> **Status: in development.** Phase 0 (scaffold) is complete. The full README —
> pitch, screenshots, architecture diagram, deployment guides, and the reasoning
> behind each design decision — is written in phase 5. See `PROJECT_SPEC.md` for
> the plan and `CLAUDE.md` for conventions and commands.

## Repository layout

| Path        | What it is                                                          |
| ----------- | ------------------------------------------------------------------- |
| `/backend`  | Laravel 13 JSON API, queued crawl and analysis pipeline, MySQL.      |
| `/frontend` | React + Vite + TypeScript SPA, deployable as static files.           |

## Quick start

Requires PHP 8.3+, Composer, Node 22+, and MySQL 8.

```sh
# API
cd backend
composer install
cp .env.example .env          # set DB_* and, from phase 3, GEMINI_*
php artisan key:generate
php artisan migrate
php artisan serve             # http://localhost:8000

# Queue worker — required, the pipeline is entirely queued jobs
php artisan queue:work

# SPA
cd frontend
npm install
cp .env.example .env          # VITE_API_URL
npm run dev                   # http://localhost:5173
```

## Tests

```sh
cd backend  && composer check   # Pint + Pest
cd frontend && npm run check    # ESLint + tsc + Prettier
```

No test touches the real network or the Gemini API.

## Licence

MIT.

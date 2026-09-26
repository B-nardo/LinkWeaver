# Linkweaver — working notes

Internal link opportunity finder. Crawls a sitemap, extracts main content and
in-content links, finds orphan and weakly linked pages, uses Gemini embeddings
to find topically related pages that are not linked, and suggests anchor text
that already exists verbatim on the source page.

`PROJECT_SPEC.md` is the contract. This file records conventions, commands, and
decisions; it is updated at the end of every phase.

---

## Layout

```
/backend     Laravel 13 JSON API. No Blade UI, no asset pipeline.
/frontend    React 19 + Vite + TypeScript SPA. Deployable standalone as static files.
```

The frontend never imports from the backend. It talks to the API over HTTP using
`VITE_API_URL`, so it can be hosted on Cloudflare Pages or Netlify while the API
runs elsewhere.

## Versions

| Thing            | Version | Note                                              |
| ---------------- | ------- | ------------------------------------------------- |
| PHP              | ^8.3    | CI matrixes 8.3 and 8.4. Local dev box runs 8.5.  |
| Laravel          | 13.33   |                                                   |
| Pest             | 4.7     | Replaces the default PHPUnit runner.              |
| MySQL            | 8.0.46  | Local server on **port 3307**. CI uses `mysql:8.0`. |
| Node             | 24 local / 22 CI | Vite 8 supports both.                    |
| React            | 19.2    |                                                   |
| TypeScript       | 6.0     | `strict`, `noUncheckedIndexedAccess` on.          |
| Tailwind         | 4.3     | CSS-first config, no `tailwind.config.js`.        |

## Commands

All backend commands run from `/backend`, frontend from `/frontend`.

### Backend

```sh
php artisan serve                 # API on http://localhost:8000
php artisan queue:work            # REQUIRED: the whole pipeline is queued jobs
php artisan migrate               # schema
php artisan migrate:fresh --seed  # reset + demo data

composer test                     # Pest suite
composer lint                     # Pint, check only
composer fix                      # Pint, apply fixes
composer check                    # lint + test, what CI runs
```

`queue:work` is not optional during development. Creating a project only
validates input and dispatches jobs; with no worker running the project sits at
`pending` forever and the frontend polls a status that never changes.

### Frontend

```sh
npm run dev          # Vite on http://localhost:5173
npm run lint         # ESLint
npm run typecheck    # tsc -b
npm run format       # Prettier, apply
npm run check        # lint + typecheck + format:check, what CI runs
```

### Database

Local MySQL 8.0 listens on **3307**, not 3306, because XAMPP's MariaDB 10.4 owns
3306 on this machine. Two databases are needed:

```sql
CREATE DATABASE linkweaver      CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE linkweaver_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Note that XAMPP's bundled MariaDB client cannot authenticate against MySQL 8
(`caching_sha2_password.dll` is missing from that distribution). Use the client
at `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe`, MySQL Workbench, or
just go through PHP — `pdo_mysql` handles the auth plugin natively.

`phpunit.xml` points the suite at `linkweaver_test`. PHPUnit does not overwrite
variables already present in the environment, so CI overrides `DB_*` via job
`env:` without editing the file.

## Conventions

### Backend

- **Strict types everywhere.** `declare(strict_types=1)` is enforced by Pint,
  including in config and bootstrap files.
- **Thin controllers, thin jobs.** Logic lives in small single-responsibility
  service classes under `app/Services`. A job's body should read as a few calls
  into services plus status bookkeeping. Controllers validate via Form Requests,
  authorise via Policies, and return API Resources.
- **No logic in models** beyond relationships, casts, and scopes.
- **Every outbound HTTP request goes through `SafeHttpFetcher`**, which applies
  `SafeUrlGuard`, timeouts, size caps, and redirect re-validation. Never call
  `Http::` directly against a user-supplied URL.
- **Every URL comparison goes through `UrlNormalizer`.** Comparing raw URLs
  anywhere is a bug.
- **Config over constants.** Tunables live in `config/linkweaver.php`, fed by
  env. No magic numbers in services.
- **Model output is untrusted.** Gemini responses are validated in code before
  anything is persisted.

### Frontend

- Path alias `@/` maps to `src/` (declared in both `vite.config.ts` and
  `tsconfig.app.json` — keep them in sync).
- Server state is TanStack Query only; no state library.
- `apiFetch` is the single entry point for API calls. It injects the bearer
  token and classifies failures as `http | network | timeout | parse` so the UI
  can distinguish a cold-starting server from a real error.
- Every async surface designs five states: empty, loading (skeleton), partial
  progress, error with retry, and cold start.
- Colour is semantic. `opportunity` (teal) only ever means "a link you could
  add"; `orphan` (sienna) only ever means "nothing points here". Never use
  either decoratively.

## Design system

Concept: a surveyor's instrument for mapping a website, not a marketing
dashboard. The link graph is the product's hero.

- **Type.** `Fraunces` for display, `IBM Plex Sans` for UI, `IBM Plex Mono` for
  all numerics (so columns align). Deliberately not Inter, Roboto, or system.
- **Palette.** Warm paper and iron-gall ink, defined as `--lw-*` custom
  properties in `src/index.css` and exposed to Tailwind through `@theme inline`.
  One accent (`opportunity`), one warning (`orphan`).
- **Theme.** `data-theme` on `<html>`, resolved before first paint by an inline
  script in `index.html` to avoid a flash. `ThemeProvider` mirrors that
  attribute rather than owning the initial value.
- **Radii.** Three, used for different classes of object: `hair` (2px chrome),
  `panel` (6px surfaces), `pill`. Avoids the "everything rounded-xl" look.
- **Avoid:** purple/blue gradients, glassmorphism, uniform shadowed cards,
  all-caps tracked eyebrow labels, emoji as icons, arrows on every button.

## Key decisions

| Decision | Why |
| --- | --- |
| Sanctum **bearer tokens**, not cookie/SPA mode | The SPA is cross-origin and separately hosted; cookie auth would need a shared parent domain. `supports_credentials` stays false. |
| `projects.id` is a UUID; child tables use bigint PKs with a UUID FK | UUIDs appear in public URLs so project IDs are not enumerable; children are never addressed publicly, so they keep cheap sequential keys. |
| Whole schema migrated in phase 1 | Keeps the shape stable and avoids reshuffling foreign keys every phase. Models and logic still arrive phase by phase. |
| Links extracted during the crawl, analysed later | Same DOM parse, no second fetch. Orphan/weak detection and the graph are phase 2 concerns. |
| Design foundation built in phase 0, not phase 5 | Retrofitting an aesthetic across finished screens is expensive. Phase 5 is polish and the landing page, not a rewrite. |
| No `intl` extension dependency | Not present on the dev machine, so CI omits it too for parity. IDN hosts are lowercased but not punycode-normalised; documented as a known limit. |
| Tests run on MySQL, not SQLite | This PHP build has no `pdo_sqlite`, and matching production's engine avoids schema drift. |
| ESLint, not the template's oxlint | The spec specifies ESLint. |
| `symfony/css-selector` added | DomCrawler's `filter()` throws without it, and the spec's content selectors are CSS. A requirement of a named dependency, not a new choice. |
| Sitemaps: DOCTYPE stripped before parsing | A sitemap is attacker-controlled input our server fetches. Stripping the DOCTYPE leaves any entity reference undefined, so the document is rejected rather than expanded. |
| Normalised URLs capped at 500 chars | Keeps `(project_id, normalized_url)` inside InnoDB's 3072-byte key limit under utf8mb4. Longer URLs are skipped at parse time. |
| Unknown/blocked URLs return 404, not 403 | Confirming that a project id exists but belongs to someone else is information the endpoint has no reason to give. |
| Extraction walks the DOM instead of deleting nodes | The same document classifies links as in-content or furniture; deleting stripped regions would destroy the ancestry checks that decision depends on. |
| Health check reports queue staleness | A queue-driven app whose worker is dead still answers HTTP. A backlog older than 5 minutes is the observable symptom, and the only one worth alerting on. |
| `laravel/boost` **not** installed | Laravel 13 scaffolds a `CLAUDE.md` recommending it; it is outside the spec's dependency list. |
| Backend `package.json` and `resources/js` deleted | The API serves JSON only; the SPA owns all assets. |

## The crawl pipeline (phase 1)

```
POST /projects  ──▶  ParseSitemapJob  ──▶  Bus::batch[ CrawlPageJob × N ]  ──▶  finally: status = done
   (validate only)      SitemapCollector        SafeHttpFetcher → robots
                        → SitemapParser         → ContentExtractor
                        → page rows             → PageWriter (page + links)
```

Key classes, all under `app/Services/Crawl`:

| Class | Responsibility |
| --- | --- |
| `UrlNormalizer` | One canonical spelling per page. Built per project from the sitemap URL. |
| `SafeUrlGuard` | Refuses private, loopback, link-local and reserved destinations, in every IP notation. DNS is injected. |
| `SafeHttpFetcher` | The **only** permitted outbound request path. Re-validates every redirect hop. |
| `RobotsTxt` / `RobotsTxtRepository` | Parses and caches robots.txt per host. |
| `SitemapParser` / `SitemapCollector` | One document, and the bounded recursion over an index. |
| `ContentExtractor` | Main content vs furniture, and which links count as editorial. |
| `PageWriter` | Persists a crawled page and resolves its links to page rows. |
| `CrawlToolkit` | Builds the per-project collaborators; injected into jobs. |

## Testing rules

- **No test may touch the real network or the real Gemini API.** Use
  `Http::fake()` and fixture files.
- Unit tests get no application container (see `tests/Pest.php`): the
  correctness-critical classes are plain objects and must stay that way.
- Feature tests use `RefreshDatabase`.
- Fixtures live in `tests/Fixtures/`, mirroring real WordPress theme markup
  (Twenty Twenty-Four block markup, Astra, GeneratePress, plus one page with no
  semantic wrapper to exercise the readability fallback).

## Build phases

Phase 0 ✅ · Phase 1 ✅ crawl · Phase 2 link analysis · Phase 3 embeddings ·
Phase 4 anchor suggestions · Phase 5 polish and deploy · Phase 6 WordPress.

At the end of each phase: run tests, run linters, commit, summarise.

## Known limits

- Similarity is an in-memory O(n²) all-pairs comparison, which is why
  `LINKWEAVER_MAX_PAGES` defaults to 200. Past a few thousand pages this needs a
  vector database.
- The `database` queue driver is chosen for free-tier hosting. Redis and
  horizontal workers are the scaling path.

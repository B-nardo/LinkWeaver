# Project Spec: Linkweaver (Internal Link Opportunity Finder)

> Rename "Linkweaver" to whatever you like before starting. Everything below uses it as a placeholder.

## 1. What we are building

A web app that audits a website's internal linking. The user submits a sitemap URL. The app crawls the pages, extracts the main content and existing internal links, finds orphan and weakly linked pages, uses Gemini embeddings to find pages that are topically related but not linked, and uses a Gemini text model to suggest anchor text that already exists verbatim on the source page. The user reviews suggestions (approve or reject), exports approved ones to CSV, and optionally pushes approved links into WordPress through the REST API.

This is a portfolio project. Code quality, architecture decisions, tests, and a polished, non-generic UI matter as much as features. It must run on free-tier infrastructure.

## 2. Tech stack (do not change without asking me)

- Backend: latest stable Laravel, PHP 8.3+, MySQL 8, Laravel queues with the `database` driver, Laravel Sanctum for auth, Pest for tests.
- Frontend: React + Vite + TypeScript, Tailwind CSS, TanStack Query for data fetching and polling, React Router, `react-force-graph-2d` for the link graph.
- HTML parsing: Symfony DomCrawler, plus `fivefilters/readability.php` as a fallback content extractor.
- AI: Google Gemini API (free tier via Google AI Studio). One embedding model and one fast text model. Model names MUST come from `.env` (`GEMINI_EMBED_MODEL`, `GEMINI_TEXT_MODEL`). Before writing the Gemini client, check Google's current API docs for model names, request format, and batch embedding support. Do not rely on memory for these.
- Repo layout: monorepo with `/backend` (Laravel API only, no Blade UI) and `/frontend` (React SPA). The frontend must be deployable on its own as a static site (Cloudflare Pages or Netlify), calling the API through `VITE_API_URL`.

## 3. Working rules for you (Claude Code)

1. Before writing code, read this whole spec and produce a short implementation plan. Wait for my approval.
2. Build one phase at a time (section 10). At the end of each phase: run the tests, run the linters, commit with a clear message, then stop and give me a summary of what was built, what was decided, and anything I should check manually.
3. Create a `CLAUDE.md` at the repo root in phase 0 that records conventions, commands (how to run tests, queue worker, dev servers), and key decisions. Keep it updated.
4. Do not add dependencies beyond this spec without telling me why.
5. Never commit `.env` files or secrets. Provide `.env.example` files.
6. Prefer small, well-named service classes over fat controllers or fat jobs.
7. If something in this spec is ambiguous or seems wrong, ask instead of guessing.

## 4. Data model

```
users           standard Laravel users table
projects        id (uuid), user_id, name, sitemap_url, status (pending|crawling|embedding|analyzing|done|failed),
                pages_found, pages_crawled, pages_embedded, error_message, is_demo (bool), timestamps
pages           id, project_id, url, normalized_url (unique per project), title, h1, meta_description,
                content_text, content_hash, word_count, http_status, crawl_error, crawled_at
links           id, project_id, source_page_id, target_page_id (nullable if target not in sitemap),
                target_url, anchor_text, in_content (bool)
embeddings      id, page_id, model, vector (JSON), content_hash, timestamps
suggestions     id, project_id, source_page_id, target_page_id, similarity (float), priority_score (float),
                anchor_text, context_sentence, status (pending|approved|rejected|applied|failed), timestamps
wp_connections  id, project_id, site_url, username, app_password (encrypted cast), last_verified_at
```

Use UUIDs in public URLs for projects. Add indexes on every foreign key and on `(project_id, normalized_url)`.

## 5. Processing pipeline (queued jobs)

The HTTP request only validates input, creates the project, and dispatches the pipeline. All work runs in queued jobs chained with `Bus::chain` and `Bus::batch`. Update the project's status and counters as each stage progresses so the frontend can poll.

### 5.1 ParseSitemap
- Support both a normal sitemap (`<urlset>`) and a sitemap index (`<sitemapindex>`), recursively.
- Skip non-HTML URLs (images, PDFs, feeds).
- By default skip taxonomy sitemaps (category, tag, author). Make this a per-project option.
- Hard cap pages per project from config (`LINKWEAVER_MAX_PAGES`, default 200).

### 5.2 CrawlPage (one job per page, inside a batch)
- Use Laravel's HTTP client with a timeout, a descriptive User-Agent, max response size, and limited redirects.
- Respect `robots.txt`.
- Throttle per domain (a few requests per second at most). Use job middleware for this.
- SSRF protection is mandatory: resolve the hostname and reject private, loopback, link-local, and reserved IP ranges (IPv4 and IPv6); allow only http and https; re-check the destination after every redirect. Put this in a dedicated `SafeUrlGuard` class with thorough tests.

### 5.3 ExtractContent
- Main content: try `<article>`, `<main>`, `.entry-content`, `.post-content` first; fall back to readability.
- Strip nav, header, footer, aside, forms, scripts, and common "related posts" widgets.
- Store title, H1, meta description, clean text, word count, and a SHA-256 hash of the clean text.
- Links: extract internal links only from the main content area and mark `in_content = true`. Optionally store nav links with `in_content = false`, but they must never count as real internal links in analysis.

### 5.4 URL normalization
A dedicated `UrlNormalizer` class used everywhere URLs are compared: lowercase scheme and host, consistent `www` handling (match the sitemap's form), strip fragments, strip tracking params (`utm_*`, `gclid`, `fbclid`), consistent trailing slash, resolve relative URLs against the page URL. Heavily unit tested. This class is the most important correctness piece in the app.

### 5.5 GenerateEmbeddings
- Input per page: title + H1 + first ~1,500 words of clean content.
- Skip pages whose `content_hash` already has an embedding for the current model (cache).
- Batch requests where the API supports it.
- Rate limit with Laravel's `RateLimited` job middleware using limits from config. Retry 429s with exponential backoff.

### 5.6 AnalyzeProject
- Orphans: pages with zero inbound in-content links from other pages in the project. Weak pages: 1 to 2 inbound links.
- Similarity: load normalized vectors into memory, cosine similarity for all pairs (fine for a few hundred pages; document the scaling limit in the README).
- Candidates: for each page, top N most similar pages above a threshold (config, default 0.75), excluding pairs where the source already links to the target.
- Priority score: combine similarity with how few inbound links the target has, so weak and orphan pages rise to the top. Make the weighting a config value and explain it in the README.

### 5.7 SuggestAnchors
- For each candidate, send the Gemini text model the target's title and meta description plus the relevant chunk of source content. Ask for strict JSON: `{"anchor": "...", "sentence": "..."}` or `{"anchor": null}`.
- Validate everything in code: valid JSON, anchor is 2 to 6 words, anchor appears verbatim in the source content, anchor is not already inside a link or a heading. Discard failures and log the reason.
- The model is useful but untrusted. Never store unvalidated model output as a suggestion.

## 6. API endpoints (JSON, Sanctum-authenticated unless noted)

- Auth: register, login, logout, me.
- `POST /projects`, `GET /projects`, `GET /projects/{uuid}`, `DELETE /projects/{uuid}`
- `GET /projects/{uuid}/status` (lightweight, for polling)
- `GET /projects/{uuid}/pages` (filters: orphan, weak, sort by inbound links)
- `GET /projects/{uuid}/suggestions` (filters: status, min score, source, target; paginated)
- `PATCH /suggestions/{id}` (approve or reject), plus a bulk endpoint
- `GET /projects/{uuid}/graph` (nodes and edges for the graph view)
- `GET /projects/{uuid}/export.csv` (streamed, approved suggestions)
- WordPress (phase 6): connect, verify, preview diff, apply.
- Public, no auth: `GET /demo` returns the preloaded demo project read-only.

Use API Resources for responses, Form Requests for validation, and Policies so users only see their own projects. Rate limit project creation per user and per IP.

## 7. WordPress integration (stretch, phase 6)

- Auth with WordPress Application Passwords over HTTPS. Store the password with an encrypted cast. Verify the connection before saving.
- Fetch the post or page by slug using `context=edit` to get raw content.
- Insert the link at the first occurrence of the anchor that is not already inside an `<a>` tag, a heading, or a shortcode. Handle Gutenberg block markup safely.
- Always show a before/after diff and require explicit confirmation before saving. Mention in the UI that WordPress revisions allow rollback.
- Test only against a local WordPress install (LocalWP). Never against a real client site.

## 8. Frontend and design direction

The UI must not look like a generic AI-generated SaaS dashboard. Use the frontend-design skill if it is installed, and follow this direction:

**Concept:** a precise instrument for mapping a website, closer to a cartographer's or surveyor's tool than a marketing dashboard. The link graph is the hero of the product, not an afterthought.

**Requirements:**
- Commit to one clear aesthetic and apply it consistently. Pick a distinctive, readable type pairing (not Inter, not Roboto, not system defaults) and a restrained palette with one purposeful accent color used for "opportunity" (suggested links) and a separate warning color for orphans.
- Establish real hierarchy. Not every block is a card. Vary spacing, weight, and density on purpose.
- Data-dense screens (suggestions table) should feel calm and scannable: aligned numbers, clear status states, keyboard shortcuts for approve (A) and reject (R).
- Every async state is designed: empty, loading (skeletons, not spinners everywhere), partial progress, error with a retry, and "server is waking up" for cold starts.
- Light and dark themes, both intentional.
- Responsive down to mobile for viewing, desktop-first for reviewing.
- Accessible: visible focus states, sufficient contrast, labels on all controls, graph has a table alternative.

**Avoid these generic tells:** purple-to-blue gradients, glassmorphism, identical rounded cards with the same soft shadow everywhere, one border radius on everything, all-caps tracked eyebrow labels above every heading, emoji as icons, arrows appended to every button label, stock "hero with gradient blob" landing page, lorem ipsum.

**Screens:**
1. Landing page: explains the problem in one sentence, shows a real screenshot or live embed of the demo graph, one clear call to action to open the demo.
2. Auth screens.
3. Projects list.
4. New project form with the sitemap URL and options.
5. Project overview: live pipeline progress by stage, then summary stats (pages, orphans, weak pages, suggestions) and the link graph. Orphans visually obvious as disconnected nodes. Clicking a node opens a side panel with the page's inbound and outbound links and its suggestions.
6. Suggestions review: filterable table, source and target with titles, anchor highlighted inside its context sentence, scores, approve/reject, bulk actions, CSV export.
7. Pages table: sortable by inbound links, orphan/weak filters.
8. WordPress connection and apply flow with diff preview (phase 6).

## 9. Free hosting requirements

- Must work on a single small server (Oracle Cloud Always Free ARM VM) AND on Render's free web service with the queue worker running inside the same Docker container under Supervisor.
- Provide a production `Dockerfile` (ARM and x86 compatible) that runs php-fpm, nginx, and one queue worker via Supervisor, and runs migrations on boot.
- Database connection via env vars so an external free MySQL (TiDB Cloud or Aiven) works. Support SSL options in the DB config.
- Seeder that creates a fully processed demo project (`is_demo = true`) from saved fixture data so the demo works without crawling or calling Gemini.
- A health endpoint for uptime checks.
- Frontend handles the backend cold start gracefully (see design section).

## 10. Build phases

- **Phase 0: Setup.** Monorepo, Laravel API, React app, Tailwind, Pest, linters (Pint, ESLint, Prettier), `CLAUDE.md`, `.env.example` files, GitHub Actions running tests on push.
- **Phase 1: Crawl.** Sitemap parsing, `SafeUrlGuard`, `UrlNormalizer`, crawling jobs, content extraction, project status polling, projects list and overview with progress. Tests use fixture HTML and `Http::fake()`, no real network.
- **Phase 2: Link analysis.** Link extraction, orphan and weak page detection, pages table, graph view. At this point the app is useful without AI.
- **Phase 3: Embeddings.** Gemini client, embedding jobs with caching and rate limiting, similarity, candidate generation.
- **Phase 4: Anchor suggestions.** Gemini text calls, strict validation, suggestions review screen, approve/reject, bulk actions, CSV export.
- **Phase 5: Polish and deploy.** Landing page, demo seeder, design pass across all screens, empty/error/cold-start states, Dockerfile, deployment docs.
- **Phase 6: WordPress (stretch).** Application password connection, diff preview, apply, status tracking.

## 11. Testing expectations

- Unit tests: `UrlNormalizer`, `SafeUrlGuard`, sitemap parser (normal and index), content extractor (fixture pages from real WordPress themes), anchor validator, similarity and priority scoring.
- Feature tests: project creation, policies (users cannot see others' projects), rate limits, full pipeline with faked HTTP and faked Gemini responses, CSV export.
- No test may hit the real network or the real Gemini API.

## 12. README (write in phase 5)

Include: one-paragraph pitch, screenshot/GIF, live demo link, architecture diagram (Mermaid), the pipeline stages, key decisions and why (URL normalization, content-only links, SSRF guard, validating AI output, caching by content hash, rate limiting), known limits and how it would scale (vector DB past a few thousand pages, Redis queues, horizontal workers), local setup, deployment guide for both hosting options, and a note that it was built to automate internal linking work I previously did by hand for a commercial insurance website.

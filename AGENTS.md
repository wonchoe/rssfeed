# AGENTS.md

## Project Context

This repository powers the Laravel-based feed ingestion platform for `rss.cursor.style`.

Local development assumptions:
- Primary dev domain: `rss.cursor.style`
- Local DNS mapping: `rss.cursor.style` resolves to `127.0.0.1`
- Treat `rss.cursor.style` as the default local host unless told otherwise

## Product Direction

Build a modern feed ingestion backend that supports:
- source discovery
- fetch and parse workflows
- deterministic extraction first, AI fallback second
- shared source caching across many subscribers
- deduplication
- delivery to Telegram first
- extensibility for Slack, Discord, email digests, and webhooks

## Architecture Principles

Use a queue-driven and event-driven Laravel architecture:
- Redis-backed queues and cache
- Horizon for worker visibility and control
- scheduler for polling, pruning, and metrics snapshots
- domain-oriented boundaries under `app/Domain`
- service contracts + data objects (DTO-style classes)

Pipeline stages should remain cleanly separated:
1. source discovery
2. fetch
3. parse
4. normalize
5. deduplicate
6. cache
7. deliver

## Extraction Priority Order

Always prefer the cheapest and most reliable extraction strategy in this order:
1. RSS
2. Atom
3. JSON Feed
4. feed autodiscovery from HTML
5. deterministic HTML parsing
6. AI-assisted schema fallback only when needed

Do not default to scraping if feed discovery has not been attempted.
Do not run AI extraction as the primary path for every source.

## Shared Source Caching Rules

The system is source-centric, not user-fetch-centric:
- one source can have many subscribers
- fetch each source once per polling interval
- reuse fetched/parsed output for all subscribers
- deduplicate by canonical URL hash and content hash
- keep user-submitted URL variants in `source_aliases` and resolve work against canonical `sources`
- regular scheduler polling should prioritize `usage_state=active`; inactive/cold sources refresh on demand or rare checks
- record `parse_attempts` + `source_snapshots` for root-cause debugging and parser repair workflows

## Domain Structure

Use these high-level areas:
- `app/Domain/Source`
- `app/Domain/Parsing`
- `app/Domain/Article`
- `app/Domain/Delivery`
- `app/Domain/Subscription`
- `app/Support`
- `app/Data`
- `app/Events`
- `app/Jobs`
- `app/Listeners`

## Preferred Service Contracts

- `SourceDiscoveryService`
- `FeedParserService`
- `HtmlCandidateExtractor`
- `AiSchemaResolver`
- `SchemaValidator`
- `ArticleNormalizer`
- `DeduplicationService`
- `DeliveryDispatcher`
- `TelegramDeliveryService`

## Preferred Events

- `SourceCreated`
- `SourceDiscovered`
- `SourceFetchRequested`
- `SourceFetched`
- `SourceSchemaRequested`
- `SourceSchemaResolved`
- `ArticlesParsed`
- `NewArticlesDetected`
- `DeliveryRequested`
- `DeliverySucceeded`
- `DeliveryFailed`

## Preferred Jobs

- `DiscoverSourceTypeJob`
- `FetchSourceJob`
- `ExtractHtmlCandidatesJob`
- `ResolveSchemaWithAiJob`
- `ValidateSchemaJob`
- `ParseArticlesJob`
- `NormalizeArticlesJob`
- `DetectNewArticlesJob`
- `QueueTelegramDeliveriesJob`
- `SendTelegramMessageJob`

## Coding Expectations

When implementing:
- keep boundaries explicit between discovery, parsing, normalization, dedupe, and delivery
- favor clear interfaces and dependency injection
- make retries/backoff explicit for external I/O jobs
- avoid brittle selectors and unstable parsing logic
- return structured data objects from core domain services
- operate delivery on normalized article entities, not raw payloads
- sanitize parsed summaries to plain text for UX/API output
- extract and persist article image URLs when available in feed payloads
- keep AI schema repair in shadow mode first; activate only after validation on recent snapshots

## Operational Defaults

- App URL should be `http://rss.cursor.style`
- Queue and cache should use Redis
- Session should remain environment-driven (database by default in local setup)
- Horizon access should be restricted in non-local environments

---

## Production Deployment

### Infrastructure overview

| Layer | Details |
|---|---|
| Server | `ubuntu@10.0.0.140` (ARM64) |
| App repo | `/home/ubuntu/rssfeed` on prod |
| k3s manifests repo | `/home/ubuntu/k3s-cursor.style` on prod, `/mnt/laravel/k3s-cursor.style` locally |
| Manifests path | `rssfeed/` in `k3s-cursor.style` repo |
| Docker Hub image | `wonchoe/rssfeed:<timestamp>` |
| Namespace | `rssfeed` |
| Domain | `rss.cursor.style` |
| Secret source | AWS SSM Parameter Store at `/project/rssfeed/.env` |
| ArgoCD | syncs from `rssfeed/apps/rssfeed/overlays/prod` |

### Full deploy workflow

```bash
# ── 1. Push local code changes ────────────────────────────────────────────────
cd /mnt/laravel/rssfeed
git add -A
git commit -m "deploy: <description>"
git push

# ── 2. SSH to prod server ─────────────────────────────────────────────────────
ssh ubuntu@10.0.0.140

# ── 3. Pull latest code on server ────────────────────────────────────────────
cd /home/ubuntu/rssfeed
git pull

# ── 4. Build ARM64 Docker image on server ────────────────────────────────────
TAG=$(date +%Y%m%d%H%M%S)
docker build -t wonchoe/rssfeed:$TAG .

# ── 5. Push image to Docker Hub ───────────────────────────────────────────────
docker push wonchoe/rssfeed:$TAG

# ── 6. Update k3s manifests with new image tag ───────────────────────────────
cd /home/ubuntu/k3s-cursor.style
git pull

# Update the prod overlay with the new tag
sed -i "s/newTag: .*/newTag: \"$TAG\"/" rssfeed/apps/rssfeed/overlays/prod/kustomization.yaml

git add rssfeed/apps/rssfeed/overlays/prod/kustomization.yaml
git commit -m "rssfeed: deploy image tag $TAG"
git push

# ── 7. Trigger ArgoCD sync ────────────────────────────────────────────────────
argocd app sync rssfeed --grpc-web
# or let automated sync pick it up within ~3 minutes
```

### First-time bootstrap (apply ArgoCD root app)

Run once on the prod server to register the rssfeed root app with ArgoCD:

```bash
kubectl apply -f /home/ubuntu/k3s-cursor.style/rssfeed/bootstrap/root.yaml
```

This creates the `rssfeed-root` Application which then manages all resources
under `rssfeed/` automatically via the `apps/rssfeed/app.yaml` Application.

### ESO — storing the .env secret

The `.env` file must be stored in AWS SSM Parameter Store under the path
`/project/rssfeed/.env` as a `SecureString`. ESO will sync it into the
`rssfeed-secrets` Kubernetes Secret in the `rssfeed` namespace, which the
pod mounts at `/var/www/.env`.

To update the secret on AWS SSM:

```bash
aws ssm put-parameter \
  --name "/project/rssfeed/.env" \
  --value "$(cat /mnt/laravel/rssfeed/.env)" \
  --type SecureString \
  --overwrite \
  --region us-east-1
```

### Key manifest locations (local)

```
/mnt/laravel/rssfeed/
  Dockerfile                          ← multi-stage: node assets + php-fpm + nginx + horizon
  docker/
    nginx.conf                        ← nginx vhost
    supervisord.conf                  ← nginx + php-fpm + horizon + scheduler
    entrypoint.sh                     ← migrate → cache → supervisord
    php.ini                           ← production PHP settings

/mnt/laravel/k3s-cursor.style/
  rssfeed/
    kustomization.yaml                ← apps-of-apps root
    bootstrap/root.yaml               ← ArgoCD root Application
    apps/rssfeed/
      app.yaml                        ← ArgoCD Application (prod)
      base/
        dep.yaml                      ← Deployment
        svc.yaml                      ← Service (ClusterIP :80)
        ingressroute.yaml             ← Traefik IngressRoute (rss.cursor.style)
        kustomization.yaml
      overlays/prod/
        kustomization.yaml            ← image tag pinning — update this per deploy
  eso/
    rssfeed-env.yaml                  ← ExternalSecret → /project/rssfeed/.env
  namespaces/
    rssfeed.yaml                      ← Namespace manifest
```

### Docker image process notes

- **Always build on the prod server** (`ubuntu@10.0.0.140`) — it is ARM64; building
  locally on x86_64 would produce mismatched architecture images.
- The image runs four processes via supervisord: `nginx`, `php-fpm`, `horizon`, `scheduler`.
- `entrypoint.sh` runs `artisan migrate --force` and caches config/routes/views before
  handing off to supervisord.
- The `.env` file is **never baked into the image** — it is always injected at runtime
  from the `rssfeed-secrets` Kubernetes Secret via volume mount at `/var/www/.env`.


# imgproxy Pro — Server Deployment & Presets

> Devops handoff for the two-server imgproxy pipeline behind mediary.
> Goal: fetch each source master **once**, normalize to a controlled "archive"
> derivative (imgproxy Pro → our S3), serve everything downstream (thumb, observe,
> IIIF tiles, HTR) from cheap derived images. Durable S3 cache stays clean & bounded.
>
> See also: `media-mediary-imgproxy-architecture` (full boundary + rationale).

---

## 1. `presets.txt` (Server A, Pro)

One preset per line. **Commit this to the repo** — the Symfony app reads the same file as the single source of truth. Place on Server A at the path referenced by `IMGPROXY_PRESETS_PATH`.

```
thumb=rs:fit:512:512:0:0/q:80/f:webp
display=rs:fit:1280:1280:0:0/q:82/f:webp
archive=rs:fit:3000:3000:0:0/q:88/f:webp
```

- `rs:fit:W:H:enlarge:extend` → `fit` (no crop), `0` enlarge (never upscale), `0` extend (no pad). `q` quality, `f` format.
- **thumb** (512) — UI thumbnails **and** the blind *observe* vision input (512px ≈ one OpenAI tile; Claude downscales ~1568 anyway). One small rung serves both — 512 is plenty for a thumbnail.
- **display** (1280) — the intermediate: detail pages, retina/hi-dpi cards, IIIF poster/fallback. No 2048 rung needed — deep zoom is IIIF tiles off the archive on Server B.
- **archive** (3000) — normalized working master, ~300 DPI for HTR. Source for HTR + all downstream derivatives.
- Ladder is ~2.5× per step (512 → 1280 → 3000), no redundant sizes. Fewer presets = smaller bounded cache on the presets-only Pro server.
- Only split out `observe=768` later if OpenAI high-detail starts upscaling 512.
- Usage: `/{signature}/pr:archive/plain/{source-url}` (or just `archive` in presets-only mode).

---

## 2. Generate signing key + salt (run twice)

```bash
xxd -g 2 -l 64 -p /dev/random | tr -d '\n'; echo
```

First output → `IMGPROXY_KEY`, second → `IMGPROXY_SALT`. Use the **same** key/salt on both servers so the app signs once for both.

---

## 3. Server A — imgproxy **Pro** (derivatives, cached, presets-only)

```env
# signing
IMGPROXY_KEY=<hex from step 2>
IMGPROXY_SALT=<hex from step 2>

# presets + lockdown (bounded cache cardinality)
IMGPROXY_PRESETS_PATH=/etc/imgproxy/presets.txt      # ⚠️ see §7 — may need inline IMGPROXY_PRESETS instead
IMGPROXY_ONLY_PRESETS=true

# internal Pro cache → OUR S3   ⚠️ §7: confirm exact var names for your Pro build
IMGPROXY_CACHE_USE=s3
IMGPROXY_CACHE_BUCKET=<bucket, e.g. museado-derivatives>
IMGPROXY_CACHE_S3_REGION=<region, e.g. us-east-1>
IMGPROXY_CACHE_PATH_PREFIX=derivatives/
IMGPROXY_CACHE_REPORT_ERRORS=true
# IMGPROXY_CACHE_S3_ENDPOINT=                          # ONLY for R2 / Spaces / MinIO

# allow s3:// sources (if masters live in S3)
IMGPROXY_USE_S3=true

# source limits (archival scans are big)
IMGPROXY_MAX_SRC_RESOLUTION=80                         # megapixels; set explicitly
# IMGPROXY_MAX_SRC_FILE_SIZE=

# Pro is billed PER WORKER across all instances — keep this small
IMGPROXY_WORKERS=4

# strip metadata is ON by default (orientation baked, EXIF/ICC dropped) — leave as-is
# AWS creds (or use an IAM role on the instance)
AWS_ACCESS_KEY_ID=<…>
AWS_SECRET_ACCESS_KEY=<…>
```

---

## 4. Server B — imgproxy **OSS** (IIIF tiles, no cache, free)

```env
IMGPROXY_KEY=<same hex>
IMGPROXY_SALT=<same hex>
# NO IMGPROXY_CACHE_USE  → never writes durable S3
IMGPROXY_USE_S3=true                                   # tiles read the archive snapshot from our S3
IMGPROXY_MAX_SRC_RESOLUTION=80
IMGPROXY_WORKERS=16                                    # free; absorbs high-volume tile load
```

Put a CDN / short-TTL edge cache in front of B for hot zoom sessions. IIIF region → imgproxy `crop:<w>:<h>:<gravity>` (+ resize), read from the **archive snapshot URL**, not the master.

---

## 5. Routing + S3 lifecycle

- Reverse proxy: `/iiif/*` → **Server B**; `/derivatives/*` and preset paths → **Server A**.
- S3 lifecycle **expiration rule** on `<bucket>/derivatives/`. The Pro cache has **no eviction and no manual invalidation** — lifecycle is the only thing capping growth.

---

## 6. Blanks to fill before deploy

- [ ] S3 bucket name + region (+ endpoint if R2/Spaces/MinIO)
- [ ] AWS creds or IAM role with **RW** on that bucket/prefix
- [ ] Hostnames for Server A and Server B (+ TLS)
- [ ] `KEY`/`SALT` generated (§2)
- [ ] `presets.txt` placed on Server A and committed to repo
- [ ] S3 lifecycle rule on the cache prefix
- [ ] Worker counts confirmed against the Pro subscription tier

---

## 7. ⚠️ Verify against the installed imgproxy version (do NOT trust blind)

Most likely to differ by build — confirm before deploy:

1. **Presets from a file** — `IMGPROXY_PRESETS_PATH` exists on newer builds; older ones only take **inline** `IMGPROXY_PRESETS=archive=...,thumb=...,observe=...` (comma-separated). Fallback ready.
2. **`/info` endpoint** — confirm it's enabled in the Pro build and reachable (`/info/{sig}/.../plain/{src}`); some builds gate it. Treat the spec's `IMGPROXY_INFO_ONLY_PRESETS` lockdown var as **unverified** — confirm it exists before relying on it.
3. **Pro S3-cache var names** (`IMGPROXY_CACHE_*`) — exact names/structure vary by Pro version; check that version's docs. If they don't match, the cache silently won't write to S3.
4. **`IMGPROXY_ONLY_PRESETS=true` actually rejects raw options** on the deployed version (past leakage reports) — curl an ad-hoc resize, expect 4xx.
5. **`IMGPROXY_MAX_SRC_RESOLUTION` default** (50MP current docs, 16.8 on some builds) — set explicitly to 80.

---

## 8. Ingest sequence (per source master) — reference

1. `GET /info` (hashsum + dimensions + orientation) on the source master. *(once)*
2. Compute our archive key from accession / ARK / hashsum.
3. Request `pr:archive` against the master → normalized 3000px WebP.
4. Write that WebP to our S3 at the computed key (fetch-once boundary).
5. Register the archive object + metadata in folio/catalog.
6. Downstream points at the **archive snapshot URL**: `pr:thumb`/`pr:display` → Server A (cached); IIIF tiles → Server B (uncached, CDN); HTR → archive snapshot (or master for accuracy-critical sets).

> Metadata note: imgproxy strips EXIF/IPTC/XMP/ICC by default and bakes orientation into pixels. Read provenance (capture date, device, rights, color) off the master **at ingest** and store it in folio/catalog — derivatives won't carry it (`sm:0`/`kcr:1` to keep some).

# ADR-0017 — Excel import pipeline: OpenSpout, S3-compatible file store, queued worker with fencing

- **Status:** Accepted (D-072 M2-D2/M2-D3; D-081; D-082) · **Date:** 2026-10-17, amended 2026-10-18

## Context
M2 step 6 (doc 29 §1.6) imports master data from versioned Excel templates through the pipeline of doc 26 §5: staging,
preview with an error file, atomic reservation per file, worker leases with fencing, chunked commits that never commit a
row twice. Thousands of rows must fit a worker's memory; uploaded files and error files are kept as job history.

## Decision
1. **XLSX library: OpenSpout** `openspout/openspout` `^5.12.2` (MIT; PHP ~8.4–8.6; needs ext-zip/xmlreader, present in
   the API image). Streaming reader and writer, so memory stays flat with row count. PhpSpreadsheet rejected (memory).
2. **File store: S3-compatible disk** `imports` (Laravel `s3` driver, `league/flysystem-aws-s3-v3` `^3.35.3`). Local
   and CI use **SeaweedFS** `chrislusf/seaweedfs:3.97` (S3 API, Apache-2.0) in compose with a one-shot bucket job —
   D-082: MinIO (M2-D3) no longer publishes images. The production provider is chosen with hosting (O-008, M6). Keys are
   `tenant/<tenant_id>/imports/<job_id>/source.xlsx` and `…/errors.xlsx`; no public URLs — files are streamed through
   the API after the tenant/permission check. Files are kept with the job (history, doc 26 §5); a retention policy is
   decided with hosting (M6).
3. **Upload checks** before anything is stored: XLSX only (zip container with `[Content_Types].xml`), ≤ 10 MB,
   uncompressed total ≤ 100 MB and ≤ 2,000 entries (zip bomb), no `vbaProject.bin` (macros), no `xl/externalLinks/`.
   Antivirus scanning is a hook decided in M6 (O-008).
4. **Worker:** Laravel queue on Redis, queue `imports`, compose service `import-worker`. The job payload carries only
   tenant id, job id, phase and the acting user from the job row; the worker runs every step inside
   `TenantTransaction` as `eora_app` with that signed context (RLS applies). Permissions are rechecked at commit.
5. **Fencing (doc 26 §5):** `claim_generation`, `locked_by`, `lease_until` on `import_jobs`; claim/takeover only when
   free or expired; every chunk commit starts with `SELECT … FOR SHARE` on the job and requires its generation, a live
   lease and status `COMMITTING`. An expired lease is moved to `FAILED (WORKER_LOST)` (generation + 1) when the job is
   next read, re-uploaded or claimed — no cross-tenant sweeper is needed.
6. **Mapping (D-081):** columns are matched by header against the template version; no manual re-mapping in M2.
7. **Chunk size 500**; valid-rows-only templates commit per chunk with the `import_source_rows` ledger; routing/BOM/map
   (step 6c) commit all-or-nothing in one fenced transaction and land as DRAFT.

## Consequences
+ Bounded memory, idempotent uploads, no double commits, no permanently locked job.
− Two more compose services (SeaweedFS and its bucket job) and one more worker process; the AWS SDK is a large
  dependency.
− Files grow without a retention policy until M6.

## Verification
B2-AT-47..52, 58..62, 69..73 as Pest tests (fencing exercised with two simulated workers), upload-check tests (macro,
external link, zip bomb, wrong type, size), import of a 2,000-row item file.

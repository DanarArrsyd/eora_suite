# Sample import files (M2 UAT §5)

Filled import templates for the Demo Stamping tenant (plant P1, starter units KG, PCS, SHEET), numbered in the order the
templates depend on each other. All data is fictitious. Effective dates are 2027-01-04; to write the set again with
another first date (today or later when imported):

```bash
docker compose run --rm -v "$PWD/docs/uat/samples:/out" --entrypoint php db-migrate artisan eora:demo:import-samples /out --from=YYYY-MM-DD
```

The rows live in `apps/api/app/Platform/Demo/ImportSamples.php`; `ImportSamplesTest` imports the whole set into a new
tenant and expects every row committed. Maps (17) need the routing and BOM versions from 15 and 16 submitted first.

`99-items-with-errors.xlsx` fails on purpose (UAT §7, stack E2E): an unknown unit, a code used twice in the file, an
unknown item type, a drawing revision without a drawing number and an item the set already created — and one valid row
(NUT-M10). `eora:demo:master` loads 01–16 into a seeded demo tenant through the import pipeline (routings and BOMs stay
Draft).

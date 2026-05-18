# v160 Separate Public and DJ Favicons

## Changes

- Adds separate favicon sets:
  - Public site: neon DTT Events circle
  - DJ/Admin portal: generic neon music note
- Public pages use:
  - `/assets/favicon-public-32.png`
  - `/assets/favicon-public-192.png`
  - `/assets/favicon-public-180.png`
- Admin pages use full main-domain paths so the `dj.` subdomain can load them:
  - `https://dancethruthedecades.co.uk/assets/favicon-dj-32.png`
  - `https://dancethruthedecades.co.uk/assets/favicon-dj-192.png`
  - `https://dancethruthedecades.co.uk/assets/favicon-dj-180.png`

## Files added

- `assets/favicon-public.png`
- `assets/favicon-public.ico`
- `assets/favicon-public-16.png`
- `assets/favicon-public-32.png`
- `assets/favicon-public-48.png`
- `assets/favicon-public-64.png`
- `assets/favicon-public-180.png`
- `assets/favicon-public-192.png`
- `assets/favicon-public-512.png`
- `assets/favicon-dj.png`
- `assets/favicon-dj.ico`
- `assets/favicon-dj-16.png`
- `assets/favicon-dj-32.png`
- `assets/favicon-dj-48.png`
- `assets/favicon-dj-64.png`
- `assets/favicon-dj-180.png`
- `assets/favicon-dj-192.png`
- `assets/favicon-dj-512.png`

## SQL

No SQL changes.

## Suggested Git commit title

v160 Separate Public and DJ Favicons

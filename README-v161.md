# v161 Upload Path Repair

## Fix

Repairs the issue where uploads were being written into an accidental folder such as:

```text
dttd-portalhttps:
```

## What changed

- Adds central helper:

```text
includes/upload-paths.php
```

- Keeps filesystem paths and public URLs separate.
- Uploads should physically save to:

```text
uploads/events/
```

- DB/browser URLs can remain:

```text
https://dancethruthedecades.co.uk/uploads/events/filename.jpg
```

## Repair page

After deploying, open:

```text
https://dj.dancethruthedecades.co.uk/repair-upload-paths.php
```

Click **Run Repair** to move misplaced files from the bad `https:` folder back into `uploads/events`.

## SQL

No SQL changes.

## Suggested Git commit title

v161 Upload Path Repair

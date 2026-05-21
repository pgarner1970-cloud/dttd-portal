# v164 Event Upload Path Fix

## Critical fix

Fixes the broken event flyer upload destination in:

```text
admin/event-edit.php
```

The broken code was creating a rogue folder like:

```text
dttd-portalhttps:
```

because it mixed a filesystem path with a public URL.

## Correct behaviour

Physical uploaded files now save to:

```text
dttd-portal/uploads/events/
```

The database still stores the public browser URL:

```text
https://dancethruthedecades.co.uk/uploads/events/filename.jpg
```

## Diagnostic page

After deploying, you can check paths here:

```text
https://dj.dancethruthedecades.co.uk/upload-path-check.php
```

## Manual cleanup

After confirming new uploads work, move any files from:

```text
dttd-portalhttps:/dancethruthedecades.co.uk/uploads/events/
```

to:

```text
dttd-portal/uploads/events/
```

Then delete the rogue `dttd-portalhttps:` folder.

## SQL

No SQL changes.

## Suggested Git commit title

v164 Event Upload Path Fix

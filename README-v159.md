# v159 Admin Events PHP Tag Fix

## Fix

- Fixes `admin/events.php` where `admin_upload_url()` was accidentally output as plain text.
- Ensures the file starts with `<?php`.
- Removes the duplicate later `<?php` created by the repair.
- Keeps the v158 request queue polling fix.

## SQL

No SQL changes.

## Suggested Git commit title

v159 Admin Events PHP Tag Fix

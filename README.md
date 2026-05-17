# Dance Thru the Decades Portal v17 - Request Queue Grouping

## Changes

- Groups matching song requests by exact normalised song title + artist.
- Example grouping:
  - `Take on me` + `Ahha`
  - `take on me` + `ahha`
- Shows one card per song/artist group.
- Lists each individual guest/dedication inside the grouped card.
- Shows request count per grouped song.
- Action buttons update the whole grouped song request.
- Request buttons now use a safer 2x2 layout so Reject is not clipped.

## SQL

No SQL to run.

## Suggested Git commit title

v17 Request Queue Grouping

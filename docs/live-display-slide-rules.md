# Live Display Slide Rules

The HDMI/live display is one page. JavaScript renders each slide as an `article.display-slide` inside the display stage and rotates the visible active slide.

## Display Modes

- `standby`: no current live event. Show the no-current-event standby slide, plus upcoming events if available.
- `live_event`: an event is running. Show only enabled event slides from the slide settings.
- `goodnight`: the event is at the planned end time window and both decks are clear. Show the goodnight slide.

The standby slide must not appear during `live_event`. If an enabled slide has no renderer, that is a bug; it must not fall back to standby.

## Live Event Slides

The API builds the available live-event slide list from enabled admin settings and available data:

- `venue`: only when venue details/social links exist.
- `qr`: event QR code.
- `event_timer`: event finish countdown and request close countdown.
- `music_board`: request queue and played requests.
- `now_playing`: current active-deck track, only displayed when playback is detected by the browser.
- `up_next`: loaded next track, only displayed when available.
- `recent`: event play history.
- `requests`: DJ playlist / coming up list.
- `photos`: approved event photos.
- `partners`: active partners.
- `upcoming`: upcoming public events.
- `sponsors`: configured event sponsors.

## Priority Rotation

Outside final stretch mode, the API returns a deterministic three-pass carousel:

- High priority: included every pass.
- Normal priority: included every pass.
- Low priority: included only on the third pass.

Each slide uses its configured duration in seconds.

## Final Stretch

When final stretch mode is active:

- Low-priority slides can be hidden if the setting is enabled.
- Remaining slides appear once per loop.
- All remaining slides use the configured final-stretch duration.

Final stretch activation is controlled by the admin setting: request close, 30 minutes left, 15 minutes left, or request close/30 minutes left.

## Event End / Goodnight

Within ten minutes either side of the specified event end time, if both decks are clear, the display enters `goodnight`.

If decks are still loaded, the display remains in live event context rather than dropping to standby.

After the goodnight window has passed and both decks are clear, the display can return to `standby`.

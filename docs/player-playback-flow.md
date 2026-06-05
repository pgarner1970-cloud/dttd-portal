# DJ Mixer Playback Flow Notes

This document records how the current DJ mixer appears to manage Player A / Player B playback, Spotify Connect commands, local deck state, track progress, and played-history logging.

It is intended as a working reference before changing playback behaviour. The main risk area is single-Spotify-account operation, where starting playback on one deck can pause the other deck at Spotify account level.

## Key files

- `admin/spotify/mixer.php` renders the DJ mixer/admin screen.
- `assets/spotify-mixer.js` drives the browser UI, sends mixer commands, renders deck state, and polls for updates.
- `admin/spotify/mixer-api.php` is the main command/state API used by the mixer UI.
- `includes/spotify.php` contains Spotify account/profile helpers and the low-level current-playback/device API calls.
- `api/public-now-playing.php` provides the public homepage/event-page live soundtrack scroller data.
- `includes/track-history.php` stores and reads event played-track history.

## Browser-side flow

The mixer UI JavaScript is in `assets/spotify-mixer.js`.

### Polling

The browser calls:

```text
GET admin/spotify/mixer-api.php?action=state
```

The current polling interval is every 5 seconds.

The returned `state` object is used to render:

- assigned devices for Player A and Player B;
- loaded track cards;
- deck play/standby indicators;
- progress bars;
- DJ playlist;
- public requests;
- played history.

### User commands

Most button clicks call:

```text
POST admin/spotify/mixer-api.php
```

with an `action` value such as:

- `assign_devices`
- `load`
- `auto_load`
- `load_request`
- `play_toggle`
- `play`
- `pause`
- `seek_relative`
- `seek_start`
- `seek_end`
- `clear_loaded`
- `return_loaded`
- `mark_loaded_played`
- `emergency_swap`

After a command, the API returns a fresh `state` object and the browser immediately re-renders the mixer.

## Stored mixer state

The mixer stores deck state in `app_settings` style values accessed through `mx_setting()`, `mx_set()`, and `mx_json()`.

Important keys include:

```text
spotify_mixer_device_a
spotify_mixer_device_b
spotify_mixer_loaded_a
spotify_mixer_loaded_b
spotify_mixer_resume_a
spotify_mixer_resume_b
spotify_mixer_playlist
spotify_mixer_history
spotify_mixer_auto_start_opposite
```

`spotify_mixer_loaded_a` and `spotify_mixer_loaded_b` hold the loaded track object for each deck.

Important loaded-track fields include:

```text
id
title
artist
album
image
duration_ms
loaded_origin
played_on_deck
played_qualified
position_base_ms
position_updated_at
paused_position_ms
resume_locked
end_seen_ms
end_armed_at
playback_started_at
expected_finish_at
history_logged_at
```

These fields allow the mixer to keep its own progress memory instead of relying entirely on Spotify Connect.

## Loading a track

The main load path is:

```text
POST mixer-api.php action=load / load_request / load_track_direct
```

Server-side flow:

1. The requested deck is normalised to `a` or `b`.
2. The deck's assigned Spotify device is read.
3. The API checks whether that deck/device is currently playing.
4. If the deck is playing, loading is blocked.
5. The track is cleaned and stored in `spotify_mixer_loaded_a` or `spotify_mixer_loaded_b`.
6. The loaded track is reset to an unplayed state:
   - `played_on_deck = false`
   - `played_qualified = false`
   - progress fields reset to zero/null.
7. If loading from the playlist, the playlist can be updated/cleaned at the same time.

Loading does not itself make the track count as played.

## Playing a loaded track

The main deck play/pause button uses:

```text
POST mixer-api.php action=play_toggle&deck=a
POST mixer-api.php action=play_toggle&deck=b
```

There are also direct play paths:

```text
action=play
action=play_track_direct
action=play_request_direct
```

For Spotify tracks, the main play path eventually calls:

```text
mx_play_track($device_id, $track_id, $position_ms, $deck)
```

That function sends Spotify Web API commands broadly in this order:

```text
PUT /v1/me/player
  Body: { device_ids: [selected_device_id], play: false }
  Purpose: transfer account playback to the deck device without immediately playing.

GET /v1/me/player
  Purpose: wait briefly for Spotify Connect to report the selected device as active.

PUT /v1/me/player/pause?device_id=...
  Purpose: quieten stale/old account context during handover.

PUT /v1/me/player/play?device_id=...
  Body: { uris: [spotify:track:<id>], position_ms: <optional> }
  Purpose: explicitly start the selected track on the selected deck device.

PUT /v1/me/player/seek?device_id=...&position_ms=...
  Purpose: re-assert the resume/seek position where required.
```

After playback is started, the loaded deck is armed using `mx_arm_loaded_track_for_playback()`.

That sets:

```text
played_on_deck = true
position_base_ms = start position
position_updated_at = now
paused_position_ms = null
resume_locked = false
end_seen_ms = start position
end_armed_at = now
playback_started_at = now
expected_finish_at = estimated finish time
```

## Pausing a deck

The explicit pause path is:

```text
POST mixer-api.php action=pause&deck=a/b
```

or pressing the deck toggle while the deck is detected as playing.

Before sending the Spotify pause command, the mixer calls:

```text
mx_save_resume_position($deck, $device_id, $track)
```

This tries to save the most accurate resume position by:

1. reading Spotify current playback for that deck account;
2. checking the current Spotify device matches the deck device;
3. checking the current Spotify track matches the loaded track;
4. using Spotify `progress_ms` where available;
5. falling back to the mixer's own stored `position_base_ms` / `paused_position_ms` if Spotify no longer reports the active deck cleanly.

It then stores a resume object:

```text
spotify_mixer_resume_a / spotify_mixer_resume_b
```

with:

```text
track_id
position_ms
saved_at
```

and marks the loaded track as resume-locked.

## Progress synchronisation

During each `action=state` poll, the API calls `mx_state()`.

`mx_state()` reads current playback for the deck account(s):

```text
mx_playback('a')
mx_playback('b')
```

Internally this uses:

```text
GET /v1/me/player
```

via `dttd_spotify_current_playback_for_deck()`.

Then the mixer calls:

```text
mx_sync_loaded_position_from_playback('a', $loadedA, $deviceA, $playbackA)
mx_sync_loaded_position_from_playback('b', $loadedB, $deviceB, $playbackB)
```

A loaded deck's progress is updated only when all of these are true:

```text
Spotify active device id == deck assigned device id
Spotify current track id == loaded track id
Spotify progress_ms is present
```

If the same deck/track is actively playing, the mixer updates:

```text
position_base_ms
position_updated_at
end_seen_ms
expected_finish_at
```

This is the main mechanism that keeps the progress bar and resume position alive.

## Played-history threshold

A loaded track is not written to played history immediately.

The played threshold is:

```text
minimum of 50% of the track duration or 90 seconds
```

When the loaded track's stored/estimated progress reaches that threshold, `mx_mark_loaded_played_if_threshold()` marks:

```text
played_qualified = true
```

and logs history once through:

```text
mx_log_loaded_history_once()
mx_add_history()
dttd_history_log_track()
```

The database history row is used by public recently-played displays.

## Auto-unload / handover

During state polling, `mx_auto_unload_finished_deck()` checks whether a played deck has finished.

It uses a combination of:

- Spotify active device;
- Spotify current track;
- Spotify playing state;
- Spotify progress near the end;
- mixer-estimated position;
- expected finish time.

If a deck is finished, the loaded deck is cleared and the played track is logged if needed.

If `spotify_mixer_auto_start_opposite` is enabled, the mixer may then start the opposite loaded deck automatically.

## Single Spotify account behaviour

In the current one-account test setup, Player A and Player B share the same Spotify account/session.

Spotify only allows one active playback stream per account. Therefore:

1. Player A starts playing.
2. The account's active playback device becomes Player A.
3. The mixer can sync Player A progress while Spotify reports Player A as active.
4. Player B is loaded and played for headphone/cue checking.
5. Spotify transfers the same account's active playback from Player A to Player B.
6. Player A is effectively paused/stopped by Spotify account handover, even if the mixer did not send an explicit pause command to Player A.
7. Because the mixer did not explicitly pause Player A, `mx_save_resume_position('a', ...)` may not have run at that moment.
8. When the DJ presses play on Player A again, the mixer must rely on the last stored Player A progress from polling, or any resume fallback that already exists.

This explains the observed risk:

```text
Player A was playing and showing progress.
Player B was briefly played for cueing.
Spotify moved account playback to Player B.
Player A no longer has a live Spotify progress source.
When Player A is resumed, the UI may temporarily show stale/zero/incorrect progress until Spotify and mixer state re-sync.
```

It can also explain a button colour/status mismatch if the browser has not yet received a state poll where:

```text
active device == Player A assigned device
current track == Player A loaded track
is_playing == true
```

## Dual Spotify account behaviour

With two Spotify accounts, Deck A and Deck B should have separate playback sessions.

In that mode:

```text
mx_playback('a') reads account/profile assigned to Deck A
mx_playback('b') reads account/profile assigned to Deck B
```

Starting Player B should not pause Player A, because Spotify sees them as separate accounts.

This is the intended production model for proper A/B deck operation.

## Public live soundtrack scroller behaviour

The public scroller uses:

```text
api/public-now-playing.php
```

The intended logic is:

1. Confirm there is a live/active event.
2. Read mixer loaded state for Player A and Player B.
3. Read live Spotify playback for the configured deck account(s).
4. Match playback to the deck's assigned device and loaded track.
5. Pick the current-track candidate with the greatest playback/progress time if both decks are playing.
6. Merge with event track history.
7. Remove duplicates by Spotify track id and normalised title/artist.
8. Return current/recent tracks to the homepage/event-page JavaScript.

This avoids showing the currently playing track twice after it crosses the played-history threshold.

## Known risk / likely next improvement

The main weakness in single-account testing is that the mixer currently saves a resume position when the DJ explicitly pauses a deck, but Spotify can pause/replace a deck implicitly when another deck starts on the same account.

A future fix should consider capturing the outgoing deck's progress before starting another deck on the same Spotify account.

For example, before Player B starts on a shared account:

```text
If Player A is loaded and currently active on the shared Spotify account:
  save Player A resume/progress using Spotify progress_ms if possible;
  store it into spotify_mixer_loaded_a and spotify_mixer_resume_a;
then start Player B.
```

And vice versa before Player A starts.

This would make single-account testing more reliable and should also make status/progress recovery cleaner after cueing another deck.

A second possible improvement is to force an immediate post-playback state re-sync after `mx_play_track()` confirms playback, so the returned state already contains the new active device, track id, progress, and deck playing status rather than relying on the next 5-second browser poll.

# Phone 3D Viewer

A single page (`index.php`) shows a phone GLB model (Nokia 3310 or a modern
low-poly phone, switchable) in a dark studio scene with a grid floor. It sits
still, auto-rotating slowly when idle, until a phone starts streaming its
real rotation via the [phyphox](https://phyphox.org) app — then the model
follows along live, in near-real-time, buffered and smoothed for clean
playback. A small "Live from phyphox" badge and a HUD (orientation angles)
appear whenever a stream is being picked up.

Only rotation is tracked. Position/motion tracking (estimating movement by
double-integrating the accelerometer) was tried and removed — a phone
accelerometer alone can't be corrected against anything external (no
GPS/visual reference), so it never settled on something reliable. Rotation,
by contrast, comes straight from phyphox's attitude sensor and is accurate
and immediate, so that's what this focuses on.

Camera orbit/zoom is free with the mouse at any time (drag to orbit, scroll
to zoom, right-drag to pan) — moving the camera never interrupts a live
stream.

Controls sit at the bottom of the screen once the model has loaded:

- **Tracking: On/Off** — pauses/resumes applying the live rotation to the
  model, without stopping the underlying stream. While paused the badge at
  the top says so, and the model just holds still until turned back on.
- **Reset** — re-zeroes the displayed rotation to the phone's *current*
  orientation, so an awkward starting angle doesn't stay baked in. This
  only affects what's shown on screen — it's a local viewing convenience,
  not a server-side action, so the actual data phyphox is sending keeps
  flowing untouched.
- **Delay slider** — adjusts how much the live view buffers before playing
  a sample (default 400ms, 0–1000ms range). Lower it for less lag but
  choppier motion; raise it for smoother motion but more delay.
- **Interpolation slider** — adjusts how quickly the model eases toward
  each new buffered sample (default 0.20, 0.05–1.00 range). Lower is
  smoother but drifts a bit behind the data; higher is snappier but can
  look stepped if the buffer runs low. Separate from the delay slider,
  which controls *how much* is buffered rather than how fast it's applied.

Both sliders can be changed mid-stream without causing a jump.

Motion capture is done entirely through the phyphox app rather than the
browser: browsers block motion-sensor access on a plain `http://<LAN-IP>`
page (see "Known limitations"), and phyphox — being a native app, not a
browser page — isn't subject to that restriction. See "Streaming from
phyphox" below for setup.

## Structure

```
docker-compose.yml
Dockerfile
.env
www/
 ├── index.php               — the 3D viewer (scene, model switcher, live playback)
 ├── get-app.php             — phone-facing page: IP field, button + QR code to install the phyphox experiment
 ├── api/
 │   ├── db.php               — DB connection + lazy schema creation
 │   ├── models_list.php      — GET: scans assets/ for .glb files
 │   ├── motion_stream.php    — GET: generates the phyphox experiment for a given ?ip=
 │   ├── phyphox_ingest.php   — POST: receives streamed phyphox rotation data
 │   └── phyphox_live.php     — GET: recent rotation keyframes for whichever phone is streaming
 └── assets/
     ├── nokia_3310.glb
     └── low_poly_mobile_phone.glb
```

## Run it

```
docker compose up -d --build
```

| Service        | Address                  | Purpose                    |
|----------------|---------------------------|-----------------------------|
| Apache + PHP   | http://localhost:8080     | The 3D viewer               |
| phpMyAdmin     | http://localhost:8081     | Administer MySQL            |
| Portainer      | https://localhost:9443    | Administer Docker containers|
| MySQL          | internal `mysql:3306`     | Live rotation data          |

Credentials live in `.env` (not committed to git). Tables are created
automatically on first request — no manual migration step.

## Streaming from phyphox

1. Install phyphox from the App Store / Play Store.
2. On the phone, open **`http://<computer's LAN IP>:8080/get-app.php`** — a
   page with an editable IP field (pre-filled with the address you're
   already viewing it from, which is normally correct as-is), a button
   that hands the experiment to phyphox, and the same link as a QR code.
   The IP field controls where phyphox sends its sensor data — it's baked
   into the generated experiment (`api/motion_stream.php`) at the moment
   you tap the button or scan the code, so there's no need to edit
   anything inside phyphox itself, even on versions with no such menu
   option. If your computer's LAN address ever changes, just update the
   field here and re-tap/re-scan.
   - Typing a `phyphox://` link straight into a mobile browser's address
     bar is usually ignored — browsers only hand off a custom link scheme
     like that when you *tap* a real link or scan a QR code, which is
     exactly what this page is for.
   - Button doesn't do anything? Open phyphox, use its own QR-scanner (menu
     → "Add experiment via QR-Code" or similar), and scan the QR code on
     that same page instead.
3. Tap the **play** button to start streaming.

While it's streaming, open the site on a computer — the model picks it up
automatically and starts following along, no button press needed there. Tap
**stop** on the phone when done.

Since phyphox has no explicit "start/stop a recording" concept of its own —
it just streams small batches of data every 0.05s while its play button is
running — the server infers stream boundaries itself: a burst of incoming
data becomes one continuous stream, and a new one starts if nothing arrives
for 5 seconds (i.e. after you hit stop and later play again).

## Known limitations

- **~400ms of intentional lag**: the live view buffers briefly so it can
  interpolate between real samples instead of the model stepping/snapping
  between updates. That delay is deliberate — smoothness was prioritized
  over minimum latency, and it's adjustable via the delay slider.
- **Motion requires a secure context in a browser**, which is exactly why
  this project doesn't attempt browser-based motion capture at all — a
  plain `http://<LAN-IP>` page (what this runs as) can't get sensor access
  in modern browsers regardless of device, so phyphox (a native app, not
  subject to that restriction) is the only way real device motion gets in.

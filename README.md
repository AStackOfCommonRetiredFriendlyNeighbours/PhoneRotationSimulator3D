# Phone 3D Viewer

A single page (`index.php`) shows a phone GLB model (Nokia 3310 or a modern
low-poly phone, switchable) in a dark studio scene with a grid floor. It sits
still, auto-rotating slowly when idle, until a phone starts streaming its
real motion — then the model follows along live, in near-real-time,
buffered and smoothed for clean playback. A small "Live from …" badge and a
HUD (orientation angles, and position when available) appear whenever a
stream is being picked up.

There are two ways to stream a phone's motion in, and either can be used at
any time — whichever streamed most recently is what the viewer shows:

- **[phyphox](https://phyphox.org)** app — rotation only, from the phone's
  gyro/accelerometer/magnetometer fused "attitude" sensor. Zero setup
  beyond installing the app; see "Streaming from phyphox" below.
- **The companion Android app** (`android-app/`) — full position *and*
  rotation, using ARCore's camera+IMU visual-inertial tracking. More
  accurate and gives real 6DoF movement, at the cost of needing to be built
  and run from Android Studio onto a real ARCore-capable phone rather than
  just installing an app from a store. See `android-app/README.md`.

Position tracking was originally tried with the phone's accelerometer alone
(double-integrating it) and removed — an accelerometer alone can't be
corrected against anything external (no GPS/visual reference), so it never
settled on something reliable. The ARCore app solves that properly by
fusing in the camera, which is the only thing that actually works for this
(confirmed against phyphox's own documentation and its lead developer's own
statements on why phyphox itself doesn't attempt it).

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

Motion capture is done entirely through native apps rather than the
browser: browsers block motion-sensor *and* camera-pose access on a plain
`http://<LAN-IP>` page (see "Known limitations"), and neither phyphox nor
the ARCore companion app — being native apps, not browser pages — are
subject to that restriction.

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
 │   ├── arcore_ingest.php    — POST: receives streamed pose data from the Android app
 │   └── phyphox_live.php     — GET: recent keyframes for whichever phone is streaming (either source)
 └── assets/
     ├── nokia_3310.glb
     └── low_poly_mobile_phone.glb
android-app/                 — companion ARCore app (Android Studio project, see its own README)
```

## Run it

```
docker compose up -d --build
```

| Service        | Address                  | Purpose                    |
|----------------|---------------------------|-----------------------------|
| Apache + PHP   | http://localhost:8080     | The 3D viewer               |
| phpMyAdmin     | http://localhost:8081     | Administer MySQL            |
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

## Streaming from the ARCore app (camera-based, full position + rotation)

See `android-app/README.md` for the full setup (it needs Android Studio and
a real ARCore-capable phone, not just an app install). Once it's running
and pointed at the same server IP:port, it shows up on the viewer exactly
like phyphox does, badge included — except the model also moves through
space, not just rotates, and the HUD shows a live position readout too.

## Known limitations

- **~400ms of intentional lag**: the live view buffers briefly so it can
  interpolate between real samples instead of the model stepping/snapping
  between updates. That delay is deliberate — smoothness was prioritized
  over minimum latency, and it's adjustable via the delay slider.
- **Motion/camera-pose access requires a secure context in a browser**,
  which is exactly why this project doesn't attempt browser-based motion
  capture at all — a plain `http://<LAN-IP>` page (what this runs as)
  can't get sensor or camera-pose access in modern browsers regardless of
  device. Both phyphox and the ARCore app are native apps, so neither is
  subject to that restriction — it's specifically a browser-JavaScript
  thing, not a property of the network being plain HTTP.
- **The ARCore app needs Android Studio to build and run** — there's no
  Play Store listing for it, so getting it onto a phone means a USB cable
  and Developer options, not just tapping install. See its README for why.

## Tailnet-only deployment on the Mac mini

Application files are copied into the image. After code changes, rebuild:

```sh
docker compose up -d --build
```

Keep the existing `.env` on the server. The MySQL named volume persists
across rebuilds; never use `docker compose down -v` for updates.
The web and phpMyAdmin ports bind only to loopback. MySQL has no published port.

On the Mac mini, inspect existing routes, then add only the simulator route:

```sh
tailscale serve status
tailscale serve --bg --https=10000 http://127.0.0.1:8080
tailscale serve status
```

Do not reset Serve or alter Jellyfin (443) or Vikunja (8443).
Do not use Funnel for this deployment. Allow authorized users TCP port 10000
on the Mac mini in the tailnet policy; do not grant admin ports to guests.

Connect the phone and viewing computer to the tailnet. Open:

- Viewer: https://macmini.tailf8a8ee.ts.net:10000/
- Phone setup: https://macmini.tailf8a8ee.ts.net:10000/get-app.php

Download and open the experiment in phyphox, or scan the setup page's QR
code inside phyphox, then press play. Re-import existing experiments after
switching from HTTP to HTTPS.

`PUBLIC_URL` in Compose defaults to the Mac mini's HTTPS address. Override
it for another server. It controls both experiment download and sensor upload
URLs. Tailscale Serve provides the TLS certificate and remains tailnet-only.
When migrating the old HTTP route, first run
`tailscale serve --http=10000 off`, then enable the HTTPS route above.

phpMyAdmin is not served through Tailscale. For administration, forward its
loopback port over SSH and open http://127.0.0.1:8081 locally:

```sh
ssh -L 8081:127.0.0.1:8081 elias@macmini
```

Remove only the simulator route when needed:

```sh
tailscale serve --https=10000 off
```

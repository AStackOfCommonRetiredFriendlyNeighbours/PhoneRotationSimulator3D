# Phone Tracker (Android / ARCore)

The camera-based companion app for the Phone 3D Viewer. Instead of phyphox's
gyro+accelerometer-only "attitude" sensor, this uses ARCore's visual-inertial
odometry (camera + IMU fused) to get the phone's *real* 6DoF pose — position
in meters, not just orientation — and streams it to the same server phyphox
used to talk to, using HTTPS (or an explicit HTTP URL for local development).

## Why this needs Android Studio (not just a browser)

phyphox can't do this: its camera input is photometric-only (brightness/
color per frame) and its depth input is a single aggregated distance value —
neither is a position/pose source. Real camera-based tracking is what
ARCore/ARKit do, and that only exists as a native SDK, not something a
website can reach. Browsers also can't help here even in principle: WebXR
(the browser equivalent) requires a secure context (`https://`), which this
project's whole point was to avoid needing.

The good news buried in that: the `https://` requirement this project kept
running into is specifically a *browser JavaScript* restriction. A native
app has no such restriction — it can POST to a plain `http://<LAN-IP>`
address freely, exactly like phyphox already did. So "camera-based" and "no
HTTPS" were never actually in conflict; phyphox specifically just can't
deliver on the camera-based half.

## Requirements

- **Android Studio** (any recent version — it'll prompt to install any SDK
  platform/build-tool pieces it's missing the first time you open this).
- **A real Android phone that supports ARCore** ("Google Play Services for
  AR"). Most phones from the last several years do; if unsure, search
  "ARCore supported devices" for the current list. An emulator will *not*
  work for this — ARCore needs a real camera and real motion.
- A USB cable to run it onto that phone from Android Studio, with
  **Developer options → USB debugging** enabled on the phone (Settings →
  About phone → tap "Build number" 7 times to unlock Developer options).

## Opening and running it

1. Android Studio → **Open** → select this `android-app` folder (not the
   repo root — this folder specifically, the one with `settings.gradle.kts`
   in it).
2. Let it sync. First open will likely prompt to install a missing SDK
   Platform (compileSdk 34) and/or generate a Gradle wrapper — accept both;
   this is normal for a project handed to you as source rather than already
   opened once in Studio. If sync instead complains that the ARCore
   library needs a newer compileSdk, bump `compileSdk` and `targetSdk` in
   `app/build.gradle.kts` to whatever it asks for (35 or 36) — I picked 34
   for broad compatibility since I couldn't test-build this myself, but
   it's a one-line change if your installed SDK tools want newer.
3. Plug the phone in via USB, allow the "Allow USB debugging?" prompt on
   the phone, and pick it from the device dropdown in Android Studio's
   toolbar.
4. Press **Run** (the green triangle). First launch will ask for the
   camera permission on the phone — allow it. If Google Play Services for
   AR isn't installed yet, it'll prompt to install that too (from the Play
   Store, one-time, like any other app dependency).
5. In the app: type the same computer IP:port you'd have used for
   phyphox's `get-app.php` field (e.g. `192.168.1.20:8080`) and tap **Start
   Tracking**. You should see the camera feed and a status line saying
   "Tracking — sending to …".
6. Open the viewer (`http://<that-ip>:8080/`) on a computer — it should
   pick the stream up automatically, same as it did for phyphox, except now
   the model also moves through space, not just rotates. The badge will say
   "Live from ARCore app".

## If tracking looks bad / jumpy

ARCore (like all visual-inertial tracking) needs enough visual texture to
lock onto — a blank wall, a dark room, or a phone held very still pointed
at the ceiling will track poorly or lose tracking (`TrackingState.PAUSED`,
shown in the status line). Point it at a normal cluttered room and move it
slowly at first.

## Files

```
android-app/
 ├── settings.gradle.kts / build.gradle.kts / gradle.properties
 └── app/
     ├── build.gradle.kts            — ARCore + AndroidX dependencies
     ├── src/main/
     │   ├── AndroidManifest.xml     — camera/internet perms, cleartext traffic, ARCore "required"
     │   ├── java/ch/csbern/m169/phonetracker/
     │   │   ├── MainActivity.kt        — ARCore session lifecycle, GL render loop, UI
     │   │   ├── BackgroundRenderer.kt  — minimal camera passthrough (so you can see what you're pointing at)
     │   │   └── PoseSender.kt          — background-thread HTTP POST client
     │   ├── res/layout/activity_main.xml
     │   └── res/values/ (strings.xml, themes.xml)
```

## A note on accuracy

This is real camera+IMU visual-inertial odometry (the same category of tech
as ARKit), which is a different league from the accelerometer-only
approaches tried earlier in this project (and correctly abandoned — pure
accelerometer integration drifts unboundedly with no way to self-correct).
ARCore continuously corrects itself against what the camera sees, so it
stays accurate over much longer stretches. It can still drift slowly over a
long session or lose tracking briefly in a low-texture environment, which
is inherent to the technique, not a bug in this app.

## Mac mini tailnet deployment

Build/install the latest app after updating: older APKs hardcode HTTP and
cannot connect to the HTTPS-only server. Connect both devices to Tailscale.
Enter `https://macmini.tailf8a8ee.ts.net:10000` in the app and tap Start Tracking.
Open `https://macmini.tailf8a8ee.ts.net:10000/` on the viewing computer.
No phyphox experiment is needed for this app. The upload endpoint is
`/api/arcore_ingest.php`; do not append it to the app's server URL field.
Bare hostnames default to HTTPS; for LAN development enter the full
`http://192.168.1.20:8080` URL explicitly.

# Phone Tracker (Android / ARCore)

Native Android companion for Phone 3D Viewer. ARCore provides camera/IMU
position and rotation, uploaded to `/api/arcore_ingest.php`.

## Build

Open this directory in Android Studio and use its Gradle JDK 17.
The project currently requests Android SDK 34; install the SDK/build tools
required by Gradle. An ARCore-capable physical Android device and Google Play
Services for AR are required to test tracking.

```sh
./gradlew assembleDebug
```

The debug APK is written to `app/build/outputs/apk/debug/app-debug.apk`.
Install it using Android Studio or your Android development tooling.
Keep release signing keys and passwords outside this repository.

## Connect

1. Connect the phone and viewing computer to the server's authorized tailnet.
2. Enter your server's full HTTPS origin, for example
   `https://server.example.ts.net:10000` (replace this placeholder).
3. Grant camera permission and tap Start Tracking.
4. Open the same address in the viewer's browser and check the app's confirmed
   send counter and the viewer's position/rotation updates.

Do not append `/api/arcore_ingest.php` to the app's server field: the app
adds it. Bare hostnames default to HTTPS. For local development, explicitly
enter an HTTP URL only when that server is intentionally reachable over HTTP.
Existing installations retain saved server addresses; update them if needed.
Older builds hardcode HTTP and must be rebuilt for HTTPS deployments.
No phyphox experiment is required.

## Optional second camera

The server's `/marker_tracker.php` page can track a printed marker from a
second stationary camera. This is browser-side functionality and does not
require a new phone protocol. See the root README for setup and limitations.

## Troubleshooting

Check camera permission, ARCore support, lighting, and tracking status first.
For failed sends, verify the base URL, Tailscale connection, access rules,
and TLS certificate. Do not bypass certificate or hostname verification.
ARCore can lose tracking or drift; move slowly in a well-lit textured room.
A successful APK build does not establish physical-device tracking accuracy.

# Phone 3D Viewer

A PHP/MySQL and three.js viewer for live phone rotation and position.
The companion Android app uses ARCore camera/IMU tracking. An optional
second camera tracks an ArUco marker and supplies another pose channel.
Legacy phyphox rotation-only streaming remains available.

## Run locally

Create an untracked `.env` with your own `MYSQL_ROOT_PASSWORD`,
`MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` values. Do not commit
credentials. Then run:

```sh
docker compose up -d --build
```

- Viewer: http://localhost:8080/
- phpMyAdmin: http://localhost:8081/ (local administration only)
- MySQL: internal Docker network only

Application files are copied into the image, so rebuild after code changes.
MySQL data lives in a named volume. Do not use `docker compose down -v`
when updating a deployment. Back up the database before schema updates.

## Private HTTPS deployment

Set `PUBLIC_URL` to your own HTTPS origin, for example
`https://server.example.ts.net:10000`, in private deployment configuration.
The example hostname is a placeholder, not a hosted public demo.

With Tailscale installed and connected on the server, inspect existing
routes and add only this application's route:

```sh
tailscale serve status
tailscale serve --bg --https=10000 http://127.0.0.1:8080
```

Use Serve, not Funnel, to keep access within the tailnet. Do not reset or
replace routes belonging to other services. Authorize only the required
users and TCP port in your tailnet policy. Keep admin services on loopback.
The app does not provide per-user application authentication; authorized
network users can access its viewer and ingestion APIs.

Connect the phone, viewer, and optional webcam device to the tailnet.
Use the address printed by Serve. TLS terminates at Serve; the backend
remains on loopback. For existing HTTP deployments, remove only that
application's old HTTP route before adding HTTPS on the same port.

## Android tracking

See [Android build and usage instructions](android-app/README.md).
Enter the full HTTPS base URL in the app, then start tracking. Open `/`
on the viewing computer. No phyphox experiment is needed for the Android app.
The app sends to `/api/arcore_ingest.php`; the viewer reads
`/api/phyphox_live.php` (the legacy name is retained for both sources).

## Optional webcam/marker tracking

1. Print an ArUco marker using dictionary `ARUCO`, ID `0` by default.
   A [marker generator](https://damianofalcioni.github.io/js-aruco2/samples/marker-creator/marker-creator.html?dictionary=ARUCO)
   is available upstream.
2. Measure the square's edge in millimeters and attach it flat to the phone.
3. Start the Android tracking app.
4. On a second, stationary camera device, open `/marker_tracker.php` using
   the HTTPS origin. Allow camera access, enter the marker size/ID, and start.
5. Keep the marker visible. The tracker automatically pairs with the most
   recently active ARCore recording; it is not a multi-user pairing system.

The viewer displays channel status and can show raw ARCore movement beside
the combined estimate. Camera pose fusion is experimental: calibration,
viewing angle, lighting, marker visibility, and coordinate conventions affect
accuracy. It does not guarantee drift-free motion. Physical camera testing
is required. Browser camera access requires HTTPS (or localhost).

## Legacy phyphox

Open `/get-app.php` at your configured HTTPS origin and import the experiment
into phyphox, or scan its QR code inside phyphox. Press play to stream rotation.
Re-import experiments after changing the server address or protocol.

## Public repository hygiene

Keep `.env`, signing keys, credentials, private hostnames, and machine-specific
paths outside tracked files. Deployment overrides containing real addresses
should be stored privately. Do not publish database backups or recordings.

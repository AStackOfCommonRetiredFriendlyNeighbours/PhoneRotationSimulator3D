<?php
// Generates the phyphox "Motion Stream" experiment on the fly, with the
// network destination (where the phone should POST sensor data — see
// phyphox_ingest.php) filled in from a query param instead of being
// hardcoded into a static file. This lets get-app.php offer a plain text
// field for "send data to <ip>", since some phyphox versions don't expose
// an in-app way to edit that address afterwards.
//
// Only the attitude (rotation) sensor is used -- position tracking via the
// accelerometer was tried and dropped as unreliable, so all the bandwidth
// here goes to rotation: a higher sensor rate and a shorter network
// interval than before, for faster/smoother playback.
$ip = trim($_GET['ip'] ?? '');
if ($ip === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $ip)) {
  $ip = $_SERVER['HTTP_HOST'] ?? '172.20.10.2:8080';
}
// A bare IP with no port would silently default to :80, which nothing is
// listening on here (Apache is only reachable on the mapped port, usually
// 8080) -- that produces a connection that just hangs instead of failing
// fast, so fill in the same port this page itself is being served on.
if (strpos($ip, ':') === false) {
  $hostParts = explode(':', $_SERVER['HTTP_HOST'] ?? '');
  $ip .= ':' . ($hostParts[1] ?? '8080');
}
$address = 'http://' . $ip . '/api/phyphox_ingest.php';

header('Content-Type: application/xml; charset=utf-8');
?>
<phyphox version="1.15" locale="en">
  <title>Motion Stream (Phone 3D Viewer)</title>
  <category>Custom</category>
  <description>Streams this phone's rotation straight to the Phone 3D Viewer server over WiFi, so recording works even without HTTPS. Currently configured to send to <?php echo htmlspecialchars($ip); ?> — set via the IP field on the "Get Motion Stream" page, no in-app editing needed.

Hit the play button below to start streaming, hit stop when you're done.</description>

  <data-containers>
    <container size="200">attX</container>
    <container size="200">attY</container>
    <container size="200">attZ</container>
    <container size="200">attW</container>
    <container size="200">attT</container>
  </data-containers>

  <input>
    <sensor type="attitude" rate="100" ignoreUnavailable="true">
      <output component="x">attX</output>
      <output component="y">attY</output>
      <output component="z">attZ</output>
      <output component="abs">attW</output>
      <output component="t">attT</output>
    </sensor>
  </input>

  <views>
    <view label="Motion Stream">
      <info label="Sends to <?php echo htmlspecialchars($ip); ?>. Hit play below to start streaming, stop when done."/>
      <separator height="1"/>
      <value label="Elapsed time" precision="1" unit="s">
        <input>attT</input>
      </value>
    </view>
  </views>

  <network>
    <connection address="<?php echo htmlspecialchars($address); ?>" service="http/post" conversion="none" interval="0.05">
      <send id="uid" type="meta">uniqueID</send>
      <send id="attT" keep="false">attT</send>
      <send id="attX" keep="false">attX</send>
      <send id="attY" keep="false">attY</send>
      <send id="attZ" keep="false">attZ</send>
      <send id="attW" keep="false">attW</send>
    </connection>
  </network>
</phyphox>

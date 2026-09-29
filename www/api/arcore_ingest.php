<?php
// Receives one HTTP POST per tracked camera frame from the companion
// Android app (android-app/, built with ARCore -- see its own README) and
// stores the phone's full 6DoF pose (position + rotation) into the same
// keyframes table api/phyphox_ingest.php writes rotation-only samples into.
// api/phyphox_live.php then serves whichever source most recently streamed
// to the viewer, tagged with `source` so index.php knows whether to trust
// the position fields.
//
// Unlike phyphox (which batches many samples per request on its own
// interval), the ARCore app sends one small JSON object per camera frame
// (~30-60Hz), fire-and-forget, from a background thread -- see
// PoseSender.kt. This is plain http://, not https://, and that's fine: the
// secure-context restriction that forced this whole project onto phyphox in
// the first place only applies to *browser* JavaScript reading sensors, not
// to a native app's own networking, so android:usesCleartextTraffic="true"
// in the app's manifest is all that's needed on that side.
//
// Session/recording bookkeeping mirrors phyphox_ingest.php: each POST
// carries the app's own persistent uniqueId (a UUID generated once and
// cached in SharedPreferences), and a batch continues the most recently
// active recording for that ID if one arrived within the last few seconds,
// otherwise a new recording starts.

require __DIR__ . '/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON body']);
  exit;
}

foreach (['uniqueId', 't', 'qx', 'qy', 'qz', 'qw', 'px', 'py', 'pz'] as $key) {
  if (!array_key_exists($key, $data)) {
    http_response_code(400);
    echo json_encode(['error' => "missing field: $key"]);
    exit;
  }
}

$uid = substr((string) $data['uniqueId'], 0, 64);
$t = (float) $data['t'];
$qx = (float) $data['qx'];
$qy = (float) $data['qy'];
$qz = (float) $data['qz'];
$qw = (float) $data['qw'];
$px = (float) $data['px'];
$py = (float) $data['py'];
$pz = (float) $data['pz'];

// ARCore's world frame is already right-handed with Y up -- the same
// convention three.js uses for its scene -- so, unlike phyphox's raw
// device-attitude quaternion, no basis-change is needed here. This is
// simply stored as-is; index.php applies it straight to modelGroup.

$conn = get_db_connection();

// Loosened from an earlier 5s: a brief tracking hiccup or network stall
// (the phone's ARCore session itself keeps running the whole time, so its
// world origin hasn't actually changed) shouldn't manufacture a fresh
// recording and snap the viewer's position back to the origin. 12s covers
// a real short interruption; only a genuinely new app launch/session
// should reset things.
const SESSION_GAP_SECONDS = 12.0;

// A real hand holding a phone cannot move faster than this between two
// consecutive frames a fraction of a second apart. When ARCore
// re-localizes (regains confident tracking after a brief loss, or
// occasionally just glitches), the reported position can snap to a
// different value in a single frame -- that's what was showing up as the
// model "jumping to another place for no apparent reason". This rejects
// that one bad sample instead of recording and displaying it.
const MAX_PLAUSIBLE_SPEED_MPS = 8.0;

// If samples keep getting rejected (a sustained burst of bad tracking, e.g.
// during fast motion blur), the gap since the last known-good sample keeps
// growing -- and since the speed check's allowance scales with that gap,
// the allowed distance eventually grows enough to wave through a jump that
// was never actually plausible. Past this many seconds of no accepted
// sample, stop trying to judge plausibility against a now-stale baseline
// and just accept the next one as a fresh reference point instead.
const MAX_GAP_FOR_SPEED_CHECK_SECONDS = 0.5;

$now = microtime(true);
$stmt = $conn->prepare('SELECT * FROM phyphox_sessions WHERE unique_id = ?');
$stmt->bind_param('s', $uid);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

$startingNew = !$session
  || ($now - (float) $session['last_seen_at']) > SESSION_GAP_SECONDS
  || $t < (float) $session['last_t_s'] - 1.0; // app was restarted -> its own clock rewound

if ($startingNew) {
  $conn->query("INSERT INTO recordings (duration_ms, video_filename, source) VALUES (0, NULL, 'arcore')");
  $recordingId = $conn->insert_id;
} else {
  $recordingId = (int) $session['recording_id'];

  $dt = $t - (float) $session['last_t_s'];
  if ($dt > 0 && $dt <= MAX_GAP_FOR_SPEED_CHECK_SECONDS) {
    $dx = $px - (float) $session['last_pos_x'];
    $dy = $py - (float) $session['last_pos_y'];
    $dz = $pz - (float) $session['last_pos_z'];
    $impliedSpeed = sqrt($dx * $dx + $dy * $dy + $dz * $dz) / $dt;

    if ($impliedSpeed > MAX_PLAUSIBLE_SPEED_MPS) {
      // Reject: don't record this pose, but do bump last_seen_at so the
      // session stays "alive" (a burst of bad samples shouldn't itself
      // trigger the new-recording path above) -- last_t_s/last_pos are
      // deliberately left untouched, so the *next* sample is still judged
      // against the last known-good position rather than the bad one.
      $touchStmt = $conn->prepare('UPDATE phyphox_sessions SET last_seen_at = ? WHERE unique_id = ?');
      $touchStmt->bind_param('ds', $now, $uid);
      $touchStmt->execute();
      $touchStmt->close();
      echo json_encode(['ok' => true, 'recording_id' => $recordingId, 'rejected' => 'implausible jump']);
      exit;
    }
  }
}

$conn->begin_transaction();
try {
  $tMs = (int) round($t * 1000);
  $kfStmt = $conn->prepare(
    'INSERT INTO keyframes (recording_id, t_ms, quat_x, quat_y, quat_z, quat_w, pos_x, pos_y, pos_z)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $kfStmt->bind_param('iiddddddd', $recordingId, $tMs, $qx, $qy, $qz, $qw, $px, $py, $pz);
  $kfStmt->execute();
  $kfStmt->close();

  // (recordings.duration_ms is not touched here -- it was for a replay
  // feature that's no longer part of this project, nothing reads it, and
  // updating it was one extra query on every single one of these requests
  // for zero benefit.)

  $sessStmt = $conn->prepare(
    'INSERT INTO phyphox_sessions (unique_id, recording_id, last_t_s, last_seen_at, last_pos_x, last_pos_y, last_pos_z)
     VALUES (?, ?, ?, ?, ?, ?, ?) AS new_vals
     ON DUPLICATE KEY UPDATE
       recording_id = new_vals.recording_id, last_t_s = new_vals.last_t_s, last_seen_at = new_vals.last_seen_at,
       last_pos_x = new_vals.last_pos_x, last_pos_y = new_vals.last_pos_y, last_pos_z = new_vals.last_pos_z'
  );
  $sessStmt->bind_param('siddddd', $uid, $recordingId, $t, $now, $px, $py, $pz);
  $sessStmt->execute();
  $sessStmt->close();

  $conn->commit();

  echo json_encode(['ok' => true, 'recording_id' => $recordingId, 'new_recording' => $startingNew]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['error' => 'Failed to ingest ARCore pose: ' . $e->getMessage()]);
}

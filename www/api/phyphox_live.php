<?php
// Powers the Viewer tab's automatic "live" mode: polled every ~100ms from
// the browser, this reports a batch of whatever new keyframes have landed
// for the most recently active stream (from either source -- phyphox or
// the ARCore app, see phyphox_ingest.php and arcore_ingest.php) since the
// client's last poll (not just the newest one), so the browser can buffer a
// short queue and interpolate between real samples for smooth playback
// instead of snapping every poll. Position fields are only meaningful for
// an 'arcore' source; a 'phyphox' recording's pos_* is just the schema
// default (0,0,0), which index.php knows to ignore via the `source` field.

require __DIR__ . '/db.php';
header('Content-Type: application/json');

const LIVE_WINDOW_SECONDS = 4.0; // no batch this recently -> not "live" anymore
const SEED_KEYFRAMES = 20;       // first poll on a stream: seed with this many recent samples
const MAX_KEYFRAMES_PER_POLL = 400; // safety cap for a client that's fallen behind

$conn = get_db_connection();

$cutoff = microtime(true) - LIVE_WINDOW_SECONDS;
$stmt = $conn->prepare(
  'SELECT s.recording_id, r.source
     FROM phyphox_sessions s
     JOIN recordings r ON r.id = s.recording_id
     WHERE s.last_seen_at > ?
     ORDER BY s.last_seen_at DESC LIMIT 1'
);
$stmt->bind_param('d', $cutoff);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
  echo json_encode(['active' => false]);
  exit;
}

$recordingId = (int) $session['recording_id'];
$source = $session['source'] ?: 'phyphox';

// The client sends back the recording_id + newest t_ms it already has, so
// each poll returns only what's new. A mismatched (or missing) recording_id
// means it's just joining this stream, or the previous one ended — seed it
// with a small tail of recent samples instead of the whole history.
$clientRecordingId = isset($_GET['recording_id']) ? (int) $_GET['recording_id'] : 0;
$isContinuing = $clientRecordingId === $recordingId && isset($_GET['since']);

if ($isContinuing) {
  $since = (int) $_GET['since'];
  $limit = MAX_KEYFRAMES_PER_POLL;
  $kfStmt = $conn->prepare(
    'SELECT t_ms, quat_x, quat_y, quat_z, quat_w, pos_x, pos_y, pos_z
       FROM keyframes WHERE recording_id = ? AND t_ms > ? ORDER BY t_ms ASC LIMIT ?'
  );
  $kfStmt->bind_param('iii', $recordingId, $since, $limit);
  $kfStmt->execute();
  $rows = $kfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $kfStmt->close();
} else {
  $limit = SEED_KEYFRAMES;
  $kfStmt = $conn->prepare(
    'SELECT t_ms, quat_x, quat_y, quat_z, quat_w, pos_x, pos_y, pos_z
       FROM keyframes WHERE recording_id = ? ORDER BY t_ms DESC LIMIT ?'
  );
  $kfStmt->bind_param('ii', $recordingId, $limit);
  $kfStmt->execute();
  $rows = array_reverse($kfStmt->get_result()->fetch_all(MYSQLI_ASSOC));
  $kfStmt->close();
}

if (!$isContinuing && count($rows) === 0) {
  // Session row exists but no keyframe has landed yet (very first batch
  // still in flight) -- report not-yet-active rather than an empty seed.
  echo json_encode(['active' => false]);
  exit;
}

$keyframes = array_map(function ($row) {
  return [
    't' => (int) $row['t_ms'],
    'qx' => (float) $row['quat_x'], 'qy' => (float) $row['quat_y'],
    'qz' => (float) $row['quat_z'], 'qw' => (float) $row['quat_w'],
    'px' => (float) $row['pos_x'], 'py' => (float) $row['pos_y'],
    'pz' => (float) $row['pos_z'],
  ];
}, $rows);

// Second, independent pose channel for the same recording -- a webcam
// watching an ArUco marker on the phone (see marker_tracker.php and
// external_pose_ingest.php) instead of the phone's own sensors. Only ever
// meaningful for an 'arcore' recording (there's no marker-based position
// concept for phyphox's rotation-only stream), and only a handful of recent
// rows: this channel runs at webcam frame rate (~10-15Hz), far slower than
// the main keyframes, so there's no equivalent need for a client-tracked
// cursor here -- just hand over whatever landed in the last couple of
// seconds each poll, which index.php folds into its drift-correction filter
// using the samples' own timestamps, not poll timing.
$external = [];
if ($source === 'arcore') {
  $externalWindowSeconds = 2.0; // not a top-level const: this is inside an if-block, where PHP's `const` isn't allowed
  $extCutoff = microtime(true) - $externalWindowSeconds;
  $extStmt = $conn->prepare(
    'SELECT t_ms, quat_x, quat_y, quat_z, quat_w, pos_x, pos_y, pos_z
       FROM external_keyframes
       WHERE recording_id = ? AND received_at > ?
       ORDER BY t_ms ASC LIMIT 100'
  );
  $extStmt->bind_param('id', $recordingId, $extCutoff);
  $extStmt->execute();
  $extRows = $extStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $extStmt->close();
  $external = array_map(function ($row) {
    return [
      't' => (int) $row['t_ms'],
      'qx' => (float) $row['quat_x'], 'qy' => (float) $row['quat_y'],
      'qz' => (float) $row['quat_z'], 'qw' => (float) $row['quat_w'],
      'px' => (float) $row['pos_x'], 'py' => (float) $row['pos_y'],
      'pz' => (float) $row['pos_z'],
    ];
  }, $extRows);
}

echo json_encode([
  'active' => true,
  'recording_id' => $recordingId,
  'source' => $source,
  'keyframes' => $keyframes,
  'external' => $external,
]);

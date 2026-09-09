<?php
// Powers the Viewer tab's automatic "live from phyphox" mode: polled every
// ~100ms from the browser, this reports a batch of whatever new keyframes
// have landed for the most recently active phyphox stream since the
// client's last poll (not just the newest one), so the browser can buffer a
// short queue and interpolate between real samples for smooth playback
// instead of snapping every poll. (See phyphox_ingest.php for how the pose
// data itself is derived and stored.)

require __DIR__ . '/db.php';
header('Content-Type: application/json');

const LIVE_WINDOW_SECONDS = 4.0; // no batch this recently -> not "live" anymore
const SEED_KEYFRAMES = 20;       // first poll on a stream: seed with this many recent samples
const MAX_KEYFRAMES_PER_POLL = 400; // safety cap for a client that's fallen behind

$conn = get_db_connection();

$cutoff = microtime(true) - LIVE_WINDOW_SECONDS;
$stmt = $conn->prepare(
  'SELECT recording_id FROM phyphox_sessions WHERE last_seen_at > ? ORDER BY last_seen_at DESC LIMIT 1'
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
    'SELECT t_ms, quat_x, quat_y, quat_z, quat_w
       FROM keyframes WHERE recording_id = ? AND t_ms > ? ORDER BY t_ms ASC LIMIT ?'
  );
  $kfStmt->bind_param('iii', $recordingId, $since, $limit);
  $kfStmt->execute();
  $rows = $kfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $kfStmt->close();
} else {
  $limit = SEED_KEYFRAMES;
  $kfStmt = $conn->prepare(
    'SELECT t_ms, quat_x, quat_y, quat_z, quat_w
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
  ];
}, $rows);

echo json_encode([
  'active' => true,
  'recording_id' => $recordingId,
  'keyframes' => $keyframes,
]);

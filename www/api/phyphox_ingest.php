<?php
// Receives periodic HTTP POST batches from the "Motion Stream" phyphox
// experiment (see api/motion_stream.php) and stores the phone's rotation
// into the keyframes table, which api/phyphox_live.php then serves to the
// viewer for live playback. Position/motion tracking (accelerometer-based
// dead reckoning) was tried and removed -- accelerometer-only position
// estimation is fundamentally unreliable (no GPS/visual reference to
// correct against), so this now tracks rotation only, which phyphox's
// attitude sensor reports directly and accurately.
//
// This exists as a workaround for browsers refusing motion/camera access
// on a plain http:// LAN address (see README "Known limitations"): phyphox
// is a native app, so it isn't subject to that secure-context restriction.
//
// Phyphox has no explicit "start recording" / "stop recording" signal for
// http/post — it just streams small buffered batches every `interval`
// seconds while its own play button is running, and stays silent while
// stopped. So a "recording" is inferred here: a batch continues the most
// recently active recording for the same phone (matched by phyphox's own
// uniqueID metadata) if a batch from it arrived recently, otherwise a new
// recording is opened. That continuation state is carried across batches
// in the phyphox_sessions table since each HTTP request is otherwise
// stateless.

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

function arr(&$data, $key) {
  return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
}

$uid = isset($data['uid']) && $data['uid'] !== '' ? substr((string) $data['uid'], 0, 64) : 'unknown-device';

$attT = arr($data, 'attT');
$attX = arr($data, 'attX');
$attY = arr($data, 'attY');
$attZ = arr($data, 'attZ');
$attW = arr($data, 'attW');

$n = count($attT);
if ($n === 0 || count($attX) !== $n || count($attY) !== $n || count($attZ) !== $n || count($attW) !== $n) {
  // Nothing new (phyphox is idle, or between play presses) — not an error.
  echo json_encode(['ok' => true, 'skipped' => 'no attitude samples']);
  exit;
}

// phyphox's "attitude" quaternion maps device-local vectors into an
// Android-style world frame (x=East, y=North, z=Up): v_world = q * v_device
// * q^-1. three.js's scene is Y-up instead of Z-up, so the same device
// orientation needs re-expressing in a world frame where "up" is Y — that's
// a fixed change of basis, C = Rx(-90deg), applied by CONJUGATION-composing
// it as the outer (left-hand) factor: q_threejs = C * q_device. (Composing
// it on the other side doesn't just offset the result by a constant — it
// silently swaps which physical axis "up" tracks as the phone tilts.)
const FRAME_CORRECTION = [-0.70710678, 0.0, 0.0, 0.70710678]; // [x, y, z, w], Rx(-90deg)

function quat_multiply($a, $b) {
  // a, b, result: [x, y, z, w]
  list($ax, $ay, $az, $aw) = $a;
  list($bx, $by, $bz, $bw) = $b;
  return [
    $aw * $bx + $ax * $bw + $ay * $bz - $az * $by,
    $aw * $by - $ax * $bz + $ay * $bw + $az * $bx,
    $aw * $bz + $ax * $by - $ay * $bx + $az * $bw,
    $aw * $bw - $ax * $bx - $ay * $by - $az * $bz,
  ];
}

$conn = get_db_connection();

const SESSION_GAP_SECONDS = 5.0; // no batch for this long -> treat the next one as a new recording

$now = microtime(true);
$stmt = $conn->prepare('SELECT * FROM phyphox_sessions WHERE unique_id = ?');
$stmt->bind_param('s', $uid);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

$firstSampleT = (float) $attT[0];
$startingNew = !$session
  || ($now - (float) $session['last_seen_at']) > SESSION_GAP_SECONDS
  || $firstSampleT < (float) $session['last_t_s'] - 1.0; // phyphox's own clock rewound -> it was restarted

if ($startingNew) {
  $conn->query('INSERT INTO recordings (duration_ms, video_filename) VALUES (0, NULL)');
  $recordingId = $conn->insert_id;
  $lastT = 0.0;
} else {
  $recordingId = (int) $session['recording_id'];
  $lastT = (float) $session['last_t_s'];
}

$conn->begin_transaction();
try {
  $kfStmt = $conn->prepare(
    'INSERT INTO keyframes (recording_id, t_ms, quat_x, quat_y, quat_z, quat_w) VALUES (?, ?, ?, ?, ?, ?)'
  );

  $maxT = $lastT;

  for ($i = 0; $i < $n; $i++) {
    $t = (float) $attT[$i];
    $maxT = max($maxT, $t);

    $raw = [(float) $attX[$i], (float) $attY[$i], (float) $attZ[$i], (float) $attW[$i]];
    $q = quat_multiply(FRAME_CORRECTION, $raw);

    $tMs = (int) round($t * 1000);
    $kfStmt->bind_param('iidddd', $recordingId, $tMs, $q[0], $q[1], $q[2], $q[3]);
    $kfStmt->execute();
  }
  $kfStmt->close();
  $lastT = $maxT;

  $durationMs = (int) round($maxT * 1000);
  $updStmt = $conn->prepare('UPDATE recordings SET duration_ms = ? WHERE id = ? AND duration_ms < ?');
  $updStmt->bind_param('iii', $durationMs, $recordingId, $durationMs);
  $updStmt->execute();
  $updStmt->close();

  $sessStmt = $conn->prepare(
    'INSERT INTO phyphox_sessions (unique_id, recording_id, last_t_s, last_seen_at)
     VALUES (?, ?, ?, ?) AS new_vals
     ON DUPLICATE KEY UPDATE
       recording_id = new_vals.recording_id, last_t_s = new_vals.last_t_s, last_seen_at = new_vals.last_seen_at'
  );
  $sessStmt->bind_param('sidd', $uid, $recordingId, $lastT, $now);
  $sessStmt->execute();
  $sessStmt->close();

  $conn->commit();

  echo json_encode(['ok' => true, 'recording_id' => $recordingId, 'new_recording' => $startingNew, 'samples' => $n]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['error' => 'Failed to ingest phyphox data: ' . $e->getMessage()]);
}

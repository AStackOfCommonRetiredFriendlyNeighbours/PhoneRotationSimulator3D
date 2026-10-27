<?php
// Receives one marker-tracker reading per processed webcam frame (see
// marker_tracker.php) and stores it tagged to the same recording_id the
// ARCore phone is already writing its own keyframes into. This is a SECOND,
// independent observation of the same physical phone's pose -- taken from
// a fixed external camera via an ArUco marker + POSIT, not from the phone's
// own sensors -- which index.php fuses with the phone's own stream for
// drift-free position (see its comments on frame alignment).
//
// Deliberately permissive about *when* these can land: unlike
// phyphox_ingest.php/arcore_ingest.php, there's no "session" bookkeeping
// here, because this channel doesn't define a recording on its own -- it
// only ever attaches to one that the phone's own stream already created
// (found via current_recording.php). If that recording has since ended or
// never existed, the insert is just silently skipped rather than treated as
// an error -- a slightly stale pairing on the webcam page shouldn't make it
// spam errors into Apache's log every frame.

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

$required = ['recording_id', 't', 'qx', 'qy', 'qz', 'qw', 'px', 'py', 'pz'];
foreach ($required as $key) {
  if (!isset($data[$key]) || !is_numeric($data[$key])) {
    http_response_code(400);
    echo json_encode(['error' => "Missing or non-numeric field: $key"]);
    exit;
  }
}

$recordingId = (int) $data['recording_id'];
$tMs = (int) round((float) $data['t']);
$qx = (float) $data['qx']; $qy = (float) $data['qy'];
$qz = (float) $data['qz']; $qw = (float) $data['qw'];
$px = (float) $data['px']; $py = (float) $data['py']; $pz = (float) $data['pz'];

$conn = get_db_connection();

// Confirm the recording still exists rather than trusting a possibly-stale
// pairing -- a foreign key would reject the insert anyway, but this avoids
// throwing a 500 for what's really just "pairing went stale, try again".
$stmt = $conn->prepare('SELECT 1 FROM recordings WHERE id = ?');
$stmt->bind_param('i', $recordingId);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$exists) {
  echo json_encode(['ok' => true, 'skipped' => 'recording no longer active']);
  exit;
}

$now = microtime(true);
$stmt = $conn->prepare(
  'INSERT INTO external_keyframes (recording_id, t_ms, quat_x, quat_y, quat_z, quat_w, pos_x, pos_y, pos_z, received_at)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iidddddddd', $recordingId, $tMs, $qx, $qy, $qz, $qw, $px, $py, $pz, $now);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);

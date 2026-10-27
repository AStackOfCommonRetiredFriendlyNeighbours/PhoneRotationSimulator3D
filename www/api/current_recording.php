<?php
// Tells a second device (marker_tracker.php's webcam page) which recording
// it should be attaching its external pose readings to, without the phone's
// own ARCore app needing to know anything about this at all -- no pairing
// code, no app change. It just reports whichever 'arcore' recording is
// currently active, same "most recently active session" rule phyphox_live.php
// already uses for the main viewer.

require __DIR__ . '/db.php';
header('Content-Type: application/json');

const LIVE_WINDOW_SECONDS = 4.0;

$conn = get_db_connection();

$cutoff = microtime(true) - LIVE_WINDOW_SECONDS;
$stmt = $conn->prepare(
  "SELECT s.recording_id
     FROM phyphox_sessions s
     JOIN recordings r ON r.id = s.recording_id
     WHERE s.last_seen_at > ? AND r.source = 'arcore'
     ORDER BY s.last_seen_at DESC LIMIT 1"
);
$stmt->bind_param('d', $cutoff);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  echo json_encode(['active' => false]);
  exit;
}

echo json_encode(['active' => true, 'recording_id' => (int) $row['recording_id']]);

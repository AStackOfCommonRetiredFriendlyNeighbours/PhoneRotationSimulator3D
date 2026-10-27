<?php
// Shared DB connection + lazy schema creation. Included by every endpoint
// in this folder — nothing to run manually, the tables are created on
// first use if they don't exist yet.

function get_db_connection() {
  static $conn = null;
  if ($conn !== null) {
    return $conn;
  }

  $host     = getenv('MYSQL_HOST') ?: 'mysql';
  $user     = getenv('MYSQL_USER') ?: 'meinuser';
  $password = getenv('MYSQL_PASSWORD') ?: 'meinpasswort';
  $database = getenv('MYSQL_DATABASE') ?: 'meine_db';

  // "p:" -- a persistent connection: PHP/Apache keeps it open and hands it
  // back out to the next request handled by the same worker process,
  // instead of paying a fresh TCP+MySQL-auth handshake on literally every
  // single request. This matters a lot here specifically: the ARCore app
  // POSTs a new pose ~30-60 times a SECOND, each one otherwise opening (and
  // tearing down) its own brand-new connection.
  $host = 'p:' . $host;

  $lastError = null;
  for ($attempt = 0; $attempt < 10; $attempt++) {
    $mysqli = @new mysqli($host, $user, $password, $database);
    if (!$mysqli->connect_error) {
      $conn = $mysqli;
      break;
    }
    $lastError = $mysqli->connect_error;
    sleep(1); // MySQL can still be starting up right after `docker compose up`
  }

  if ($conn === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database unavailable: ' . $lastError]);
    exit;
  }

  ensure_schema_once($conn);
  return $conn;
}

function ensure_schema_once($conn) {
  // ensure_schema() below does several CREATE TABLE / information_schema
  // lookups -- fine to pay once, wasteful to pay on every single request at
  // up to ~60Hz. mod_php has no natural cross-request memory (a "static"
  // local doesn't survive between requests the way it would in a
  // long-running process), so this uses a plain marker file in the
  // container's own /tmp as a cheap "already checked this container
  // lifetime" flag -- a single file_exists() stat instead of ~10 SQL
  // statements. It naturally re-runs once after any container
  // restart/rebuild, which is exactly when it's actually needed again.
  $marker = sys_get_temp_dir() . '/phone3d_schema_ensured';
  if (file_exists($marker)) {
    return;
  }
  ensure_schema($conn);
  @file_put_contents($marker, '1');
}

function ensure_schema($conn) {
  $conn->query("
    CREATE TABLE IF NOT EXISTS recordings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      duration_ms INT NOT NULL DEFAULT 0,
      video_filename VARCHAR(255) NULL,
      source VARCHAR(16) NULL
    )
  ");

  // One row per streamed pose sample. quat_* comes from every source;
  // pos_* is only real data when it comes from the ARCore app (see
  // api/arcore_ingest.php) -- phyphox has no camera, so its rows just leave
  // pos_* at the default (0,0,0), which is fine since the viewer only
  // applies position for a recording whose source is 'arcore' (see
  // index.php).
  $conn->query("
    CREATE TABLE IF NOT EXISTS keyframes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      recording_id INT NOT NULL,
      t_ms INT NOT NULL,
      quat_x DOUBLE NOT NULL,
      quat_y DOUBLE NOT NULL,
      quat_z DOUBLE NOT NULL,
      quat_w DOUBLE NOT NULL,
      pos_x DOUBLE NOT NULL DEFAULT 0,
      pos_y DOUBLE NOT NULL DEFAULT 0,
      pos_z DOUBLE NOT NULL DEFAULT 0,
      INDEX (recording_id),
      CONSTRAINT fk_keyframes_recording FOREIGN KEY (recording_id)
        REFERENCES recordings(id) ON DELETE CASCADE
    )
  ");

  // Tracks which recording each streaming phone (matched by a per-source
  // unique ID -- phyphox's own uniqueID, or a UUID the ARCore app generates
  // and keeps in SharedPreferences) is currently appending to, since each
  // HTTP request is otherwise stateless. Shared by phyphox_ingest.php and
  // arcore_ingest.php.
  $conn->query("
    CREATE TABLE IF NOT EXISTS phyphox_sessions (
      unique_id VARCHAR(64) PRIMARY KEY,
      recording_id INT NOT NULL,
      last_t_s DOUBLE NOT NULL DEFAULT 0,
      last_seen_at DOUBLE NOT NULL DEFAULT 0,
      CONSTRAINT fk_phyphox_sessions_recording FOREIGN KEY (recording_id)
        REFERENCES recordings(id) ON DELETE CASCADE
    )
  ");

  // Schema evolution for deployments created by earlier versions of this
  // project. MySQL has neither "ADD COLUMN IF NOT EXISTS" nor "DROP COLUMN
  // IF EXISTS" (that's a MariaDB/Postgres thing -- confirmed against
  // MySQL's own ALTER TABLE grammar, which doesn't list either), so
  // existence has to be checked by hand first every time. This matters
  // beyond tidiness: keyframes.pos_x/y/z used to be NOT NULL with no
  // default, so as long as they lingered unexpectedly, every insert that
  // didn't supply them was rejected outright by strict mode -- i.e.
  // nothing was being recorded at all. DEFAULT 0 this time around
  // specifically avoids repeating that.
  add_column_if_not_exists($conn, 'keyframes', 'pos_x', 'DOUBLE NOT NULL DEFAULT 0');
  add_column_if_not_exists($conn, 'keyframes', 'pos_y', 'DOUBLE NOT NULL DEFAULT 0');
  add_column_if_not_exists($conn, 'keyframes', 'pos_z', 'DOUBLE NOT NULL DEFAULT 0');
  add_column_if_not_exists($conn, 'recordings', 'source', 'VARCHAR(16) NULL');
  // Last-accepted position per session, used by arcore_ingest.php to reject
  // implausible single-frame jumps (ARCore relocalization glitches) instead
  // of letting them make the model visibly teleport. Unused/left at the
  // default by phyphox sessions, which never send a position.
  add_column_if_not_exists($conn, 'phyphox_sessions', 'last_pos_x', 'DOUBLE NOT NULL DEFAULT 0');
  add_column_if_not_exists($conn, 'phyphox_sessions', 'last_pos_y', 'DOUBLE NOT NULL DEFAULT 0');
  add_column_if_not_exists($conn, 'phyphox_sessions', 'last_pos_z', 'DOUBLE NOT NULL DEFAULT 0');

  foreach (['vel_x', 'vel_y', 'vel_z', 'grav_x', 'grav_y', 'grav_z',
            'grav_ready', 'quiet_s'] as $col) {
    drop_column_if_exists($conn, 'phyphox_sessions', $col);
  }

  // A second, independent pose channel for the SAME recording -- not a
  // separate source like phyphox/arcore, but an outside-in observer (a
  // laptop/second-phone webcam watching an ArUco marker taped to the
  // tracked phone, see marker_tracker.php) reporting what it sees of that
  // same physical phone, in its own camera-centered coordinate frame. The
  // viewer (index.php) is what reconciles the two frames and fuses them --
  // this table just needs to let one recording have many of these landing
  // concurrently with its regular keyframes.
  $conn->query("
    CREATE TABLE IF NOT EXISTS external_keyframes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      recording_id INT NOT NULL,
      t_ms INT NOT NULL,
      quat_x DOUBLE NOT NULL,
      quat_y DOUBLE NOT NULL,
      quat_z DOUBLE NOT NULL,
      quat_w DOUBLE NOT NULL,
      pos_x DOUBLE NOT NULL,
      pos_y DOUBLE NOT NULL,
      pos_z DOUBLE NOT NULL,
      received_at DOUBLE NOT NULL,
      INDEX (recording_id, t_ms),
      CONSTRAINT fk_external_keyframes_recording FOREIGN KEY (recording_id)
        REFERENCES recordings(id) ON DELETE CASCADE
    )
  ");
}

function add_column_if_not_exists($conn, $table, $column, $definition) {
  $stmt = $conn->prepare(
    'SELECT 1 FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
  );
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $exists = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  if (!$exists) {
    $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
  }
}

function drop_column_if_exists($conn, $table, $column) {
  $stmt = $conn->prepare(
    'SELECT 1 FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
  );
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $exists = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  if ($exists) {
    $conn->query("ALTER TABLE `$table` DROP COLUMN `$column`");
  }
}

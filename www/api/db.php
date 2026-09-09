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

  ensure_schema($conn);
  return $conn;
}

function ensure_schema($conn) {
  $conn->query("
    CREATE TABLE IF NOT EXISTS recordings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      duration_ms INT NOT NULL DEFAULT 0,
      video_filename VARCHAR(255) NULL
    )
  ");

  // One row per streamed rotation sample -- see phyphox_ingest.php.
  $conn->query("
    CREATE TABLE IF NOT EXISTS keyframes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      recording_id INT NOT NULL,
      t_ms INT NOT NULL,
      quat_x DOUBLE NOT NULL,
      quat_y DOUBLE NOT NULL,
      quat_z DOUBLE NOT NULL,
      quat_w DOUBLE NOT NULL,
      INDEX (recording_id),
      CONSTRAINT fk_keyframes_recording FOREIGN KEY (recording_id)
        REFERENCES recordings(id) ON DELETE CASCADE
    )
  ");

  // Tracks which recording each streaming phone (by phyphox's own uniqueID)
  // is currently appending to, since each HTTP request is otherwise
  // stateless. Used only by phyphox_ingest.php.
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

  // Position tracking (accelerometer dead-reckoning) was removed -- only
  // rotation is tracked now. Older deployments may still have these
  // columns from before; drop them so the schema matches what the code
  // actually uses. MySQL has no "DROP COLUMN IF EXISTS" (that's a
  // MariaDB/Postgres thing -- confirmed against MySQL's own ALTER TABLE
  // grammar, which doesn't list it), so existence has to be checked by
  // hand first. This matters beyond tidiness: keyframes.pos_x/y/z were
  // NOT NULL with no default, so as long as they lingered, every insert
  // that didn't supply them was rejected outright by strict mode -- i.e.
  // nothing was being recorded at all.
  drop_column_if_exists($conn, 'keyframes', 'pos_x');
  drop_column_if_exists($conn, 'keyframes', 'pos_y');
  drop_column_if_exists($conn, 'keyframes', 'pos_z');
  foreach (['vel_x', 'vel_y', 'vel_z', 'pos_x', 'pos_y', 'pos_z',
            'grav_x', 'grav_y', 'grav_z', 'grav_ready', 'quiet_s'] as $col) {
    drop_column_if_exists($conn, 'phyphox_sessions', $col);
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

<?php
// Outside-in second tracking channel: this page runs on a laptop (or a
// second phone) with a WEBCAM, not on the tracked phone itself. It watches
// an ArUco marker taped to the back of the tracked phone and reports the
// marker's pose -- straight to api/external_pose_ingest.php -- which
// index.php then fuses with the phone's own ARCore stream (see its
// comments on frame alignment) for continuous, drift-free position instead
// of relying on ARCore's own self-tracking alone.
//
// Deliberately doesn't need any change to the Android app or any pairing
// code typed on either side: it just asks api/current_recording.php which
// 'arcore' recording is active right now and attaches to that. See
// android-app/README.md / top-level README.md "Second tracking channel"
// for the physical setup (marker size, printing, taping).
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Marker Tracker — Phone 3D Viewer</title>
<style>
  :root { color-scheme: dark; }
  body {
    margin: 0; background: #111; color: #eee;
    font-family: system-ui, -apple-system, sans-serif;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
    padding: 16px 16px 40px;
  }
  h1 { font-size: 18px; margin: 4px 0; }
  p.sub { color: #999; font-size: 13px; max-width: 480px; text-align: center; margin: 0 0 8px; }
  .stage { position: relative; width: 100%; max-width: 640px; }
  video, canvas { width: 100%; display: block; border-radius: 8px; background: #000; }
  canvas.overlay {
    position: absolute; top: 0; left: 0; pointer-events: none;
  }
  .panel {
    width: 100%; max-width: 640px; background: #1b1b1b; border-radius: 8px;
    padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;
  }
  .row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  label { font-size: 13px; color: #ccc; }
  input[type="number"] {
    width: 90px; background: #2a2a2a; color: #eee; border: 1px solid #444;
    border-radius: 4px; padding: 4px 6px;
  }
  button {
    background: #3a7; color: #fff; border: none; border-radius: 6px;
    padding: 10px 16px; font-size: 14px; cursor: pointer;
  }
  button:disabled { background: #444; color: #888; cursor: default; }
  button.stop { background: #b44; }
  #status { font-size: 13px; color: #aaa; white-space: pre-wrap; }
  .flips { display: flex; gap: 14px; font-size: 12px; color: #bbb; }
  .flips label { display: flex; align-items: center; gap: 4px; }
</style>
</head>
<body>

<h1>Marker Tracker (external camera)</h1>
<p class="sub">
  Point this device's camera at the ArUco marker taped to the tracked
  phone. It reports that marker's position back to the server, which the
  3D viewer fuses with the phone's own ARCore tracking for a continuous,
  drift-free position estimate. Keep this device still once you start.
</p>

<div class="stage">
  <video id="video" autoplay playsinline muted></video>
  <canvas id="overlay" class="overlay"></canvas>
</div>
<canvas id="process" style="display:none;"></canvas>

<div class="panel">
  <div class="row">
    <label for="markerSize">Marker size (mm, printed edge length)</label>
    <input id="markerSize" type="number" value="70" min="10" step="1">
  </div>
  <div class="row">
    <label for="markerId">Marker ID (ARUCO dictionary)</label>
    <input id="markerId" type="number" value="0" min="0" step="1">
  </div>
  <div class="flips">
    <label><input type="checkbox" id="flipX"> flip X</label>
    <label><input type="checkbox" id="flipY"> flip Y</label>
    <label><input type="checkbox" id="flipZ"> flip Z</label>
  </div>
  <div class="row">
    <button id="startBtn">Start</button>
    <span id="pairing">Not paired yet</span>
  </div>
  <div id="status">Idle.</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/js-aruco2@2.0.0/src/cv.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-aruco2@2.0.0/src/aruco.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-aruco2@2.0.0/src/svd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-aruco2@2.0.0/src/posit1.js"></script>
<script>
// --- Elements ---
const video = document.getElementById('video');
const overlay = document.getElementById('overlay');
const overlayCtx = overlay.getContext('2d');
const processCanvas = document.getElementById('process');
const processCtx = processCanvas.getContext('2d');
const startBtn = document.getElementById('startBtn');
const statusEl = document.getElementById('status');
const pairingEl = document.getElementById('pairing');
const markerSizeEl = document.getElementById('markerSize');
const markerIdEl = document.getElementById('markerId');
const flipXEl = document.getElementById('flipX');
const flipYEl = document.getElementById('flipY');
const flipZEl = document.getElementById('flipZ');

let running = false;
let detector = null;
let posit = null;
let recordingId = null;
let pairingTimer = null;
let frameTimer = null;
let sentCount = 0, failedCount = 0, noMarkerCount = 0;

// --- Pairing: find the ARCore recording currently active, no app change
// needed -- see api/current_recording.php. Polled continuously (not just
// once) so this page can pick up a NEW recording automatically if the
// phone's stream restarts while this page is left running. ---
async function pollPairing() {
  try {
    const res = await fetch('api/current_recording.php');
    const d = await res.json();
    if (d.active) {
      if (recordingId !== d.recording_id) {
        recordingId = d.recording_id;
        pairingEl.textContent = `Paired with recording #${recordingId}`;
      }
    } else {
      recordingId = null;
      pairingEl.textContent = 'Waiting for the ARCore app to start streaming…';
    }
  } catch (err) {
    pairingEl.textContent = 'Pairing check failed (network?)';
  }
}

// --- Rotation matrix (POSIT's 3x3, row-major) -> quaternion. Standard
// matrix-to-quaternion conversion (Shepperd's method / the common
// branch-on-largest-diagonal form) -- POSIT's R is a proper rotation
// matrix, so this applies directly. ---
function matrixToQuaternion(m) {
  // m is [[m00,m01,m02],[m10,m11,m12],[m20,m21,m22]]
  const m00 = m[0][0], m01 = m[0][1], m02 = m[0][2];
  const m10 = m[1][0], m11 = m[1][1], m12 = m[1][2];
  const m20 = m[2][0], m21 = m[2][1], m22 = m[2][2];
  const trace = m00 + m11 + m22;
  let qw, qx, qy, qz;
  if (trace > 0) {
    const s = Math.sqrt(trace + 1.0) * 2;
    qw = 0.25 * s;
    qx = (m21 - m12) / s;
    qy = (m02 - m20) / s;
    qz = (m10 - m01) / s;
  } else if (m00 > m11 && m00 > m22) {
    const s = Math.sqrt(1.0 + m00 - m11 - m22) * 2;
    qw = (m21 - m12) / s;
    qx = 0.25 * s;
    qy = (m01 + m10) / s;
    qz = (m02 + m20) / s;
  } else if (m11 > m22) {
    const s = Math.sqrt(1.0 + m11 - m00 - m22) * 2;
    qw = (m02 - m20) / s;
    qx = (m01 + m10) / s;
    qy = 0.25 * s;
    qz = (m12 + m21) / s;
  } else {
    const s = Math.sqrt(1.0 + m22 - m00 - m11) * 2;
    qw = (m10 - m01) / s;
    qx = (m02 + m20) / s;
    qy = (m12 + m21) / s;
    qz = 0.25 * s;
  }
  return [qx, qy, qz, qw];
}

async function startCamera() {
  const stream = await navigator.mediaDevices.getUserMedia({
    video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } },
    audio: false,
  });
  video.srcObject = stream;
  await new Promise((resolve) => { video.onloadedmetadata = resolve; });
  const w = video.videoWidth, h = video.videoHeight;
  overlay.width = w; overlay.height = h;
  processCanvas.width = w; processCanvas.height = h;
  detector = new AR.Detector({ dictionaryName: 'ARUCO' });
  posit = new POS.Posit(Number(markerSizeEl.value), w);
}

function processFrame() {
  if (!running) return;
  const w = processCanvas.width, h = processCanvas.height;
  processCtx.drawImage(video, 0, 0, w, h);
  const imageData = processCtx.getImageData(0, 0, w, h);

  overlayCtx.clearRect(0, 0, w, h);

  const markers = detector.detect(imageData);
  const wantedId = Number(markerIdEl.value);
  const marker = markers.find((m) => m.id === wantedId) || markers[0];

  if (marker) {
    overlayCtx.strokeStyle = '#3f7';
    overlayCtx.lineWidth = 3;
    overlayCtx.beginPath();
    marker.corners.forEach((c, i) => {
      if (i === 0) overlayCtx.moveTo(c.x, c.y); else overlayCtx.lineTo(c.x, c.y);
    });
    overlayCtx.closePath();
    overlayCtx.stroke();

    // POSIT needs corners centered on the canvas, with Y flipped (image Y
    // grows downward; POSIT/OpenCV convention here expects it growing up).
    const corners = marker.corners.map((c) => ({
      x: c.x - w / 2,
      y: h / 2 - c.y,
    }));
    const pose = posit.pose(corners);
    if (pose) {
      // POSIT returns two candidate poses (a known ambiguity for a single
      // planar square marker viewed near head-on) -- bestError is POSIT's
      // own ranking of which is more self-consistent; good enough here
      // since the frame-alignment step in index.php only needs this to be
      // stable moment-to-moment, not perfectly disambiguated every frame.
      const t = pose.bestTranslation; // [x, y, z] in mm
      const R = pose.bestRotation;    // 3x3

      let px = t[0] / 1000, py = t[1] / 1000, pz = t[2] / 1000; // -> meters
      if (flipXEl.checked) px = -px;
      if (flipYEl.checked) py = -py;
      if (flipZEl.checked) pz = -pz;

      const [qx, qy, qz, qw] = matrixToQuaternion(R);

      sendPose(px, py, pz, qx, qy, qz, qw);
      statusEl.textContent =
        `Marker seen. pos=(${px.toFixed(3)}, ${py.toFixed(3)}, ${pz.toFixed(3)}) m\n` +
        `sent=${sentCount} failed=${failedCount} no-marker=${noMarkerCount}`;
    }
  } else {
    noMarkerCount++;
    statusEl.textContent =
      `No marker in view. sent=${sentCount} failed=${failedCount} no-marker=${noMarkerCount}`;
  }
}

async function sendPose(px, py, pz, qx, qy, qz, qw) {
  if (recordingId === null) return; // nothing to attach this reading to yet
  try {
    const res = await fetch('api/external_pose_ingest.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        recording_id: recordingId,
        t: performance.now(),
        qx, qy, qz, qw, px, py, pz,
      }),
    });
    if (res.ok) sentCount++; else failedCount++;
  } catch (err) {
    failedCount++;
  }
}

startBtn.addEventListener('click', async () => {
  if (running) {
    running = false;
    clearInterval(frameTimer);
    clearInterval(pairingTimer);
    startBtn.textContent = 'Start';
    startBtn.classList.remove('stop');
    statusEl.textContent = 'Stopped.';
    return;
  }
  startBtn.disabled = true;
  try {
    await startCamera();
    running = true;
    startBtn.textContent = 'Stop';
    startBtn.classList.add('stop');
    statusEl.textContent = 'Running.';
    pollPairing();
    pairingTimer = setInterval(pollPairing, 2000);
    // ~12Hz: plenty for a slow drift-correction channel, light on both this
    // device's CPU and the server -- the fast motion detail still comes
    // from the phone's own ARCore stream.
    frameTimer = setInterval(processFrame, 80);
  } catch (err) {
    statusEl.textContent = 'Camera access failed: ' + (err.message || err);
  } finally {
    startBtn.disabled = false;
  }
});
</script>
</body>
</html>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phone 3D Viewer</title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: #0a0a0f; }
  body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; color: #cfe9f2; }
  canvas { display: block; }

  #app { position: fixed; inset: 0; }

  #loading {
    position: fixed; inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; color: #0ff;
    background: #0a0a0f;
    transition: opacity 0.4s ease;
    z-index: 10;
  }
  #loading.hidden { opacity: 0; pointer-events: none; }

  #switcher {
    position: fixed;
    top: 20px;
    left: 20px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px;
    background: rgba(10, 10, 15, 0.65);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(0, 255, 255, 0.35);
    border-radius: 12px;
    box-shadow: 0 0 20px rgba(0, 255, 255, 0.15);
    z-index: 5;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.4s ease;
    max-width: 200px;
  }
  #switcher.visible { opacity: 1; pointer-events: auto; }
  #switcher button {
    appearance: none;
    border: none;
    background: transparent;
    color: #0ff;
    font-size: 13px;
    font-weight: 500;
    text-align: left;
    padding: 8px 12px;
    border-radius: 8px;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.2s ease, color 0.2s ease;
  }
  #switcher button:hover { background: rgba(0, 255, 255, 0.12); }
  #switcher button.active { background: #0ff; color: #000; }

  #controls {
    position: fixed;
    bottom: 26px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 10px;
    z-index: 5;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.4s ease;
  }
  #controls.visible { opacity: 1; pointer-events: auto; }
  #controls button {
    padding: 10px 18px;
    border: 1px solid rgba(0, 255, 255, 0.35);
    border-radius: 999px;
    background: rgba(10, 10, 15, 0.65);
    backdrop-filter: blur(8px);
    color: #0ff;
    font-size: 13px;
    font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
  }
  #controls button:hover { background: rgba(0, 255, 255, 0.12); }
  #controls button.off {
    color: #ff6b6b;
    border-color: rgba(255, 107, 107, 0.4);
  }

  .slider-control {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border: 1px solid rgba(0, 255, 255, 0.35);
    border-radius: 999px;
    background: rgba(10, 10, 15, 0.65);
    backdrop-filter: blur(8px);
    color: #0ff;
    font-size: 12px;
    font-family: inherit;
    white-space: nowrap;
  }
  .slider-control input[type="range"] { accent-color: #0ff; width: 110px; cursor: pointer; }

  #hud {
    position: fixed;
    bottom: 26px;
    left: 20px;
    font-size: 12px;
    line-height: 1.6;
    color: #0ff;
    opacity: 0.75;
    background: rgba(10, 10, 15, 0.5);
    border: 1px solid rgba(0, 255, 255, 0.25);
    border-radius: 8px;
    padding: 10px 14px;
    z-index: 5;
    display: none;
  }
  #hud.visible { display: block; }

  #live-badge {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: none;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    letter-spacing: 0.02em;
    color: #0ff;
    background: rgba(10, 10, 15, 0.6);
    border: 1px solid rgba(0, 255, 255, 0.35);
    border-radius: 999px;
    padding: 6px 14px;
    z-index: 6;
  }
  #live-badge.visible { display: flex; }
  #live-badge .label { white-space: nowrap; }
  #live-badge .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ff3366;
    box-shadow: 0 0 8px #ff3366;
    animation: live-pulse 1.2s ease-in-out infinite;
  }
  @keyframes live-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
  }
</style>
</head>
<body>

<div id="app"></div>
<div id="loading">Loading models…</div>
<div id="switcher"></div>
<div id="controls">
  <button id="tracking-toggle">Tracking: On</button>
  <button id="reset-btn">Reset</button>
  <div class="slider-control">
    <label for="delay-slider">Delay: <span id="delay-value">400</span>ms</label>
    <input type="range" id="delay-slider" min="0" max="1000" step="50" value="400">
  </div>
  <div class="slider-control">
    <label for="interp-slider">Interpolation: <span id="interp-value">0.20</span></label>
    <input type="range" id="interp-slider" min="0.05" max="1" step="0.05" value="0.2">
  </div>
</div>
<div id="live-badge"><span class="dot"></span><span class="label">Live from phyphox</span></div>
<div id="hud">
  <div>alpha: <span id="alpha">0</span>°</div>
  <div>beta: <span id="beta">0</span>°</div>
  <div>gamma: <span id="gamma">0</span>°</div>
</div>

<script type="importmap">
{
  "imports": {
    "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
    "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
  }
}
</script>

<script type="module">
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

const container = document.getElementById('app');
const loadingEl = document.getElementById('loading');
const switcherEl = document.getElementById('switcher');
const controlsEl = document.getElementById('controls');
const trackingToggleBtn = document.getElementById('tracking-toggle');
const resetBtn = document.getElementById('reset-btn');
const delaySliderEl = document.getElementById('delay-slider');
const delayValueEl = document.getElementById('delay-value');
const interpSliderEl = document.getElementById('interp-slider');
const interpValueEl = document.getElementById('interp-value');
const liveBadgeEl = document.getElementById('live-badge');
const liveBadgeLabelEl = liveBadgeEl.querySelector('.label');
const hudEl = document.getElementById('hud');
const alphaEl = document.getElementById('alpha');
const betaEl = document.getElementById('beta');
const gammaEl = document.getElementById('gamma');

// --- Scene / Camera / Renderer ---
const scene = new THREE.Scene();

const camera = new THREE.PerspectiveCamera(
  45,
  window.innerWidth / window.innerHeight,
  0.01, // near plane pulled in so extreme close-up zoom doesn't clip
  20000
);
camera.position.set(2, 1.5, 3);

const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;
container.appendChild(renderer.domElement);

// --- Orbit controls (drag to orbit the camera around the model) ---
const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.dampingFactor = 0.08;
controls.minDistance = 0.02;
controls.maxDistance = 50;
controls.target.set(0, -0.35, 0);
// Full freedom: orbit all the way around and underneath the floor too.

// Idle auto-rotation: spins slowly whenever nothing is streaming live,
// pausing while the user is orbiting and resuming a little after they let go.
let autoRotate = true;
let idleTimer = null;
controls.addEventListener('start', () => {
  autoRotate = false;
  clearTimeout(idleTimer);
});
controls.addEventListener('end', () => {
  idleTimer = setTimeout(() => { autoRotate = true; }, 3000);
});

// --- Background: dark gray studio backdrop (subtle vertical gradient so it
// doesn't read as flat), matching the site's dark/neon UI ---
const bgMat = new THREE.ShaderMaterial({
  uniforms: {
    topColor: { value: new THREE.Color(0x2a2c31) },
    bottomColor: { value: new THREE.Color(0x111214) }
  },
  vertexShader: `
    varying vec3 vWorldPosition;
    void main() {
      vec4 worldPosition = modelMatrix * vec4(position, 1.0);
      vWorldPosition = worldPosition.xyz;
      gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
    }
  `,
  fragmentShader: `
    uniform vec3 topColor;
    uniform vec3 bottomColor;
    varying vec3 vWorldPosition;
    void main() {
      float h = normalize(vWorldPosition).y * 0.5 + 0.5;
      gl_FragColor = vec4(mix(bottomColor, topColor, h), 1.0);
    }
  `,
  side: THREE.BackSide
});
scene.add(new THREE.Mesh(new THREE.SphereGeometry(500, 32, 15), bgMat));

// Fade far geometry into the background color — hides the floor grid's
// finite edge instead of letting it cut off abruptly.
scene.fog = new THREE.FogExp2(0x111214, 0.045);

// --- Lighting: a small studio rig (key + fill + rim + a soft light near the
// camera) so the model's details and edges actually read against the dark
// background, instead of one directional light doing everything ---
scene.add(new THREE.HemisphereLight(0x8fa8c2, 0x0a0a0d, 1.4));

const keyLight = new THREE.DirectionalLight(0xffffff, 3.4);
keyLight.position.set(3, 5, 4);
scene.add(keyLight);

const fillLight = new THREE.DirectionalLight(0xbcd6ff, 1.1);
fillLight.position.set(-4, 1.5, -2);
scene.add(fillLight);

// Rim light behind the model to pick out its silhouette against the dark bg
const rimLight = new THREE.DirectionalLight(0x66e0ff, 2.2);
rimLight.position.set(-1, 2.5, -5);
scene.add(rimLight);

// Soft light near the camera to add a highlight/catch-light on the screen
const cameraLight = new THREE.PointLight(0xffffff, 1.6, 20, 2);
cameraLight.position.set(2, 2, 4);
scene.add(cameraLight);

renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.15;

// --- Phone models ---
// The list of available models isn't hardcoded — api/models_list.php scans
// www/assets/*.glb, so dropping a new .glb file in there is enough to make
// it show up in the switcher, no code change needed. A couple of specific
// files ship with a rotation baked into their own scene graph; this is the
// one place that's still hand-maintained, since it can't be discovered
// generically.
const KNOWN_CORRECTIONS = {
  'low_poly_mobile_phone.glb': new THREE.Quaternion(0.38268343, 0, 0, 0.92387953)
};

let MODELS = [];
let DEFAULT_MODEL_ID = null;

const modelGroup = new THREE.Group();
scene.add(modelGroup);

const loader = new GLTFLoader();
const wrappers = {};
let floorY = Infinity;

function loadModel(config) {
  return new Promise((resolve, reject) => {
    loader.load(
      config.url,
      (gltf) => {
        const model = gltf.scene;

        if (config.correction) {
          model.quaternion.copy(config.correction);
        }

        const box = new THREE.Box3().setFromObject(model);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z) || 1;
        const scale = 1.6 / maxDim;

        model.scale.setScalar(scale);
        model.position.set(
          -center.x * scale,
          -center.y * scale - 0.35,
          -center.z * scale
        );

        const wrapper = new THREE.Group();
        wrapper.add(model);
        wrapper.visible = false;
        modelGroup.add(wrapper);
        wrappers[config.id] = wrapper;

        const worldBox = new THREE.Box3().setFromObject(model);
        floorY = Math.min(floorY, worldBox.min.y);

        resolve();
      },
      undefined,
      reject
    );
  });
}

function showModel(id) {
  for (const key in wrappers) {
    wrappers[key].visible = (key === id);
  }
  for (const btn of switcherEl.children) {
    btn.classList.toggle('active', btn.dataset.id === id);
  }
}

fetch('api/models_list.php')
  .then((r) => r.json())
  .then((files) => {
    MODELS = files.map((f) => ({
      id: f.id,
      label: f.label,
      url: f.url,
      correction: KNOWN_CORRECTIONS[f.url.split('/').pop()] || null
    }));

    if (MODELS.length === 0) {
      loadingEl.textContent = 'No .glb files found in www/assets/.';
      return Promise.reject(new Error('no models'));
    }
    DEFAULT_MODEL_ID = MODELS[0].id;

    for (const config of MODELS) {
      const btn = document.createElement('button');
      btn.textContent = config.label;
      btn.dataset.id = config.id;
      btn.addEventListener('click', () => showModel(config.id));
      switcherEl.appendChild(btn);
    }

    return Promise.all(MODELS.map(loadModel));
  })
  .then(() => {
    const grid = new THREE.GridHelper(28, 56, 0x6a6e78, 0x3a3d43);
    grid.position.y = floorY + 0.002;
    grid.material.transparent = true;
    grid.material.opacity = 0.6;
    scene.add(grid);

    showModel(DEFAULT_MODEL_ID);
    loadingEl.classList.add('hidden');
    switcherEl.classList.add('visible');
    controlsEl.classList.add('visible');
    startPhyphoxLivePoll();
  })
  .catch((error) => {
    console.error('Failed to load model(s):', error);
    if (MODELS.length > 0) loadingEl.textContent = 'Could not load the 3D model.';
  });

// --- Live phyphox stream ---
// Polls the server for whatever phone is actively streaming via
// api/motion_stream.php right now (see phyphox_ingest.php) and drives the
// model's rotation automatically — no button here, this is the whole point
// of the phyphox workaround: watch it live on a computer while it streams.
// (Position/motion tracking was tried and dropped -- accelerometer-only
// position estimation isn't reliable enough to be worth the complexity, so
// this only tracks rotation, which phyphox's attitude sensor reports
// directly and accurately.)
//
// Each poll returns a small batch of newly-landed keyframes (not just the
// latest one) into a short queue; a playback delay (LIVE_BUFFER_MS, tunable
// live via the delay slider) is deliberately held so the render loop always
// has two real samples on hand to slerp between every frame, instead of the
// model snapping to a new pose only once per poll. Lower it for less lag
// but choppier motion, raise it for smoother motion but more delay.
let LIVE_BUFFER_MS = 400; // adjustable live via the delay slider
// How much the model eases toward each new buffered sample per frame
// (tunable live via the interpolation slider): lower is smoother but
// laggier behind the buffer, higher is snappier but more prone to visible
// stepping if the buffer itself is running low on samples.
let LIVE_SMOOTH_FACTOR = 0.2;

let liveActive = false;
let liveRecordingId = null;
let liveSinceCursor = -1;
let liveQueue = []; // ascending by t: { t, qx, qy, qz, qw }
let liveAnchorWall = null; // performance.now() when playback of this queue began
let liveAnchorT = null;    // the recording's own t (ms) that wall time lines up with

const deviceQuaternion = new THREE.Quaternion();
const liveEuler = new THREE.Euler();
const liveQuatA = new THREE.Quaternion();
const liveQuatB = new THREE.Quaternion();

// Pausing/resetting are purely local viewing conveniences -- neither one
// touches the data phyphox_ingest.php is writing to the database.
let trackingEnabled = true;

// "Reset" re-zeroes the display without needing the server involved: it
// captures the current raw orientation as a reference, and every pose
// after that is shown relative to it (inverse-rotate) until the next reset
// or a new stream begins.
const refQuatInverse = new THREE.Quaternion();
const rawQuat = new THREE.Quaternion();

function refreshLiveBadge() {
  if (!liveActive) {
    liveBadgeEl.classList.remove('visible');
    return;
  }
  liveBadgeEl.classList.add('visible');
  liveBadgeLabelEl.textContent = trackingEnabled
    ? 'Live from phyphox'
    : 'Live from phyphox — tracking paused';
}

async function pollPhyphoxLive() {
  try {
    const params = new URLSearchParams();
    if (liveRecordingId !== null) {
      params.set('recording_id', liveRecordingId);
      params.set('since', liveSinceCursor);
    }
    const res = await fetch('api/phyphox_live.php?' + params.toString());
    const d = await res.json();

    if (!d.active) {
      if (liveActive) {
        liveActive = false;
        liveRecordingId = null;
        liveQueue = [];
        liveAnchorWall = null;
        hudEl.classList.remove('visible');
        refreshLiveBadge();
      }
      return;
    }

    if (!liveActive) {
      liveActive = true;
      hudEl.classList.add('visible');
      refreshLiveBadge();
    }

    if (d.recording_id !== liveRecordingId) {
      // (Re)joining a stream -- reset the buffer and the reset reference
      // (an old one belongs to a different session).
      liveRecordingId = d.recording_id;
      liveSinceCursor = -1;
      liveQueue = [];
      liveAnchorWall = null;
      refQuatInverse.identity();
      modelGroup.quaternion.identity();
    }

    if (d.keyframes && d.keyframes.length > 0) {
      liveQueue.push(...d.keyframes);
      liveSinceCursor = d.keyframes[d.keyframes.length - 1].t;
    }
  } catch (err) {
    // Transient network hiccup -- just try again on the next tick.
  }
}

let livePollTimer = null;
function startPhyphoxLivePoll() {
  if (livePollTimer) return;
  pollPhyphoxLive();
  livePollTimer = setInterval(pollPhyphoxLive, 100);
}

function updateLivePlayback() {
  if (!liveActive || liveQueue.length === 0) return;

  if (liveAnchorWall === null) {
    liveAnchorWall = performance.now();
    liveAnchorT = liveQueue[0].t;
  }
  const targetT = liveAnchorT + (performance.now() - liveAnchorWall) - LIVE_BUFFER_MS;

  // Drop samples already played past, keeping the one just behind targetT
  // as the interpolation start point.
  while (liveQueue.length > 1 && liveQueue[1].t <= targetT) {
    liveQueue.shift();
  }

  const a = liveQueue[0];
  const b = liveQueue.length > 1 ? liveQueue[1] : null;

  if (b && targetT > a.t) {
    const frac = THREE.MathUtils.clamp((targetT - a.t) / (b.t - a.t), 0, 1);
    liveQuatA.set(a.qx, a.qy, a.qz, a.qw);
    liveQuatB.set(b.qx, b.qy, b.qz, b.qw);
    rawQuat.copy(liveQuatA).slerp(liveQuatB, frac);
  } else {
    // Not enough buffered data to interpolate yet (still warming up, or the
    // network fell behind) -- hold on the newest sample we do have.
    rawQuat.set(a.qx, a.qy, a.qz, a.qw);
  }

  // Re-express relative to the last reset point (identity until Reset has
  // been pressed).
  deviceQuaternion.copy(refQuatInverse).multiply(rawQuat);

  liveEuler.setFromQuaternion(deviceQuaternion, 'YXZ');
  alphaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.y));
  betaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.x));
  gammaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.z));
}

// --- Tracking toggle + reset ---
trackingToggleBtn.addEventListener('click', () => {
  trackingEnabled = !trackingEnabled;
  trackingToggleBtn.textContent = trackingEnabled ? 'Tracking: On' : 'Tracking: Off';
  trackingToggleBtn.classList.toggle('off', !trackingEnabled);
  refreshLiveBadge();
});

resetBtn.addEventListener('click', () => {
  refQuatInverse.copy(rawQuat).invert();
  // Snap immediately instead of waiting for the slerp to catch up.
  deviceQuaternion.identity();
  modelGroup.quaternion.identity();
});

// Both sliders take effect on the very next frame simply by being read
// live inside animate()/updateLivePlayback() -- the delay slider additionally
// shifts the playback anchor by the same amount the buffer just changed by,
// so adjusting it mid-stream doesn't cause a visible jump.
delaySliderEl.addEventListener('input', () => {
  const newValue = Number(delaySliderEl.value);
  if (liveAnchorT !== null) {
    liveAnchorT += newValue - LIVE_BUFFER_MS;
  }
  LIVE_BUFFER_MS = newValue;
  delayValueEl.textContent = LIVE_BUFFER_MS;
});

interpSliderEl.addEventListener('input', () => {
  LIVE_SMOOTH_FACTOR = Number(interpSliderEl.value);
  interpValueEl.textContent = LIVE_SMOOTH_FACTOR.toFixed(2);
});

// --- Animation loop ---
function animate() {
  requestAnimationFrame(animate);
  updateLivePlayback();

  if (liveActive && trackingEnabled) {
    modelGroup.quaternion.slerp(deviceQuaternion, LIVE_SMOOTH_FACTOR);
  } else if (!liveActive && autoRotate) {
    modelGroup.rotation.y += 0.004;
  }

  controls.update();
  renderer.render(scene, camera);
}
animate();

function resizeViewer() {
  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
}
window.addEventListener('resize', resizeViewer);
</script>

</body>
</html>

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

  #streams-panel {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 6;
    background: rgba(10, 10, 15, 0.6);
    border: 1px solid rgba(0, 255, 255, 0.25);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #ccc;
    min-width: 180px;
  }
  #streams-panel .streams-title {
    color: #0ff;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    font-size: 11px;
    margin-bottom: 6px;
  }
  #streams-panel .stream-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 3px 0;
  }
  #streams-panel .stream-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #666; /* inactive by default */
    flex-shrink: 0;
  }
  #streams-panel .stream-dot.active {
    background: #3f7;
    box-shadow: 0 0 6px #3f7;
  }
  #streams-panel .stream-dot.stale {
    background: #e9a53f;
    box-shadow: 0 0 6px #e9a53f;
  }
  #streams-panel a#open-tracker-link {
    display: inline-block;
    margin-top: 8px;
    color: #0ff;
    text-decoration: none;
    font-size: 12px;
  }
  #streams-panel a#open-tracker-link:hover { text-decoration: underline; }
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
    <label for="delay-slider">Delay: <span id="delay-value">80</span>ms</label>
    <input type="range" id="delay-slider" min="0" max="1000" step="20" value="80">
  </div>
  <div class="slider-control">
    <label for="interp-slider">Interpolation: <span id="interp-value">0.60</span></label>
    <input type="range" id="interp-slider" min="0.05" max="1" step="0.05" value="0.6">
  </div>
  <div class="slider-control" id="scale-control" style="display:none">
    <label for="scale-slider">Position scale: <span id="scale-value">4.0</span>x</label>
    <input type="range" id="scale-slider" min="0.5" max="15" step="0.5" value="4">
  </div>
  <div class="slider-control" id="ghost-control" style="display:none">
    <label><input type="checkbox" id="ghost-toggle"> Show raw ARCore (ghost)</label>
  </div>
</div>
<div id="live-badge"><span class="dot"></span><span class="label">Live</span></div>
<div id="hud">
  <div>alpha: <span id="alpha">0</span>°</div>
  <div>beta: <span id="beta">0</span>°</div>
  <div>gamma: <span id="gamma">0</span>°</div>
  <div id="pos-row" style="display:none">pos: <span id="pos">0.00, 0.00, 0.00</span></div>
</div>
<div id="streams-panel">
  <div class="streams-title">Streams</div>
  <div class="stream-row">
    <span class="stream-dot" id="phone-dot"></span>
    <span>Phone: <span id="phone-status">inactive</span></span>
  </div>
  <div class="stream-row">
    <span class="stream-dot" id="webcam-dot"></span>
    <span>Webcam: <span id="webcam-status">not connected</span></span>
  </div>
  <a id="open-tracker-link" href="marker_tracker.php" target="_blank" rel="noopener">Open camera tracker ↗</a>
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
const scaleControlEl = document.getElementById('scale-control');
const scaleSliderEl = document.getElementById('scale-slider');
const scaleValueEl = document.getElementById('scale-value');
const liveBadgeEl = document.getElementById('live-badge');
const liveBadgeLabelEl = liveBadgeEl.querySelector('.label');
const hudEl = document.getElementById('hud');
const alphaEl = document.getElementById('alpha');
const betaEl = document.getElementById('beta');
const gammaEl = document.getElementById('gamma');
const posRowEl = document.getElementById('pos-row');
const posEl = document.getElementById('pos');
const ghostControlEl = document.getElementById('ghost-control');
const ghostToggleEl = document.getElementById('ghost-toggle');
const phoneDotEl = document.getElementById('phone-dot');
const phoneStatusEl = document.getElementById('phone-status');
const webcamDotEl = document.getElementById('webcam-dot');
const webcamStatusEl = document.getElementById('webcam-status');

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

// The position math re-expresses ARCore's movement relative to however the
// phone was held at the last Reset, which makes "push the phone away from
// you" map to one *consistent* 3D axis in the scene -- but that's only
// intuitive on screen if the viewer's own camera happens to be looking
// straight down that axis. The default orbit angle (2, 1.5, 3) is angled
// off to the side for a nicer-looking idle shot, so even correct
// forward/back motion projects onto the screen as a diagonal mix,
// including a left-right component -- which is exactly what reads as "goes
// sideways". Snapping the viewer's own camera to a straight-on view
// whenever the reference frame is re-established (Reset, or picking up a
// new stream) fixes that: forward/back then actually looks like
// toward/away on screen, at least until the person orbits away from it
// themselves afterward.
function snapViewerToFrontView() {
  camera.position.set(0, controls.target.y + 1.0, 4.2);
  controls.update();
}

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
let ghostGroup = null;       // raw-ARCore "ghost" copy -- created once models finish loading, see below
const ghostWrappers = {};

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
    if (ghostWrappers[key]) ghostWrappers[key].visible = (key === id);
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

    // "Ghost" model: a second, translucent copy of the SAME phone model,
    // driven by the raw/uncorrected ARCore pose (pre-webcam-fusion) while
    // the main model shows the fused pose -- lets you literally see what
    // the webcam correction is doing, instead of just trusting the HUD
    // numbers. Deep-cloned so it shares geometry/vertex buffers with the
    // real model (cheap), but materials are cloned per-mesh too so its
    // opacity/tint can differ without touching the real model's own
    // materials (clone(true) alone would still share material objects).
    ghostGroup = modelGroup.clone(true);
    ghostGroup.traverse((obj) => {
      if (obj.isMesh) {
        const wasArray = Array.isArray(obj.material);
        const mats = wasArray ? obj.material : [obj.material];
        const cloned = mats.map((m) => {
          const clone = m.clone();
          clone.transparent = true;
          clone.opacity = 0.35;
          clone.depthWrite = false;
          clone.color = new THREE.Color(0xff8c3f); // orange tint -- visually distinct from the (unmodified) real model
          clone.emissive = new THREE.Color(0xff8c3f);
          clone.emissiveIntensity = 0.5;
          return clone;
        });
        obj.material = wasArray ? cloned : cloned[0];
      }
    });
    ghostGroup.visible = false;
    scene.add(ghostGroup);
    // Map each wrapper id to its cloned counterpart (clone(true) preserves
    // child order, so pairing by index with the original is reliable) so
    // showModel() can keep both the real and ghost model in sync when the
    // switcher is used mid-stream.
    Object.keys(wrappers).forEach((id, i) => {
      ghostWrappers[id] = ghostGroup.children[i];
    });
    showModel(DEFAULT_MODEL_ID); // re-apply now that ghostWrappers exists too

    startPhyphoxLivePoll();
  })
  .catch((error) => {
    console.error('Failed to load model(s):', error);
    if (MODELS.length > 0) loadingEl.textContent = 'Could not load the 3D model.';
  });

// --- Live stream (phyphox or the ARCore app) ---
// Polls the server for whatever phone is actively streaming right now --
// either phyphox (api/phyphox_ingest.php, rotation only) or the companion
// ARCore app (api/arcore_ingest.php, full 6DoF position + rotation) -- and
// drives the model automatically, no button here: that's the whole point,
// watch it live on a computer while it streams. Position is only applied
// when the active recording's `source` is 'arcore' -- a phyphox stream's
// position fields are just the schema default (0,0,0) and would otherwise
// yank the model to the origin.
//
// Each poll returns a small batch of newly-landed keyframes (not just the
// latest one) into a short queue; a playback delay (LIVE_BUFFER_MS, tunable
// live via the delay slider) is deliberately held so the render loop always
// has two real samples on hand to interpolate between every frame, instead
// of the model snapping to a new pose only once per poll. Lower it for less
// lag but choppier motion, raise it for smoother motion but more delay.
let LIVE_BUFFER_MS = 80; // adjustable live via the delay slider -- defaults low, favoring responsiveness over smoothness
// How much the model eases toward each new buffered sample per frame
// (tunable live via the interpolation slider): lower is smoother but
// laggier behind the buffer, higher is snappier but more prone to visible
// stepping if the buffer itself is running low on samples.
let LIVE_SMOOTH_FACTOR = 0.6;

let liveActive = false;
let liveSource = 'phyphox'; // 'phyphox' | 'arcore' -- which source the active recording is from
let liveRecordingId = null;
let liveSinceCursor = -1;
let needsAutoReference = false; // set on (re)joining a stream; consumed once its first real sample arrives
let liveQueue = []; // ascending by t: { t, qx, qy, qz, qw, px, py, pz }
let liveAnchorWall = null; // performance.now() when playback of this queue began
let liveAnchorT = null;    // the recording's own t (ms) that wall time lines up with

// Real-world meters (from the ARCore app) -> viewer units. Ordinary
// hand-scale motion is only a few centimeters, which is barely visible
// against a model that's ~1.6 units across at 1:1 -- so this defaults well
// above 1:1 and is live-tunable via the position scale slider (shown only
// while an ARCore stream is active).
let POSITION_SCALE = 4.0;

const deviceQuaternion = new THREE.Quaternion();
const devicePos = new THREE.Vector3();
const liveEuler = new THREE.Euler();
const liveQuatA = new THREE.Quaternion();
const liveQuatB = new THREE.Quaternion();
const livePosA = new THREE.Vector3();
const livePosB = new THREE.Vector3();

// Pausing/resetting are purely local viewing conveniences -- neither one
// touches the data the ingest endpoints are writing to the database.
let trackingEnabled = true;

// "Reset" re-zeroes the display without needing the server involved: it
// captures the current raw orientation/position as a reference, and every
// pose after that is shown relative to it (inverse-rotate / subtract) until
// the next reset or a new stream begins. Position reset matters especially
// for the ARCore app, whose world origin is just wherever its tracking
// session happened to start.
const refQuatInverse = new THREE.Quaternion();
const refPosOffset = new THREE.Vector3();
const rawQuat = new THREE.Quaternion();
const rawPos = new THREE.Vector3();

// --- Second tracking channel: an external camera (laptop/second-phone
// webcam watching an ArUco marker on the phone -- see marker_tracker.php)
// reporting the SAME physical phone's pose from outside, in its own
// camera-centered frame. That frame has an unknown fixed rotation relative
// to ARCore's own session-arbitrary world frame, so it can't be used
// directly -- it first needs aligning, which only needs ONE simultaneous
// correspondence (both channels can observe the phone's full 3D
// orientation at the same moment) rather than any manual calibration step:
//
//   extAlignQuat = rawQuat(t0) * extQuat(t0)^-1
//
// captured once when the external channel's first sample lands after a
// (re)join/Reset -- exactly mirroring how refQuatInverse/refPosOffset
// themselves get captured from the phone's own first sample. After that,
// extAlignQuat * extQuat(t) is what the external channel thinks the
// phone's ARCore-equivalent raw orientation is, directly comparable to
// ARCore's own rawQuat -- and persistently blended in via
// rotationCorrectionQuat/positionCorrectionOffset below, continuously, not
// just once at alignment time. This is what lets the webcam's drift-free
// (but noisier, slower) position correct ARCore's drift-prone (but fast,
// smooth) one without discarding either source.
let haveExternalFix = false;
let needsExternalReference = false;
let lastExternalT = null; // dedupe: phyphox_live.php resends the last ~2s of external samples every poll
const extAlignQuat = new THREE.Quaternion();
const extRefPos = new THREE.Vector3();
const extQuat = new THREE.Quaternion();
const extPos = new THREE.Vector3();
const extQuatEquiv = new THREE.Quaternion(); // extAlignQuat * extQuat(t): external sample, rotated into ARCore's raw frame
const extDisplacement = new THREE.Vector3();
const extDeviceEquivPos = new THREE.Vector3(); // same display frame as devicePos
const positionCorrectionOffset = new THREE.Vector3(); // persistent -- added to devicePos every frame
const rotationCorrectionQuat = new THREE.Quaternion();  // persistent -- composed onto deviceQuaternion every frame
const fusedPos = new THREE.Vector3();
const fusedQuat = new THREE.Quaternion();
// How strongly each new external sample pulls the persistent correction
// toward what the webcam just saw. Not a slow once-in-a-while trim: at
// ~12Hz external samples this reaches most of the way to a steady
// disagreement within well under a second, so the webcam channel is a
// genuine continuous contributor, not just an occasional nudge -- while
// still being a weighted EMA rather than an outright override, so one bad
// marker read (motion blur, bad angle) doesn't yank the model around.
const EXTERNAL_BLEND_FACTOR = 0.35;

function refreshLiveBadge() {
  if (!liveActive) {
    liveBadgeEl.classList.remove('visible');
    return;
  }
  liveBadgeEl.classList.add('visible');
  let sourceLabel = liveSource === 'arcore' ? 'Live from ARCore app' : 'Live from phyphox';
  if (haveExternalFix) sourceLabel += ' + webcam';
  liveBadgeLabelEl.textContent = trackingEnabled ? sourceLabel : `${sourceLabel} — tracking paused`;
  posRowEl.style.display = liveSource === 'arcore' ? '' : 'none';
  scaleControlEl.style.display = liveSource === 'arcore' ? '' : 'none';
  ghostControlEl.style.display = (liveSource === 'arcore' && haveExternalFix) ? '' : 'none';
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
    }
    liveSource = d.source || 'phyphox';
    refreshLiveBadge();

    if (d.recording_id !== liveRecordingId) {
      // (Re)joining a stream -- reset the buffer. The reset REFERENCE
      // itself is deliberately not zeroed here: doing that would show
      // ARCore's raw pose, in its own session-arbitrary heading, for
      // however long it takes the person to remember to press Reset
      // themselves. Instead this stream's first real sample (below) is
      // captured as the reference automatically -- exactly what pressing
      // Reset does, just immediate, so a fresh stream is never shown raw.
      liveRecordingId = d.recording_id;
      liveSinceCursor = -1;
      liveQueue = [];
      liveAnchorWall = null;
      needsAutoReference = true;
      // New stream -- any previous external alignment belonged to the old
      // one (different recording_id, quite possibly a different physical
      // arrangement of the webcam too). Re-arm it rather than carrying a
      // stale alignment/correction forward.
      needsExternalReference = true;
      haveExternalFix = false;
      lastExternalT = null;
      lastExternalWallMs = null;
      positionCorrectionOffset.set(0, 0, 0);
      rotationCorrectionQuat.identity();
      modelGroup.quaternion.identity();
      modelGroup.position.set(0, 0, 0);
      snapViewerToFrontView();
    }

    if (d.keyframes && d.keyframes.length > 0) {
      if (needsAutoReference) {
        const first = d.keyframes[0];
        refQuatInverse.set(first.qx, first.qy, first.qz, first.qw).invert();
        refPosOffset.set(first.px, first.py, first.pz);
        needsAutoReference = false;
      }
      liveQueue.push(...d.keyframes);
      liveSinceCursor = d.keyframes[d.keyframes.length - 1].t;
    }

    if (d.external && d.external.length > 0) {
      for (const sample of d.external) {
        if (lastExternalT !== null && sample.t <= lastExternalT) continue; // already processed
        lastExternalT = sample.t;
        lastExternalWallMs = performance.now();
        extQuat.set(sample.qx, sample.qy, sample.qz, sample.qw);
        extPos.set(sample.px, sample.py, sample.pz);

        if (needsExternalReference) {
          // One-time correspondence: both channels are observing the same
          // physical orientation right now, so this fully determines the
          // fixed rotation between their two frames. Deliberately NOT
          // re-snapped on every later sample -- that would make the
          // external channel trivially agree with ARCore instead of being
          // an independent check on it.
          //
          // Uses the freshest ARCore sample actually known at THIS poll
          // (the newest queued keyframe, if this poll delivered any) rather
          // than rawQuat/rawPos -- those are only updated by the render
          // loop's updateLivePlayback(), so on the exact poll where a new
          // stream and the external reference both land together, they
          // could still be holding the PREVIOUS stream's last values.
          const freshestArcore = liveQueue.length > 0 ? liveQueue[liveQueue.length - 1] : null;
          const alignQuatSrc = freshestArcore
            ? liveQuatA.set(freshestArcore.qx, freshestArcore.qy, freshestArcore.qz, freshestArcore.qw)
            : rawQuat;
          extAlignQuat.copy(alignQuatSrc).multiply(extQuat.clone().invert());
          extRefPos.copy(extPos);
          needsExternalReference = false;
          haveExternalFix = true;
          refreshLiveBadge();
          continue; // this sample defines the reference; nothing to correct against yet
        }

        // This sample's ARCore-equivalent raw orientation/displacement,
        // using the fixed alignment above -- directly comparable to
        // ARCore's own rawQuat / (rawPos - refPosOffset).
        extQuatEquiv.copy(extAlignQuat).multiply(extQuat);
        extDisplacement.copy(extPos).sub(extRefPos).applyQuaternion(extAlignQuat);

        // Express both corrections in the same DISPLAY frame devicePos/
        // deviceQuaternion already use, then blend the persistent
        // correction toward what this sample implies. EMA rather than a
        // hard snap: one bad marker read (blur, grazing angle) nudges
        // things rather than yanking them.
        extDeviceEquivPos.copy(extDisplacement).applyQuaternion(refQuatInverse).multiplyScalar(POSITION_SCALE);
        positionCorrectionOffset.lerp(
          extDeviceEquivPos.clone().sub(devicePos),
          EXTERNAL_BLEND_FACTOR
        );

        fusedQuat.copy(refQuatInverse).multiply(extQuatEquiv); // external-implied display orientation
        const arcoreDisplayQuat = refQuatInverse.clone().multiply(rawQuat);
        const desiredRotCorrection = fusedQuat.clone().multiply(arcoreDisplayQuat.clone().invert());
        rotationCorrectionQuat.slerp(desiredRotCorrection, EXTERNAL_BLEND_FACTOR);
      }
    }
  } catch (err) {
    // Transient network hiccup -- just try again on the next tick.
  }
}

let livePollTimer = null;
function startPhyphoxLivePoll() {
  if (livePollTimer) return;
  pollPhyphoxLive();
  // 30ms rather than the old 100ms: this poll is a lightweight indexed
  // query against a local server, so polling faster costs little and
  // directly lowers the floor on how fresh the buffered data can be --
  // unlike the delay/interpolation sliders, this isn't something the page
  // exposes as adjustable, since there's little reason to ever want it slower.
  livePollTimer = setInterval(pollPhyphoxLive, 30);
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
    livePosA.set(a.px, a.py, a.pz);
    livePosB.set(b.px, b.py, b.pz);
    rawPos.copy(livePosA).lerp(livePosB, frac);
  } else {
    // Not enough buffered data to interpolate yet (still warming up, or the
    // network fell behind) -- hold on the newest sample we do have.
    rawQuat.set(a.qx, a.qy, a.qz, a.qw);
    rawPos.set(a.px, a.py, a.pz);
  }

  // Re-express relative to the last reset point (identity/zero until Reset
  // has been pressed).
  deviceQuaternion.copy(refQuatInverse).multiply(rawQuat);
  // Position needs the same reference-frame rotation applied that
  // orientation gets (refQuatInverse), not just an offset subtraction:
  // ARCore's position is reported in its own session-arbitrary world
  // frame (whichever direction the phone happened to be facing the moment
  // tracking started), which generally does NOT line up with the viewer's
  // own fixed scene axes. Without rotating the position delta into the
  // same "as held at last Reset" frame that orientation already uses,
  // pushing the phone in a real-world direction could show up on
  // basically any axis in the viewer -- including sideways -- and that
  // mapping would silently change every time tracking restarts.
  devicePos.copy(rawPos).sub(refPosOffset).applyQuaternion(refQuatInverse).multiplyScalar(POSITION_SCALE);

  // Fold in the webcam channel's persistent correction, if any (both stay
  // at identity/zero until an external fix exists, so this is a no-op
  // otherwise -- the model behaves exactly as before with no second
  // device connected).
  fusedPos.copy(devicePos).add(positionCorrectionOffset);
  fusedQuat.copy(rotationCorrectionQuat).multiply(deviceQuaternion).normalize();

  liveEuler.setFromQuaternion(fusedQuat, 'YXZ');
  alphaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.y));
  betaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.x));
  gammaEl.textContent = Math.round(THREE.MathUtils.radToDeg(liveEuler.z));
  if (liveSource === 'arcore') {
    posEl.textContent = `${fusedPos.x.toFixed(2)}, ${fusedPos.y.toFixed(2)}, ${fusedPos.z.toFixed(2)}`;
  }
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
  refPosOffset.copy(rawPos);
  // Snap immediately instead of waiting for the slerp/lerp to catch up.
  deviceQuaternion.identity();
  devicePos.set(0, 0, 0);
  modelGroup.quaternion.identity();
  modelGroup.position.set(0, 0, 0);
  snapViewerToFrontView();
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

scaleSliderEl.addEventListener('input', () => {
  POSITION_SCALE = Number(scaleSliderEl.value);
  scaleValueEl.textContent = POSITION_SCALE.toFixed(1);
});

let showGhost = false;
ghostToggleEl.addEventListener('change', () => {
  showGhost = ghostToggleEl.checked;
});

// --- Streams panel: a visual/status read-out of every channel currently
// feeding the model, not just whichever one happens to be shown -- the
// phone's own ARCore stream, and (if connected) the webcam's marker
// tracker. Refreshed on a plain timer rather than piggybacking on the
// live-poll responses, since "webcam went stale" needs to be noticed even
// between polls that have nothing new in them.
let lastExternalWallMs = null; // when we last actually RECEIVED a new external sample (wall clock, not the sample's own t)
function refreshStreamsPanel() {
  if (liveActive) {
    phoneDotEl.className = 'stream-dot active';
    phoneStatusEl.textContent = liveSource === 'arcore' ? 'ARCore app, live' : 'phyphox, live';
  } else {
    phoneDotEl.className = 'stream-dot';
    phoneStatusEl.textContent = 'inactive';
  }

  if (!haveExternalFix) {
    webcamDotEl.className = 'stream-dot';
    webcamStatusEl.textContent = liveSource === 'arcore' ? 'not connected' : 'n/a (phyphox has no position)';
  } else {
    const ageMs = lastExternalWallMs === null ? Infinity : performance.now() - lastExternalWallMs;
    if (ageMs < 1500) {
      webcamDotEl.className = 'stream-dot active';
      webcamStatusEl.textContent = 'tracking marker, correcting';
    } else {
      webcamDotEl.className = 'stream-dot stale';
      webcamStatusEl.textContent = `no fresh sample (${Math.round(ageMs / 1000)}s) — marker out of view?`;
    }
  }
}
setInterval(refreshStreamsPanel, 500);

// --- Animation loop ---
function animate() {
  requestAnimationFrame(animate);
  updateLivePlayback();

  if (liveActive && trackingEnabled) {
    modelGroup.quaternion.slerp(fusedQuat, LIVE_SMOOTH_FACTOR);
    if (liveSource === 'arcore') {
      modelGroup.position.lerp(fusedPos, LIVE_SMOOTH_FACTOR);
    }

    if (ghostGroup) {
      const showingGhost = showGhost && haveExternalFix && liveSource === 'arcore';
      ghostGroup.visible = showingGhost;
      if (showingGhost) {
        // Raw/uncorrected ARCore pose -- deliberately NOT run through the
        // same slerp/lerp smoothing as the main model, so this is exactly
        // "what ARCore alone currently says", not a softened version of it.
        ghostGroup.quaternion.copy(deviceQuaternion);
        ghostGroup.position.copy(devicePos);
      }
    }
  } else if (!liveActive && autoRotate) {
    modelGroup.rotation.y += 0.004;
    if (ghostGroup) ghostGroup.visible = false;
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

<?php
// A tiny landing page for getting the "Motion Stream" experiment into the
// phyphox app. Typing a phyphox:// link straight into a mobile browser's
// address bar is usually ignored (browsers only hand off custom-scheme
// links when you TAP a real <a href> or scan a QR code) — so this page
// exists purely to give you something to tap or scan.
//
// The IP field below drives the whole link/QR code: it's used both as the
// host phyphox fetches this experiment from and as the address baked into
// it for sending sensor data back (api/motion_stream.php's ?ip=). That
// matters because this page is often viewed on the computer itself (e.g.
// as "localhost:8080") to display the QR code for a phone's camera to
// scan — "localhost" would only mean the phone itself, so the field must
// hold the computer's real LAN address for the QR/link to work from
// another device, not whatever host this page happened to load from.
$pageHost = $_SERVER['HTTP_HOST'] ?? '172.20.10.2:8080';
$hostParts = explode(':', $pageHost, 2);
$defaultPort = $hostParts[1] ?? '8080';
// Rendered directly into the page's HTML (not set by JS after load) so the
// button works immediately even on a phone browser that's strict about
// only handing off a custom phyphox:// scheme for a link that was already
// there when the page arrived, not one a script edited in afterwards. The
// inline script below still takes over from here once the IP field is
// touched.
$initialUrl = 'phyphox://' . $pageHost . '/api/motion_stream.php?ip=' . urlencode($pageHost);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Get Motion Stream</title>
<style>
  body {
    background: #0a0a0f;
    color: #e8e8f0;
    font-family: system-ui, -apple-system, sans-serif;
    margin: 0;
    padding: 32px 20px 60px;
    text-align: center;
  }
  h1 { font-size: 1.3rem; color: #0ff; margin-bottom: 8px; }
  p { color: #9fa3b0; line-height: 1.5; max-width: 420px; margin: 0 auto 20px; }
  label.field-label {
    display: block;
    font-size: 0.85rem;
    color: #9fa3b0;
    margin: 0 auto 8px;
    max-width: 420px;
  }
  #ip-input {
    display: block;
    width: 240px;
    max-width: 80%;
    margin: 0 auto 24px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid rgba(0, 255, 255, 0.35);
    background: rgba(255, 255, 255, 0.05);
    color: #0ff;
    font-size: 1rem;
    text-align: center;
    font-family: inherit;
  }
  #ip-input:focus { outline: none; border-color: #0ff; }
  .btn {
    display: inline-block;
    background: #0ff;
    color: #05050a;
    font-weight: 700;
    text-decoration: none;
    padding: 16px 28px;
    border-radius: 10px;
    font-size: 1.05rem;
    box-shadow: 0 0 20px rgba(0,255,255,0.35);
    margin: 10px 0 28px;
  }
  .btn:active { transform: scale(0.98); }
  .qr {
    background: #fff;
    display: inline-block;
    padding: 14px;
    border-radius: 10px;
    margin-top: 4px;
  }
  .hint { font-size: 0.85rem; color: #6b6f7d; margin-top: 28px; }
  code { color: #0ff; word-break: break-all; }
</style>
</head>
<body>
  <h1>Get "Motion Stream" into phyphox</h1>
  <p>Tap the button below on your phone (install phyphox first if you haven't).
     If nothing happens when you tap it, open phyphox, use its QR-scanner,
     and scan the code underneath instead.</p>

  <label class="field-label" for="ip-input">This computer's LAN address (used for both the link and QR code below):</label>
  <input type="text" id="ip-input" spellcheck="false" autocapitalize="off" autocomplete="off"
         value="<?php echo htmlspecialchars($pageHost); ?>">

  <a class="btn" id="open-btn" href="<?php echo htmlspecialchars($initialUrl); ?>">Open in phyphox</a>

  <div class="qr"><div id="qr"></div></div>

  <p class="hint">Link this page encodes: <code id="link-preview"><?php echo htmlspecialchars($initialUrl); ?></code></p>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    const pageHost = <?php echo json_encode($pageHost); ?>;
    const defaultPort = <?php echo json_encode($defaultPort); ?>;
    const ipInput = document.getElementById('ip-input');
    const openBtn = document.getElementById('open-btn');
    const linkPreview = document.getElementById('link-preview');
    const qrEl = document.getElementById('qr');
    let qr = null;

    // A bare IP with no port would default to :80, which nothing here
    // listens on -- that hangs instead of failing fast, so fill in the
    // same port this page is being served on if one wasn't typed.
    function normalizeIp(raw) {
      const v = raw.trim() || pageHost;
      return v.includes(':') ? v : v + ':' + defaultPort;
    }

    function currentUrl() {
      const ip = normalizeIp(ipInput.value);
      return 'phyphox://' + ip + '/api/motion_stream.php?ip=' + encodeURIComponent(ip);
    }

    function refresh() {
      const url = currentUrl();
      openBtn.href = url;
      linkPreview.textContent = url;
      if (qr) {
        qrEl.innerHTML = '';
      }
      qr = new QRCode(qrEl, { text: url, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
    }

    ipInput.addEventListener('input', refresh);
    refresh();
  </script>
</body>
</html>

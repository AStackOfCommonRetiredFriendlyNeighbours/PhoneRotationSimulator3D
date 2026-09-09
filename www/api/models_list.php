<?php
// Scans www/assets for .glb files so the viewer's model switcher builds
// itself — dropping a new model file in there is enough, no code change.

header('Content-Type: application/json');

$assetsDir = __DIR__ . '/../assets';
$files = glob($assetsDir . '/*.glb') ?: [];
natsort($files);

$models = [];
foreach ($files as $path) {
  $filename = basename($path);
  $id = pathinfo($filename, PATHINFO_FILENAME);
  $label = ucwords(str_replace(['_', '-'], ' ', $id));
  $models[] = [
    'id' => $id,
    'label' => $label,
    'url' => 'assets/' . $filename,
  ];
}

echo json_encode(array_values($models));

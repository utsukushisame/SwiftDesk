<?php
// Simple JSON storage backend for Digital Board
// Prevents race conditions with file locking

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$dataFile = 'data.json';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = file_get_contents('php://input');
$newData = json_decode($input, true);

if (!$newData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Open file for writing
$fp = fopen($dataFile, 'c+');

if (flock($fp, LOCK_EX)) { // Exclusive lock
    // Read current data to check for conflicts (optional but recommended)
    $currentSize = filesize($dataFile);
    $currentContent = $currentSize > 0 ? fread($fp, $currentSize) : null;
    $currentData = $currentContent ? json_decode($currentContent, true) : null;

    // Here you could implement server-side conflict checking if needed
    // e.g., if ($currentData['updatedAt'] > $newData['updatedAt']) ...
    // But the frontend already handles optimistic locking via loadData checking.
    // We will just overwrite here as the "source of truth".
    
    // Truncate file and write new data
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN); // Release lock
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Could not lock file']);
    exit;
}

fclose($fp);

echo json_encode(['success' => true, 'timestamp' => time()]);
?>

<?php
/**
 * Single-File Kanban Backend pre NAS (PHP 7.4+)
 * 
 * Funkcionalita:
 * - Ukladanie dát do data.json
 * - Atomický zápis pomocou temp súboru a rename()
 * - Zamykanie pomocou flock() nad data.lock (neblokuje čítanie dát)
 * - Optimistic Locking (kontrola verzií)
 * - ETag Polling (304 Not Modified)
 * - Automatické zálohovanie (rotácia 50 súborov)
 */

// Nastavenia
define('DATA_FILE', 'data.json');
define('LOCK_FILE', 'data.lock');
define('BACKUP_DIR', 'backups');
define('MAX_BACKUPS', 50);

// Hlavičky pre JSON API a CORS (ak by bolo treba, hoci single-file je same-origin)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    // Inicializácia adresárov a súborov
    if (!file_exists(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0775, true);
    }
    if (!file_exists(LOCK_FILE)) {
        touch(LOCK_FILE);
        chmod(LOCK_FILE, 0775);
    }
    // Inicializačná štruktúra dát, ak neexistujú
    if (!file_exists(DATA_FILE)) {
        $initialData = [
            'version' => 1,
            'locks' => [],
            'columns' => [
                'todo' => ['title' => 'To Do', 'cards' => []],
                'progress' => ['title' => 'In Progress', 'cards' => []],
                'done' => ['title' => 'Done', 'cards' => []]
            ]
        ];
        file_put_contents(DATA_FILE, json_encode($initialData, JSON_PRETTY_PRINT));
        chmod(DATA_FILE, 0775);
    }

    $action = $_GET['action'] ?? '';

    // --- SPRACOVANIE REQUESTOV ---

    if ($action === 'load') {
        handleLoad();
    } elseif ($action === 'save') {
        handleSave();
    } elseif ($action === 'lock') {
        handleLock();
    } elseif ($action === 'health') {
        echo json_encode(['status' => 'ok', 'writeABLE' => is_writable(DATA_FILE) && is_writable(dirname(DATA_FILE))]);
    } else {
        throw new Exception("Neplatná akcia.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * 1. LOAD: Načíta dáta s podporou ETag
 */
function handleLoad() {
    // Čítanie nevyžaduje exkluzívny zámok na data.lock, pretože rename() pri zápise je atomický.
    // Buď prečítame starý súbor alebo nový.
    
    if (!file_exists(DATA_FILE)) {
        echo json_encode([]);
        return;
    }

    // Vyčistenie expirovaných zámkov pri čítaní (voliteľné, ale dobré pre údržbu)
    // Pozor: Pre úplnú korektnosť by sme mali zamknúť LOCK_FILE ak chceme modifikovať zámky.
    // Pre LOAD radšej len čítame, čistenie necháme na WRITE operácie alebo dedikovaný cron/trigger,
    // aby sme nespomaľovali read requesty blokovaním.
    
    $content = file_get_contents(DATA_FILE);
    $etag = md5($content);

    // Kontrola ETag z klienta (If-None-Match)
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        header("HTTP/1.1 304 Not Modified");
        exit;
    }

    header("ETag: \"$etag\"");
    echo $content;
}

/**
 * 2. SAVE: Atomický zápis s kontrolou verzií a zálohou
 */
function handleSave() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("SAVE vyžaduje POST.");

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception("Neplatný JSON input.");

    $clientUuid = $input['client_uuid'] ?? '';
    $expectedVersion = $input['expected_version'] ?? 0;
    $newData = $input['payload'] ?? null; // Celý data objekt

    if (!$newData || !isset($newData['columns'])) throw new Exception("Chýbajúce dáta.");

    // EXKLUZÍVNE ZAMKNUTIE (LOCK_EX)
    // Používame separátny lock súbor, aby sme neblokovali čítanie data.json
    $fp = fopen(LOCK_FILE, 'r+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        throw new Exception("Nepodarilo sa získať zámok. Skúste znova.");
    }

    try {
        // 1. Načítaj aktuálny stav (po získaní zámku, aby sme mali 'fresh' dáta)
        $currentContent = file_get_contents(DATA_FILE);
        $currentData = json_decode($currentContent, true);
        
        // Validácia integrity JSONu na disku
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Ak je súbor na disku poškodený, toto je kritické.
            // Backup by mal existovať. Tu by sme mohli pridať logiku na restore.
            throw new Exception("Kritická chyba: Súbor data.json je poškodený!");
        }

        // 2. Kontrola verzií (Optimistic Locking)
        if ($currentData['version'] > $expectedVersion) {
            http_response_code(409); // Conflict
            echo json_encode([
                'error' => 'Conflict',
                'message' => 'Dáta boli zmenené iným používateľom. Prosím, načítajte novú verziu.',
                'server_version' => $currentData['version'],
                'client_version' => $expectedVersion
            ]);
            return; // Končíme, nezapisujeme
        }

        // 3. Vytvorenie zálohy
        createBackup($currentContent);

        // 4. Inkrement verzie a príprava nových dát
        // Zachováme zámky od iných, ak sú, alebo ich prepočítame?
        // Pri SAVE celého state-u z klienta musíme byť opatrní, aby klient neprepísal zámky iných,
        // o ktorých nevedel. Ale frontend posiela 'payload' vrátane locks.
        // Bezpečnejšie je spojiť locks zo servera (autoritatívne) s dátami od klienta.
        
        // Vyčistenie expirovaných zámkov
        $activeLocks = cleanupLocks($currentData['locks']);
        
        // Client posiela dáta. Prepíšeme verziu a zámky serverovou autoritou.
        $newData['version'] = $currentData['version'] + 1;
        $newData['locks'] = $activeLocks; // Zámky spravuje server/lock API, nie SAVE (zvyčajne) 
        // POZNÁMKA: Ak SAVE obsahuje aj odomknutie karty (user uložil), musíme to zohľadniť.
        // Dohoda: SAVE odomyká karty, ktoré editoval tento user.
        // Ale pre jednoduchosť a robustnosť: SAVE ukladá stĺpce/karty. Zámky ostanú, kým neexspirujú alebo kým ich action=lock (unlock) neodstráni.
        // Ak užívateľ práve uložil zmeny, front-end by mal poslať extra request na odomknutie, alebo to spravíme tu.
        // Pre 'Single-File' jednoduchosť predpokladajme, že 'save' len ukladá obsah boardu. 
        // (Teda 'locks' v payload od klienta ignorujeme a použijeme tie zo servera).
        
        // UPDATE: Ak klient uložil kartu, asi ju chce aj odomknúť.
        // Môžeme prejsť zámky a odstrániť tie, ktoré patria tomuto $clientUuid (ak chceme auto-unlock).
        // Pre teraz ponecháme locks tak ako sú na serveri (refreshnuté), frontend pošle seperátne unlock volanie ak treba.

        // 5. Zápis do TEMP súboru (Atomic Write pattern)
        $tempFile = 'temp_data.json';
        $jsonOutput = json_encode($newData, JSON_PRETTY_PRINT);
        
        if (file_put_contents($tempFile, $jsonOutput) === false) {
            throw new Exception("Nepodarilo sa zapísať do dočasného súboru.");
        }

        // 6. Validácia zapísaného JSONu
        $dataCheck = json_decode(file_get_contents($tempFile));
        if (json_last_error() !== JSON_ERROR_NONE) {
            unlink($tempFile);
            throw new Exception("Validácia JSON zlyhala pri zápise.");
        }

        // 7. Rename (Atomic switch)
        if (!rename($tempFile, DATA_FILE)) {
            unlink($tempFile);
            throw new Exception("Nepodarilo sa prepísať data.json.");
        }
        
        // Nastavenie práv pre istotu
        chmod(DATA_FILE, 0775);

        // Vrátime nové dáta a ETag
        $newEtag = md5($jsonOutput);
        header("ETag: \"$newEtag\"");
        echo json_encode($newData);

    } finally {
        // 8. Odomknutie
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * 3. LOCK: Manažment zámkov (Acquire / Renew / Release)
 * 
 * Volané s ?action=lock
 * POST parametre: client_uuid, card_id, release (bool)
 */
function handleLock() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("LOCK vyžaduje POST.");
    $input = json_decode(file_get_contents('php://input'), true);

    $clientUuid = $input['client_uuid'] ?? '';
    $cardId = $input['card_id'] ?? '';
    $release = $input['release'] ?? false; // true = odomknúť

    if (!$clientUuid || !$cardId) throw new Exception("Chýba UUID alebo Card ID.");

    // ZAMKNUTIE SÚBORU (potrebujeme modifikovať data.json kvôli zámkom)
    $fp = fopen(LOCK_FILE, 'r+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new Exception("Lock error.");

    try {
        $content = file_get_contents(DATA_FILE);
        $data = json_decode($content, true);
        
        // Vyčistenie starých zámkov
        $data['locks'] = cleanupLocks($data['locks'] ?? []);
        $locks = &$data['locks'];

        $now = time();
        $isLockedByOther = isset($locks[$cardId]) && $locks[$cardId]['user'] !== $clientUuid;

        if ($release) {
            // Odomknutie
            if (isset($locks[$cardId]) && $locks[$cardId]['user'] === $clientUuid) {
                unset($locks[$cardId]);
            }
        } else {
            // Zamknutie / Heartbeat
            if ($isLockedByOther) {
                // Konflikt - už je zamknuté iným
                http_response_code(423); // Locked
                echo json_encode(['error' => 'Karta je zamknutá iným používateľom.']);
                return;
            }
            
            // Vytvorenie alebo obnovenie zámku
            $locks[$cardId] = [
                'user' => $clientUuid,
                'expires' => $now + 60 // 60s expirácia
            ];
        }

        // Uloženie zmien (len locks, ale musíme zapísať celý súbor atomicky)
        // Verzia sa pri zmene zámkov NEINKREMENTUJE, pretože to nie je zmena dát obsahu (diskusné, ale frontend to zjednoduší)
        // ALE: ak sa polling spolieha na ETag, tak ak nezmeníme obsah, ETag sa nezmení a frontend nezíska nové zámky.
        // PRETO: Musíme zapísať súbor, ETag sa zmení, frontend refreshne a uvidí zámky. Verziu dát radšej neinkrementujeme, aby to nekolidovalo so SAVE contentom.
        // Alebo inkrementujeme? Ak inkrementujeme, SAVE bude failovať na 409 ak user len zamkol.
        // RIEŠENIE: Zmena zámkov NEMENÍ verziu dát 'content version', ale mení súbor => nový ETag => klienti reloadnú.
        
        $tempFile = 'temp_locks.json';
        $jsonOutput = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($tempFile, $jsonOutput);
        rename($tempFile, DATA_FILE);

        echo json_encode(['status' => 'ok', 'locks' => $data['locks']]);

    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Helper: Odstráni expirované zámky
 */
function cleanupLocks($locks) {
    $now = time();
    $newLocks = [];
    foreach ($locks as $id => $lock) {
        if ($lock['expires'] > $now) {
            $newLocks[$id] = $lock;
        }
    }
    return $newLocks;
}

/**
 * Helper: Vytvorí zálohu do backups/
 */
function createBackup($content) {
    $timestamp = date('Ymd_His');
    $backupFile = BACKUP_DIR . "/data_{$timestamp}.json";
    
    file_put_contents($backupFile, $content);
    
    // Rotácia záloh (ponechať len posledných 50)
    $files = glob(BACKUP_DIR . "/*.json");
    if (count($files) > MAX_BACKUPS) {
        // Zoradiť podľa času (najstaršie prvé)
        array_multisort(array_map('filemtime', $files), SORT_ASC, $files);
        $filesToDelete = array_slice($files, 0, count($files) - MAX_BACKUPS);
        foreach ($filesToDelete as $f) {
            unlink($f);
        }
    }
}

#!/usr/bin/env php
<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'root';
$db   = getenv('DB_NAME') ?: 'sindomondb';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Run migration: adapt existing tbl_polres to match spec
echo "  running migration: tbl_polres -> polres_id / nama_polres + FK\n";
$cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_polres'")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('polres_id', $cols)) {
    echo "    tbl_polres already migrated, skipping\n";
} else {
    $pdo->exec("ALTER TABLE `tbl_polres` CHANGE `id` `polres_id` INT(11) NOT NULL AUTO_INCREMENT");
    $pdo->exec("ALTER TABLE `tbl_polres` CHANGE `nama_polda` `nama_polres` VARCHAR(100) NOT NULL");
    $pdo->exec("ALTER TABLE `tbl_polres` DROP `created_at`");
    try {
        $pdo->exec("ALTER TABLE `tbl_polres` ADD CONSTRAINT `fk_polres_polda` FOREIGN KEY (`polda_id`) REFERENCES `tbl_polda`(`id`) ON DELETE RESTRICT");
    } catch (PDOException $fkErr) {
        if (strpos($fkErr->getMessage(), 'Duplicate') === false && strpos($fkErr->getMessage(), 'already exists') === false) {
            throw $fkErr;
        }
    }
    echo "    migration applied\n";
}

// Ensure roles exist
$pdo->exec("INSERT IGNORE INTO tbl_role (id, roles, created_at) VALUES (1, 'Super Admin', NOW())");
$pdo->exec("INSERT IGNORE INTO tbl_role (id, roles, created_at) VALUES (2, 'Operator Polda', NOW())");

// Ensure at least one polda exists for testing
$poldaCount = $pdo->query("SELECT COUNT(*) as c FROM tbl_polda")->fetch()['c'];
if ($poldaCount === 0) {
    $pdo->exec("INSERT INTO tbl_polda (id, nama_polda, created_at) VALUES (1, 'POLDA TEST', NOW())");
    echo "  test polda seeded\n";
} else {
    echo "  tbl_polda already has $poldaCount row(s)\n";
}

// Ensure admin user exists (roles_id=1)
$adminExists = $pdo->query("SELECT COUNT(*) as c FROM tbl_users WHERE username = 'admin'")->fetch()['c'];
if ($adminExists === 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO tbl_users (username, password, roles_id, polda_id, uuid, token, expired, created_at)
        VALUES ('admin', " . $pdo->quote($hash) . ", 1, 1, UUID(), 'admintoken', '30', NOW())");
    echo "  admin user seeded\n";
} else {
    echo "  admin user already exists\n";
}

// Clean & re-seed operator test user
$pdo->exec("DELETE FROM tbl_users WHERE username = 'operator_test'");
$hash = password_hash('operator123', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO tbl_users (username, password, roles_id, polda_id, uuid, token, expired, created_at)
    VALUES ('operator_test', " . $pdo->quote($hash) . ", 2, 12, UUID(), 'testtoken', '30', NOW())");
echo "  test user 'operator_test' seeded\n";

// Optional seeds — skip if tables don't exist
foreach ([
    'tbl_pangkat (pangkat_id, nama_pangkat) VALUES (1, \'BRIPDA\')',
    'tbl_pangkat (pangkat_id, nama_pangkat) VALUES (2, \'BRIPTU\')',
] as $sql) {
    try { $pdo->exec("INSERT IGNORE INTO $sql"); } catch (PDOException $e) {}
}
foreach ([
    'tbl_jabatan (jabatan_id, nama_jabatan, formasi_ideal, parent_id) VALUES (1, \'Staff\', 10, NULL)',
    'tbl_jabatan (jabatan_id, nama_jabatan, formasi_ideal, parent_id) VALUES (2, \'Kanit\', 2, 1)',
] as $sql) {
    try { $pdo->exec("INSERT IGNORE INTO $sql"); } catch (PDOException $e) {}
}
try {
    $pdo->exec("DELETE FROM tbl_proses_hukum WHERE personil_id IN (SELECT personil_id FROM (SELECT personil_id FROM tbl_personil WHERE nrp LIKE '88______') t)");
    $pdo->exec("DELETE FROM tbl_personil WHERE nrp LIKE '88______'");
    $pdo->exec("INSERT IGNORE INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id)
        VALUES ('00000000-0000-0000-0000-00000000dead', '99999999', 'TRAP_PERSONIL_POLDA15', 1, 1, 'Aktif', 15, NULL)");
    echo "  cross-region trap personil seeded (polda_id=15)\n";
} catch (PDOException $e) {
    echo "  SKIP: personil/pangkat/jabatan tables not available\n";
}

echo "\nSeed complete.\n";

<?php

/**
 * Verify MySQL credentials from a Laravel .env file (run on the server via deploy).
 * Usage: php db-precheck.php /path/to/.env
 */

$envFile = $argv[1] ?? null;

if ($envFile === null || ! is_readable($envFile)) {
    fwrite(STDERR, "ERROR: .env not readable: ".($envFile ?? '(missing path)').PHP_EOL);
    exit(1);
}

$vars = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line, "\r\n");
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value, " \t\"'");
}

$host = $vars['DB_HOST'] ?? '127.0.0.1';
$port = $vars['DB_PORT'] ?? '3306';
$database = $vars['DB_DATABASE'] ?? '';
$username = $vars['DB_USERNAME'] ?? '';
$password = $vars['DB_PASSWORD'] ?? '';

if ($database === '' || $username === '') {
    fwrite(STDERR, "ERROR: DB_DATABASE and DB_USERNAME must be set in $envFile".PHP_EOL);
    exit(1);
}

echo "  DB_HOST=$host  DB_PORT=$port  DB_DATABASE=$database  DB_USERNAME=$username".PHP_EOL;

$hosts = array_values(array_unique([$host, '127.0.0.1', 'localhost']));
$connected = false;

foreach ($hosts as $h) {
    try {
        $pdo = new PDO(
            "mysql:host={$h};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_TIMEOUT => 5]
        );
        echo 'DB OK via host: '.$h.PHP_EOL;
        $connected = true;
        break;
    } catch (PDOException $e) {
        echo 'FAILED ('.$h.'): '.$e->getMessage().PHP_EOL;
    }
}

if (! $connected) {
    echo PHP_EOL;
    echo 'ERROR: Could not connect to MySQL with any host variant.'.PHP_EOL;
    echo 'Fix options:'.PHP_EOL;
    echo "  1. Verify DB_USERNAME/DB_PASSWORD in $envFile".PHP_EOL;
    echo "  2. In CyberPanel: confirm the DB user has GRANT on $database".PHP_EOL;
    echo '  3. Try DB_HOST=127.0.0.1 instead of localhost in .env'.PHP_EOL;
    exit(1);
}

exit(0);

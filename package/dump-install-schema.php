<?php

/**
 * Refresh database/schema/mysql-schema.sql from a throwaway migrated MySQL database.
 *
 * Usage:
 *   php package/rebuild-install-schema.php
 *   php package/dump-install-schema.php --database=NAME --prefix=PREFIX_
 */

$opts = getopt('', ['host::', 'port::', 'user::', 'password::', 'database:', 'prefix:', 'out::']);
$host = $opts['host'] ?? '127.0.0.1';
$port = (int) ($opts['port'] ?? 3306);
$user = $opts['user'] ?? 'root';
$pass = $opts['password'] ?? '';
$db = $opts['database'] ?? '';
$prefixRaw = $opts['prefix'] ?? '';
$out = $opts['out'] ?? dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'schema'.DIRECTORY_SEPARATOR.'mysql-schema.sql';

if ($db === '' || $prefixRaw === '') {
    fwrite(STDERR, "Required: --database=... --prefix=WENTO_ (source table prefix)\n");
    exit(1);
}

$prefix = rtrim($prefixRaw, '_').'_';
$token = '__DB_PREFIX__';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$tables = [];
foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $row) {
    $tables[] = $row[0];
}
sort($tables);

// Match real table prefix case (MySQL may keep WENTO_ while we asked for wento_).
$migGuess = null;
foreach ($tables as $table) {
    if (preg_match('/^(.+_)migrations$/i', $table, $m)) {
        $migGuess = $m[1];
        break;
    }
}
if ($migGuess !== null) {
    $prefix = $migGuess;
}

$lines = [];
$lines[] = '-- WentoCart fresh-install schema (auto-generated).';
$lines[] = '-- Placeholder __DB_PREFIX__ is replaced by InstallerService with the merchant prefix.';
$lines[] = '-- Regenerate: php package/dump-install-schema.php --database=NAME --prefix=PREFIX_';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
$lines[] = 'SET UNIQUE_CHECKS=0;';
$lines[] = 'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';';
$lines[] = '';

foreach ($tables as $table) {
    $create = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
    $sql = array_values($create)[1] ?? '';
    $tokenTable = preg_replace('/^'.preg_quote($prefix, '/').'/i', $token, $table);
    $sql = preg_replace('/^CREATE TABLE `/i', 'CREATE TABLE IF NOT EXISTS `', $sql);
    $sql = preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $sql);
    $sql = str_ireplace('`'.$prefix, '`'.$token, $sql);
    $sql = str_ireplace($prefix, $token, $sql);
    $lines[] = 'DROP TABLE IF EXISTS `'.$tokenTable.'`;';
    $lines[] = $sql.';';
    $lines[] = '';
}

$lines[] = 'SET UNIQUE_CHECKS=1;';
$lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

$dir = dirname($out);
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($out, implode("\n", $lines)."\n");
echo 'Wrote '.$out.' ('.count($tables).' tables, '.filesize($out)." bytes)\n";

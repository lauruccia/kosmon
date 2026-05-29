<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php deploy/export_sqlite_full_mysql.php <sqlite-file> <mysql-sql-output>\n");
    exit(1);
}

[$script, $sqliteFile, $outputFile] = $argv;

if (! is_file($sqliteFile)) {
    fwrite(STDERR, "SQLite file not found: {$sqliteFile}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $sqliteFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = $pdo->query(
    "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"
)->fetchAll(PDO::FETCH_COLUMN);

$handle = fopen($outputFile, 'wb');

if (! $handle) {
    fwrite(STDERR, "Cannot open output file: {$outputFile}\n");
    exit(1);
}

fwrite($handle, "-- Full MySQL dump generated from {$sqliteFile}\n");
fwrite($handle, "-- Includes table structure and data.\n\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

foreach (array_reverse($tables) as $table) {
    fwrite($handle, 'DROP TABLE IF EXISTS `' . escapeIdentifier($table) . "`;\n");
}

fwrite($handle, "\n");

foreach ($tables as $table) {
    writeCreateTable($handle, $pdo, $table);
    writeInsertRows($handle, $pdo, $table);
}

ensureSessionsMigrationIsMarked($handle, $pdo);

fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

echo "Exported full MySQL dump with " . count($tables) . " tables to {$outputFile}\n";

function writeCreateTable($handle, PDO $pdo, string $table): void
{
    $columns = getColumns($pdo, $table);
    $definitions = [];
    $primaryColumns = [];

    foreach ($columns as $column) {
        $name = $column['name'];
        $type = strtolower((string) $column['type']);
        $isPrimary = (int) $column['pk'] > 0;
        $nullable = (int) $column['notnull'] === 0 && ! $isPrimary;
        $default = $column['dflt_value'];

        if ($isPrimary) {
            $primaryColumns[(int) $column['pk']] = $name;
        }
    }

    $hasSingleIntegerPrimaryKey = count($primaryColumns) === 1;

    foreach ($columns as $column) {
        $name = $column['name'];
        $type = strtolower((string) $column['type']);
        $isPrimary = (int) $column['pk'] > 0;
        $nullable = (int) $column['notnull'] === 0 && ! $isPrimary;
        $default = $column['dflt_value'];

        $definition = '`' . escapeIdentifier($name) . '` ' . mysqlType($type, $isPrimary);

        if ($hasSingleIntegerPrimaryKey && $isPrimary && str_contains($type, 'integer')) {
            $definition .= ' NOT NULL AUTO_INCREMENT';
        } else {
            $mysqlType = mysqlType($type, false);
            $definition .= $nullable ? ' NULL' : ' NOT NULL';
            $definition .= mysqlDefault($default, $type, $mysqlType);
        }

        $definitions[] = $definition;
    }

    ksort($primaryColumns);

    if ($primaryColumns !== []) {
        $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(fn ($name) => '`' . escapeIdentifier($name) . '`', $primaryColumns)) . ')';
    }

    fwrite($handle, 'CREATE TABLE `' . escapeIdentifier($table) . "` (\n  " . implode(",\n  ", $definitions) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");

    foreach (getIndexes($pdo, $table) as $indexSql) {
        fwrite($handle, $indexSql . "\n");
    }

    fwrite($handle, "\n");
}

function writeInsertRows($handle, PDO $pdo, string $table): void
{
    $columns = array_column(getColumns($pdo, $table), 'name');

    if ($columns === []) {
        return;
    }

    $columnSql = implode(', ', array_map(fn ($column) => '`' . escapeIdentifier($column) . '`', $columns));
    $selectSql = 'SELECT ' . implode(', ', array_map(fn ($column) => '"' . str_replace('"', '""', $column) . '"', $columns)) . ' FROM "' . str_replace('"', '""', $table) . '"';
    $rows = $pdo->query($selectSql);
    $count = 0;

    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map('mysqlLiteral', array_values($row));
        fwrite($handle, 'INSERT INTO `' . escapeIdentifier($table) . "` ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n");
        $count++;
    }

    fwrite($handle, "-- {$table}: {$count} rows\n\n");
}

function getColumns(PDO $pdo, string $table): array
{
    return $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll(PDO::FETCH_ASSOC);
}

function getIndexes(PDO $pdo, string $table): array
{
    $indexes = [];
    $indexList = $pdo->query('PRAGMA index_list("' . str_replace('"', '""', $table) . '")')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($indexList as $index) {
        $name = (string) $index['name'];

        if (str_starts_with($name, 'sqlite_autoindex_')) {
            continue;
        }

        $indexColumns = $pdo->query('PRAGMA index_info("' . str_replace('"', '""', $name) . '")')->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($indexColumns, 'name');

        if ($columns === []) {
            continue;
        }

        $prefix = ((int) $index['unique'] === 1) ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ';
        $indexName = shortenIdentifier($name);
        $indexes[] = $prefix . '`' . escapeIdentifier($indexName) . '` ON `' . escapeIdentifier($table) . '` (' .
            implode(', ', array_map(fn ($column) => '`' . escapeIdentifier($column) . '`', $columns)) . ');';
    }

    return $indexes;
}

function mysqlType(string $sqliteType, bool $isPrimary): string
{
    if ($isPrimary && str_contains($sqliteType, 'integer')) {
        return 'BIGINT UNSIGNED';
    }

    if (str_contains($sqliteType, 'tinyint')) {
        return 'TINYINT(1)';
    }

    if (str_contains($sqliteType, 'integer')) {
        return 'BIGINT';
    }

    if (str_contains($sqliteType, 'datetime')) {
        return 'DATETIME';
    }

    if (str_contains($sqliteType, 'date')) {
        return 'DATE';
    }

    if (str_contains($sqliteType, 'numeric') || str_contains($sqliteType, 'decimal')) {
        return 'DECIMAL(12,2)';
    }

    if (str_contains($sqliteType, 'text')) {
        return 'LONGTEXT';
    }

    return 'VARCHAR(255)';
}

function mysqlDefault(mixed $default, string $sqliteType, string $mysqlType): string
{
    if ($default === null) {
        return '';
    }

    if (str_contains(strtolower($mysqlType), 'text')) {
        return '';
    }

    $default = trim((string) $default);

    if ($default === '' || strcasecmp($default, 'NULL') === 0) {
        return '';
    }

    if (strcasecmp($default, 'CURRENT_TIMESTAMP') === 0) {
        return ' DEFAULT CURRENT_TIMESTAMP';
    }

    $unquoted = trim($default, "'\"");

    if (preg_match('/^-?\d+(\.\d+)?$/', $unquoted)) {
        return ' DEFAULT ' . $unquoted;
    }

    return ' DEFAULT ' . mysqlLiteral($unquoted);
}

function ensureSessionsMigrationIsMarked($handle, PDO $pdo): void
{
    if (! in_array('migrations', $pdo->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)) {
        return;
    }

    $exists = (bool) $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE migration = '0001_01_01_000003_create_sessions_table'")
        ->execute();

    fwrite($handle, "INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000003_create_sessions_table', 1);\n\n");
}

function mysqlLiteral(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . strtr((string) $value, [
        "\\" => "\\\\",
        "'" => "\\'",
        "\0" => "\\0",
        "\n" => "\\n",
        "\r" => "\\r",
        "\x1a" => "\\Z",
    ]) . "'";
}

function escapeIdentifier(string $value): string
{
    return str_replace('`', '``', $value);
}

function shortenIdentifier(string $value): string
{
    if (strlen($value) <= 64) {
        return $value;
    }

    return substr($value, 0, 55) . '_' . substr(sha1($value), 0, 8);
}

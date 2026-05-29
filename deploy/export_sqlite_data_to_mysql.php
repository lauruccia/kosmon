<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php deploy/export_sqlite_data_to_mysql.php <sqlite-file> <mysql-sql-output>\n");
    exit(1);
}

[$script, $sqliteFile, $outputFile] = $argv;

if (! is_file($sqliteFile)) {
    fwrite(STDERR, "SQLite file not found: {$sqliteFile}\n");
    exit(1);
}

$excludedTables = [
    'cache',
    'cache_locks',
    'failed_jobs',
    'job_batches',
    'jobs',
    'migrations',
    'password_reset_tokens',
    'sessions',
];

$pdo = new PDO('sqlite:' . $sqliteFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = $pdo->query(
    "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"
)->fetchAll(PDO::FETCH_COLUMN);

$tables = array_values(array_filter($tables, fn ($table) => ! in_array($table, $excludedTables, true)));

$handle = fopen($outputFile, 'wb');

if (! $handle) {
    fwrite(STDERR, "Cannot open output file: {$outputFile}\n");
    exit(1);
}

fwrite($handle, "-- Data-only MySQL dump generated from {$sqliteFile}\n");
fwrite($handle, "-- Import this after running Laravel migrations on the live database.\n\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

foreach (array_reverse($tables) as $table) {
    fwrite($handle, 'DELETE FROM `' . str_replace('`', '``', $table) . "`;\n");
}

fwrite($handle, "\n");

foreach ($tables as $table) {
    $quotedTable = '"' . str_replace('"', '""', $table) . '"';
    $columns = $pdo->query("PRAGMA table_info({$quotedTable})")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');

    if ($columnNames === []) {
        continue;
    }

    $columnSql = implode(', ', array_map(fn ($column) => '`' . str_replace('`', '``', $column) . '`', $columnNames));
    $selectSql = 'SELECT ' . implode(', ', array_map(fn ($column) => '"' . str_replace('"', '""', $column) . '"', $columnNames)) . " FROM {$quotedTable}";
    $rows = $pdo->query($selectSql);
    $count = 0;

    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map('mysqlLiteral', array_values($row));
        fwrite($handle, 'INSERT INTO `' . str_replace('`', '``', $table) . "` ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n");
        $count++;
    }

    fwrite($handle, "-- {$table}: {$count} rows\n\n");
}

fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

echo "Exported " . count($tables) . " tables to {$outputFile}\n";

function mysqlLiteral(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $value = (string) $value;

    if ($value === '') {
        return "''";
    }

    return "'" . strtr($value, [
        "\\" => "\\\\",
        "'" => "\\'",
        "\0" => "\\0",
        "\n" => "\\n",
        "\r" => "\\r",
        "\x1a" => "\\Z",
    ]) . "'";
}

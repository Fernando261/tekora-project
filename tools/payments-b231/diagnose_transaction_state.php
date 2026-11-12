<?php

declare(strict_types=1);

// Standalone PDO/SQLite probe: no PHPUnit, application boot, file DB or fork.
$results = [];
foreach (['pdo', 'BEGIN', 'BEGIN IMMEDIATE', 'BEGIN EXCLUSIVE', 'SAVEPOINT probe'] as $mode) {
    $p = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec('CREATE TABLE probe(value INTEGER)');
    if ($mode === 'pdo') {
        $p->beginTransaction();
    } else {
        $p->exec($mode);
    }
    $tracked = $p->inTransaction();
    $p->exec('INSERT INTO probe VALUES(1)');
    $secondBegin = null;
    try {
        $p->exec('BEGIN IMMEDIATE');
    } catch (PDOException $e) {
        $secondBegin = $e->errorInfo[1];
    }
    if ($mode === 'pdo') {
        $p->rollBack();
    } else {
        $p->exec('ROLLBACK');
    }
    $results[] = ['mode' => $mode, 'pdo_in_transaction' => $tracked, 'second_begin_sqlite_code' => $secondBegin, 'rows_after_rollback' => $p->query('SELECT count(*) FROM probe')->fetchColumn(), 'pdo_after_rollback' => $p->inTransaction()];
}
$p = new PDO('sqlite::memory:');
echo json_encode(['php' => PHP_VERSION, 'pdo_sqlite_extension' => phpversion('pdo_sqlite'), 'sqlite' => $p->query('SELECT sqlite_version()')->fetchColumn(), 'sqlite_source_id' => $p->query('SELECT sqlite_source_id()')->fetchColumn(), 'phpunit_loaded' => class_exists('PHPUnit\\Framework\\TestCase', false), 'cases' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";

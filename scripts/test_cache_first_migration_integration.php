<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function m024_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function m024_batches(PDO $db, string $sql): void
{
    $sql = preg_replace('/^\s*SET\s+XACT_ABORT\s+ON\s*;?\s*$/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*(BEGIN|COMMIT)\s+TRANSACTION\s*;?\s*$/mi', '', $sql) ?? $sql;
    foreach (preg_split('/^\s*GO\s*$/mi', $sql) ?: [] as $batch) {
        if (trim($batch) !== '') {
            $db->exec($batch);
        }
    }
}

function m024_fixture(PDO $db, string $schema, string $role): void
{
    $db->exec("CREATE SCHEMA [$schema]");
    $db->exec("CREATE ROLE [$role]");
    $db->exec("CREATE TABLE [$schema].schema_migrations(
        migration_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        script_name NVARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME2(3) NOT NULL DEFAULT(SYSUTCDATETIME()),
        applied_by NVARCHAR(256) NOT NULL DEFAULT(SUSER_SNAME()),
        checksum_sha256 CHAR(64) NULL
    )");
    $db->exec("CREATE TABLE [$schema].documents(
        document_id BIGINT NOT NULL PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL,
        relative_path NVARCHAR(512) NOT NULL,
        content_hash CHAR(64) NULL,
        source_modified_at DATETIME2(3) NOT NULL
    )");
    $hash = hash('sha256', 'fixture-source');
    $statement = $db->prepare("INSERT INTO [$schema].documents(document_id,public_id,relative_path,content_hash,source_modified_at)
        VALUES(1,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',N'網站文件/fixture.txt',?,'2026-08-30T01:00:00')");
    $statement->execute([$hash]);
}

function m024_apply(PDO $db, string $migration, string $schema, string $role): void
{
    $fixtureSql = str_replace(['dbo.', 'TWWATER_PORTAL_APP'], ['[' . $schema . '].', $role], $migration);
    m024_batches($db, $fixtureSql);
}

function m024_verify(PDO $db, string $schema, string $role): void
{
    $object = $schema . '.documents';
    $query = $db->prepare("SELECT c.name,t.name AS type_name,c.max_length,c.scale,c.is_nullable
        FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id
        WHERE c.object_id=OBJECT_ID(?) AND c.name LIKE 'writeback_%' OR c.object_id=OBJECT_ID(?) AND c.name IN('source_content_hash','working_modified_at')
        ORDER BY c.name");
    $query->execute([$object, $object]);
    $actual = [];
    foreach ($query->fetchAll() as $row) {
        $actual[$row['name']] = [$row['type_name'], (int)$row['max_length'], (int)$row['scale'], (int)$row['is_nullable']];
    }
    $expected = [
        'source_content_hash' => ['char',64,0,1],
        'working_modified_at' => ['datetime2',8,3,0],
        'writeback_due_at' => ['datetime2',8,3,1],
        'writeback_error_code' => ['varchar',64,0,1],
        'writeback_generation' => ['uniqueidentifier',16,0,1],
        'writeback_observed_source_hash' => ['char',64,0,1],
        'writeback_status' => ['varchar',8,0,0],
        'writeback_updated_at' => ['datetime2',8,3,0],
    ];
    m024_assert($actual === $expected, 'Migration 024 column metadata mismatch.');

    $query = $db->prepare("SELECT name,is_disabled,is_not_trusted FROM sys.check_constraints
        WHERE parent_object_id=OBJECT_ID(?) AND name LIKE 'CK_documents_writeback_%' OR parent_object_id=OBJECT_ID(?) AND name='CK_documents_source_content_hash'");
    $query->execute([$object, $object]);
    $checks = $query->fetchAll();
    m024_assert(count($checks) === 5, 'Migration 024 check count mismatch.');
    foreach ($checks as $check) {
        m024_assert((int)$check['is_disabled'] === 0 && (int)$check['is_not_trusted'] === 0, 'Migration 024 check must be enabled and trusted.');
    }

    $query = $db->prepare("SELECT has_filter,filter_definition FROM sys.indexes WHERE object_id=OBJECT_ID(?) AND name='IX_documents_writeback_due'");
    $query->execute([$object]);
    $index = $query->fetch();
    m024_assert(is_array($index) && (int)$index['has_filter'] === 1, 'Write-back due index must be filtered.');
    m024_assert(str_contains((string)$index['filter_definition'], '[writeback_status]'), 'Write-back due index filter mismatch.');

    $row = $db->query("SELECT writeback_status,content_hash,source_content_hash,working_modified_at,source_modified_at,writeback_generation,writeback_due_at,writeback_error_code,writeback_observed_source_hash FROM [$schema].documents WHERE document_id=1")->fetch();
    m024_assert(is_array($row) && $row['writeback_status'] === 'synced', 'Existing row was not seeded as synced.');
    m024_assert(hash_equals((string)$row['content_hash'], (string)$row['source_content_hash']), 'Existing source baseline mismatch.');
    m024_assert((string)$row['working_modified_at'] === (string)$row['source_modified_at'], 'Existing working time seed mismatch.');

    $invalidRejected = false;
    try {
        $db->exec("UPDATE [$schema].documents SET writeback_status='pending' WHERE document_id=1");
    } catch (Throwable) {
        $invalidRejected = true;
    }
    m024_assert($invalidRejected, 'Incomplete pending state must be rejected.');

    $working = hash('sha256', 'fixture-working');
    $statement = $db->prepare("UPDATE [$schema].documents SET
        content_hash=?,writeback_status='pending',writeback_generation='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        writeback_due_at='2026-08-30T02:00:00',working_modified_at='2026-08-30T01:30:00',writeback_updated_at='2026-08-30T01:30:00'
        WHERE document_id=1");
    $statement->execute([$working]);
    m024_assert($statement->rowCount() === 1, 'Valid pending state was not accepted.');

    $testUser = 'u_' . $schema;
    $db->exec("CREATE USER [$testUser] WITHOUT LOGIN");
    $db->exec("ALTER ROLE [$role] ADD MEMBER [$testUser]");
    $db->exec("EXECUTE AS USER='$testUser'");
    try {
        foreach (['SELECT','UPDATE'] as $permission) {
            $query = $db->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', ?)");
            $query->execute([$object, $permission]);
            m024_assert((int)$query->fetchColumn() === 1, "$permission permission missing.");
        }
        $query = $db->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'DELETE')");
        $query->execute([$object]);
        m024_assert((int)$query->fetchColumn() === 0, 'DELETE must be denied.');
    } finally {
        $db->exec('REVERT');
    }

    $query = $db->prepare("SELECT COUNT(*) FROM [$schema].schema_migrations WHERE script_name='024_cache_first_documents.sql'");
    $query->execute();
    m024_assert((int)$query->fetchColumn() === 1, 'Migration ledger row mismatch.');
}

$db = portal_open_database(portal_config(false));
$databaseName = (string)$db->query('SELECT DB_NAME()')->fetchColumn();
m024_assert($databaseName !== '' && !in_array(strtolower($databaseName), ['master','model','msdb','tempdb'], true), 'Refusing to test against a system database.');
$migration = (string)file_get_contents(dirname(__DIR__) . '/database/024_cache_first_documents.sql');
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$freshSchema = 'm024f_' . $suffix;
$freshRole = 'r_' . $freshSchema;

$db->beginTransaction();
try {
    m024_fixture($db, $freshSchema, $freshRole);
    m024_apply($db, $migration, $freshSchema, $freshRole);
    m024_apply($db, $migration, $freshSchema, $freshRole);
    m024_verify($db, $freshSchema, $freshRole);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$query = $db->prepare("SELECT (SELECT COUNT(*) FROM sys.schemas WHERE name=?)+(SELECT COUNT(*) FROM sys.database_principals WHERE name IN(?,?))");
$query->execute([$freshSchema, $freshRole, 'u_' . $freshSchema]);
m024_assert((int)$query->fetchColumn() === 0, 'Fresh fixture residue remains after rollback.');

$partialSchema = 'm024p_' . $suffix;
$partialRole = 'r_' . $partialSchema;
$partialRejected = false;
$db->beginTransaction();
try {
    m024_fixture($db, $partialSchema, $partialRole);
    $db->exec("ALTER TABLE [$partialSchema].documents ADD writeback_status VARCHAR(8) NULL");
    try {
        m024_apply($db, $migration, $partialSchema, $partialRole);
    } catch (Throwable) {
        $partialRejected = true;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
m024_assert($partialRejected, 'Partial schema must fail closed.');
$query = $db->prepare("SELECT (SELECT COUNT(*) FROM sys.schemas WHERE name=?)+(SELECT COUNT(*) FROM sys.database_principals WHERE name=?)");
$query->execute([$partialSchema, $partialRole]);
m024_assert((int)$query->fetchColumn() === 0, 'Partial fixture residue remains after rollback.');

echo "[OK] Migration 024 applied twice in fresh fixture, rejected partial schema, enforced state constraints and permissions, and rolled back with zero residue.\n";

<?php
declare(strict_types=1);

function cache_first_migration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '024_cache_first_documents.sql';
cache_first_migration_assert(is_file($path), 'Migration 024 is missing.');
$sql = (string) file_get_contents($path);
cache_first_migration_assert($sql !== '', 'Migration 024 is empty.');

foreach ([
    'writeback_status',
    'source_content_hash',
    'writeback_generation',
    'writeback_due_at',
    'writeback_error_code',
    'writeback_observed_source_hash',
    'working_modified_at',
    'writeback_updated_at',
    "IN ('synced','pending','conflict','failed')",
    'CK_documents_writeback_consistency',
    'CK_documents_source_content_hash',
    'CK_documents_writeback_observed_hash',
    'IX_documents_writeback_due',
    "024_cache_first_documents.sql",
    'Incomplete cache-first document schema',
    'Cache-first document column metadata mismatch',
    'TYPE_NAME(c.user_type_id)',
    'c.max_length',
    'c.scale',
    'c.is_nullable',
] as $required) {
    cache_first_migration_assert(str_contains($sql, $required), 'Migration contract missing: ' . $required);
}

cache_first_migration_assert(!str_contains($sql, 'CREATE TABLE dbo.document_working_'), 'Migration must not create a second document workflow table.');
cache_first_migration_assert(!str_contains($sql, 'ALTER TABLE dbo.image_requirement_revisions'), 'Migration must not mutate immutable image history.');
cache_first_migration_assert(!str_contains($sql, 'ALTER TABLE dbo.drive_change_packages'), 'Migration must not mutate immutable Drive change-package history.');
cache_first_migration_assert(str_contains($sql, "source_content_hash = content_hash"), 'Existing rows must seed their source baseline from the current cache hash.');
cache_first_migration_assert(str_contains($sql, "working_modified_at = source_modified_at"), 'Existing rows must seed working time without rewriting source time.');
cache_first_migration_assert(str_contains($sql, 'DENY DELETE ON dbo.documents'), 'Portal principal must not gain document DELETE permission.');

$rollbackPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '024_cache_first_documents_rollback.sql';
cache_first_migration_assert(is_file($rollbackPath), 'Migration 024 rollback is missing.');
$rollback = (string) file_get_contents($rollbackPath);
foreach ([
    "writeback_status<>'synced'",
    'Refusing rollback: unsynced website working copies exist',
    'DROP INDEX IX_documents_writeback_due',
    'DROP CONSTRAINT CK_documents_writeback_consistency',
    'DROP CONSTRAINT DF_documents_writeback_status',
    'DROP COLUMN writeback_status',
    "DELETE FROM dbo.schema_migrations WHERE script_name=N'024_cache_first_documents.sql'",
    'REVOKE DELETE ON dbo.documents',
] as $required) {
    cache_first_migration_assert(str_contains($rollback, $required), 'Rollback contract missing: ' . $required);
}

echo "[OK] Cache-first migration and rollback static contracts passed.\n";

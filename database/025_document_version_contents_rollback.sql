/* Idempotent pre-content rollback. After evidence exists, retain it and withdraw application usage instead. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO
IF OBJECT_ID(N'dbo.document_version_contents',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'IF EXISTS(SELECT 1 FROM dbo.document_version_contents) THROW 51045,''Refusing rollback: immutable document version bytes already exist; withdraw application usage without deleting evidence.'',1;';
IF COL_LENGTH(N'dbo.documents',N'current_version_id') IS NOT NULL
    EXEC sys.sp_executesql N'IF EXISTS(SELECT 1 FROM dbo.documents WHERE current_version_id IS NOT NULL) THROW 51046,''Refusing rollback: current-version bindings already exist; retain evidence and reconcile first.'',1;';
GO
IF OBJECT_ID(N'dbo.portal_prepare_document_version_capture',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_prepare_document_version_capture;
IF OBJECT_ID(N'dbo.portal_capture_current_document_version',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_capture_current_document_version;
IF OBJECT_ID(N'dbo.portal_mark_document_version_capture_failed',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_mark_document_version_capture_failed;
IF OBJECT_ID(N'dbo.portal_list_document_versions',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_list_document_versions;
IF OBJECT_ID(N'dbo.portal_read_document_version_content',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_read_document_version_content;
IF OBJECT_ID(N'dbo.portal_verify_document_version_contents',N'P') IS NOT NULL DROP PROCEDURE dbo.portal_verify_document_version_contents;
GO
IF OBJECT_ID(N'dbo.TR_document_version_contents_validate',N'TR') IS NOT NULL DROP TRIGGER dbo.TR_document_version_contents_validate;
IF OBJECT_ID(N'dbo.TR_document_version_contents_immutable',N'TR') IS NOT NULL DROP TRIGGER dbo.TR_document_version_contents_immutable;
GO
IF OBJECT_ID(N'dbo.documents',N'U') IS NOT NULL
BEGIN
 IF EXISTS(SELECT 1 FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'FK_documents_current_version') ALTER TABLE dbo.documents DROP CONSTRAINT FK_documents_current_version;
 IF EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.documents') AND name=N'IX_documents_current_version') DROP INDEX IX_documents_current_version ON dbo.documents;
 IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_consistency') ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_version_capture_consistency;
 IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_error') ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_version_capture_error;
 IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_status') ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_version_capture_status;
 IF EXISTS(SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_version_capture_updated') ALTER TABLE dbo.documents DROP CONSTRAINT DF_documents_version_capture_updated;
 IF EXISTS(SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_version_capture_status') ALTER TABLE dbo.documents DROP CONSTRAINT DF_documents_version_capture_status;
 IF COL_LENGTH(N'dbo.documents',N'current_version_id') IS NOT NULL ALTER TABLE dbo.documents DROP COLUMN current_version_id;
 IF COL_LENGTH(N'dbo.documents',N'version_capture_status') IS NOT NULL ALTER TABLE dbo.documents DROP COLUMN version_capture_status;
 IF COL_LENGTH(N'dbo.documents',N'version_capture_error_code') IS NOT NULL ALTER TABLE dbo.documents DROP COLUMN version_capture_error_code;
 IF COL_LENGTH(N'dbo.documents',N'version_capture_updated_at') IS NOT NULL ALTER TABLE dbo.documents DROP COLUMN version_capture_updated_at;
END;
GO
IF OBJECT_ID(N'dbo.document_version_contents',N'U') IS NOT NULL DROP TABLE dbo.document_version_contents;
IF OBJECT_ID(N'dbo.schema_migrations',N'U') IS NOT NULL DELETE FROM dbo.schema_migrations WHERE script_name=N'025_document_version_contents.sql';
GO
COMMIT TRANSACTION;
GO

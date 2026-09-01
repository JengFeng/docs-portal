/* Immutable, user-created IMAGE REVIEW requirement revisions. */
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
GO
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO
IF OBJECT_ID(N'dbo.documents',N'U') IS NULL OR OBJECT_ID(N'dbo.auth_users',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
    THROW 51023,'Run migrations 001 through 022 before 023_image_requirement_revisions.sql.',1;
GO
IF OBJECT_ID(N'dbo.image_requirement_revisions',N'U') IS NOT NULL
AND ((SELECT COUNT(*) FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.image_requirement_revisions'))<>16
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'image_requirement_revision_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'public_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'document_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'source_content_hash') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'revision_number') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'requirement_json') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'prompt_text') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'item_count') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'status_code') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'created_by_user_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'created_at') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'completed_by_user_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'completed_at') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'archived_by_user_id') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'archived_at') IS NULL
 OR COL_LENGTH(N'dbo.image_requirement_revisions',N'rowver') IS NULL)
    THROW 51024,'Existing image_requirement_revisions schema is partial or incompatible.',1;
GO
IF OBJECT_ID(N'dbo.image_requirement_revisions',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.image_requirement_revisions
    (
        image_requirement_revision_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_image_requirement_revisions PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_image_requirement_revisions_public_id DEFAULT(NEWID()),
        document_id BIGINT NOT NULL,
        source_content_hash CHAR(64) NOT NULL,
        revision_number INT NOT NULL,
        requirement_json NVARCHAR(MAX) NOT NULL,
        prompt_text NVARCHAR(MAX) NOT NULL,
        item_count INT NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_image_requirement_revisions_status DEFAULT('ready'),
        created_by_user_id BIGINT NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_requirement_revisions_created DEFAULT(SYSUTCDATETIME()),
        completed_by_user_id BIGINT NULL,
        completed_at DATETIME2(3) NULL,
        archived_by_user_id BIGINT NULL,
        archived_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_image_requirement_revisions_public_id UNIQUE(public_id),
        CONSTRAINT UQ_image_requirement_revisions_document_source_revision UNIQUE(document_id,source_content_hash,revision_number),
        CONSTRAINT FK_image_requirement_revisions_document FOREIGN KEY(document_id) REFERENCES dbo.documents(document_id),
        CONSTRAINT FK_image_requirement_revisions_creator FOREIGN KEY(created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_image_requirement_revisions_completer FOREIGN KEY(completed_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_image_requirement_revisions_archiver FOREIGN KEY(archived_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_image_requirement_revisions_source_hash CHECK(source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_image_requirement_revisions_revision CHECK(revision_number BETWEEN 1 AND 1000000),
        CONSTRAINT CK_image_requirement_revisions_json CHECK(ISJSON(requirement_json)=1 AND DATALENGTH(requirement_json) BETWEEN 2 AND 1048576),
        CONSTRAINT CK_image_requirement_revisions_item_count CHECK(item_count BETWEEN 1 AND 100),
        CONSTRAINT CK_image_requirement_revisions_status CHECK(status_code IN('ready','completed') AND ((status_code='ready' AND completed_by_user_id IS NULL AND completed_at IS NULL) OR (status_code='completed' AND completed_by_user_id IS NOT NULL AND completed_at IS NOT NULL))),
        CONSTRAINT CK_image_requirement_revisions_archive CHECK((archived_by_user_id IS NULL AND archived_at IS NULL) OR (archived_by_user_id IS NOT NULL AND archived_at IS NOT NULL))
    );
    CREATE INDEX IX_image_requirement_revisions_document_history ON dbo.image_requirement_revisions(document_id,created_at DESC,image_requirement_revision_id DESC) INCLUDE(public_id,source_content_hash,revision_number,item_count,status_code,archived_at);
    CREATE INDEX IX_image_requirement_revisions_current_source ON dbo.image_requirement_revisions(document_id,source_content_hash,archived_at,revision_number DESC) INCLUDE(public_id,item_count,status_code,created_at);
END;
GO
IF (SELECT COUNT(*) FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(N'dbo.image_requirement_revisions'))<>4
 OR (SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.image_requirement_revisions'))<>6
 OR NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_requirement_revisions') AND name=N'UQ_image_requirement_revisions_public_id' AND is_unique=1)
 OR NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_requirement_revisions') AND name=N'UQ_image_requirement_revisions_document_source_revision' AND is_unique=1)
 OR NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_requirement_revisions') AND name=N'IX_image_requirement_revisions_document_history')
 OR NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_requirement_revisions') AND name=N'IX_image_requirement_revisions_current_source')
    THROW 51025,'Existing image_requirement_revisions constraints or indexes are incompatible.',1;
GO
CREATE OR ALTER TRIGGER dbo.TR_image_requirement_revisions_immutable
ON dbo.image_requirement_revisions
AFTER UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted d LEFT JOIN inserted i ON i.image_requirement_revision_id=d.image_requirement_revision_id WHERE i.image_requirement_revision_id IS NULL)
        THROW 51026,'image requirement revisions cannot be physically deleted.',1;
    IF EXISTS(
        SELECT 1 FROM inserted i JOIN deleted d ON d.image_requirement_revision_id=i.image_requirement_revision_id
        WHERE i.public_id<>d.public_id OR i.document_id<>d.document_id OR i.source_content_hash<>d.source_content_hash
           OR i.revision_number<>d.revision_number OR i.requirement_json<>d.requirement_json OR i.prompt_text<>d.prompt_text
           OR i.item_count<>d.item_count OR i.created_by_user_id<>d.created_by_user_id OR i.created_at<>d.created_at
           OR (d.status_code='completed' AND i.status_code<>'completed')
           OR (d.completed_by_user_id IS NOT NULL AND (i.completed_by_user_id IS NULL OR i.completed_by_user_id<>d.completed_by_user_id))
           OR (d.completed_at IS NOT NULL AND (i.completed_at IS NULL OR i.completed_at<>d.completed_at))
    ) THROW 51027,'image requirement revision immutable content or completion state cannot change.',1;
END;
GO
IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT,INSERT,UPDATE ON dbo.image_requirement_revisions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.image_requirement_revisions TO [TWWATER_PORTAL_APP];
END;
GO
IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'023_image_requirement_revisions.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'023_image_requirement_revisions.sql',NULL);
GO
COMMIT TRANSACTION;
GO

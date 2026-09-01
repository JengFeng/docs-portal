SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.documents', N'U') IS NULL
   OR OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
   OR OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
    THROW 51022, 'Run earlier TWWATER migrations first.', 1;

IF OBJECT_ID(N'dbo.drive_change_packages', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.drive_change_packages
    (
        drive_change_package_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_drive_change_packages PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_drive_change_packages_public_id DEFAULT (NEWID()),
        document_id BIGINT NOT NULL,
        source_content_hash CHAR(64) NOT NULL,
        requirements_json NVARCHAR(MAX) NOT NULL,
        requirements_sha256 CHAR(64) NOT NULL,
        status_code VARCHAR(24) NOT NULL CONSTRAINT DF_drive_change_packages_status DEFAULT ('spec_ready'),
        candidate_content_hash CHAR(64) NULL,
        created_by_user_id BIGINT NOT NULL,
        confirmed_by_user_id BIGINT NULL,
        confirmed_at DATETIME2(3) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_drive_change_packages_created DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_drive_change_packages_updated DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_drive_change_packages_public_id UNIQUE (public_id),
        CONSTRAINT FK_drive_change_packages_document FOREIGN KEY (document_id) REFERENCES dbo.documents(document_id),
        CONSTRAINT FK_drive_change_packages_creator FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_drive_change_packages_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_drive_change_packages_source_hash CHECK (LEN(source_content_hash)=64 AND source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_drive_change_packages_requirements_hash CHECK (LEN(requirements_sha256)=64 AND requirements_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_drive_change_packages_candidate_hash CHECK (candidate_content_hash IS NULL OR (LEN(candidate_content_hash)=64 AND candidate_content_hash NOT LIKE '%[^0-9A-Fa-f]%')),
        CONSTRAINT CK_drive_change_packages_requirements CHECK (ISJSON(requirements_json)=1 AND DATALENGTH(requirements_json) BETWEEN 2 AND 524288),
        CONSTRAINT CK_drive_change_packages_status CHECK (status_code = 'spec_ready'),
        CONSTRAINT CK_drive_change_packages_spec_ready_consistency CHECK
            (status_code = 'spec_ready' AND candidate_content_hash IS NULL AND confirmed_by_user_id IS NULL AND confirmed_at IS NULL)
    );
END;
ELSE
BEGIN
    IF COL_LENGTH(N'dbo.drive_change_packages',N'drive_change_package_id') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'public_id') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'document_id') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'source_content_hash') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'requirements_json') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'requirements_sha256') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'status_code') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'candidate_content_hash') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'created_by_user_id') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'confirmed_by_user_id') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'confirmed_at') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'created_at') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'updated_at') IS NULL
       OR COL_LENGTH(N'dbo.drive_change_packages',N'rowver') IS NULL
        THROW 51023, 'Incomplete dbo.drive_change_packages schema; restore or repair it before migration 022.', 1;

    IF EXISTS (SELECT 1 FROM dbo.drive_change_packages WHERE status_code <> 'spec_ready'
        OR candidate_content_hash IS NOT NULL OR confirmed_by_user_id IS NOT NULL OR confirmed_at IS NOT NULL)
        THROW 51024, 'Existing drive change package state is incompatible with the spec_ready-only slice.', 1;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.drive_change_packages') AND name=N'CK_drive_change_packages_status')
        ALTER TABLE dbo.drive_change_packages DROP CONSTRAINT CK_drive_change_packages_status;
    ALTER TABLE dbo.drive_change_packages WITH CHECK ADD CONSTRAINT CK_drive_change_packages_status CHECK (status_code = 'spec_ready');
    ALTER TABLE dbo.drive_change_packages CHECK CONSTRAINT CK_drive_change_packages_status;

    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.drive_change_packages') AND name=N'CK_drive_change_packages_spec_ready_consistency')
        ALTER TABLE dbo.drive_change_packages WITH CHECK ADD CONSTRAINT CK_drive_change_packages_spec_ready_consistency CHECK
            (status_code = 'spec_ready' AND candidate_content_hash IS NULL AND confirmed_by_user_id IS NULL AND confirmed_at IS NULL);
    ALTER TABLE dbo.drive_change_packages CHECK CONSTRAINT CK_drive_change_packages_spec_ready_consistency;
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.drive_change_packages') AND name=N'IX_drive_change_packages_document_created')
    CREATE INDEX IX_drive_change_packages_document_created
        ON dbo.drive_change_packages(document_id, created_at DESC)
        INCLUDE(public_id, source_content_hash, requirements_sha256, status_code, created_by_user_id);

IF (SELECT COUNT(*) FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(N'dbo.drive_change_packages')
    AND name IN(N'FK_drive_change_packages_document',N'FK_drive_change_packages_creator',N'FK_drive_change_packages_confirmer')) <> 3
    THROW 51025, 'Incomplete dbo.drive_change_packages schema: required foreign keys are missing.', 1;
IF (SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.drive_change_packages')
    AND name IN(N'CK_drive_change_packages_source_hash',N'CK_drive_change_packages_requirements_hash',N'CK_drive_change_packages_candidate_hash',N'CK_drive_change_packages_requirements',N'CK_drive_change_packages_status',N'CK_drive_change_packages_spec_ready_consistency')) <> 6
    THROW 51026, 'Incomplete dbo.drive_change_packages schema: required checks are missing.', 1;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.drive_change_packages') AND name=N'UQ_drive_change_packages_public_id' AND is_unique=1)
    THROW 51027, 'Incomplete dbo.drive_change_packages schema: public id uniqueness is missing.', 1;

/* The deployed Portal principal is currently a db_owner member, so object
   DENY alone is not an effective runtime boundary. This immutable slice has
   no legitimate UPDATE/DELETE path; an INSTEAD OF trigger fails both closed. */
EXEC(N'CREATE OR ALTER TRIGGER dbo.TR_drive_change_packages_immutable
ON dbo.drive_change_packages
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 51028, ''Drive change package evidence is immutable.'', 1;
END;');

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON dbo.drive_change_packages TO TWWATER_PORTAL_APP;
    REVOKE UPDATE ON dbo.drive_change_packages TO TWWATER_PORTAL_APP;
    DENY UPDATE, DELETE ON dbo.drive_change_packages TO TWWATER_PORTAL_APP;
END;

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'022_drive_change_packages.sql')
    INSERT INTO dbo.schema_migrations(script_name) VALUES(N'022_drive_change_packages.sql');

COMMIT TRANSACTION;

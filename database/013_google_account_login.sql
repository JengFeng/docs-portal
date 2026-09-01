SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
BEGIN
    ;THROW 51013, 'Run database migrations 001 through 012 before 013_google_account_login.sql.', 1;
END;
GO

BEGIN TRANSACTION;

IF COL_LENGTH(N'dbo.auth_users', N'google_email_normalized') IS NULL
BEGIN
    ALTER TABLE dbo.auth_users ADD google_email_normalized NVARCHAR(320) NULL;
END;

IF COL_LENGTH(N'dbo.auth_users', N'google_subject') IS NULL
BEGIN
    ALTER TABLE dbo.auth_users ADD google_subject VARCHAR(255) NULL;
END;

IF COL_LENGTH(N'dbo.auth_users', N'google_linked_at') IS NULL
BEGIN
    ALTER TABLE dbo.auth_users ADD google_linked_at DATETIME2(3) NULL;
END;

IF COL_LENGTH(N'dbo.auth_users', N'google_last_login_at') IS NULL
BEGIN
    ALTER TABLE dbo.auth_users ADD google_last_login_at DATETIME2(3) NULL;
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.auth_users') AND name = N'UX_auth_users_google_email')
BEGIN
    EXEC(N'CREATE UNIQUE INDEX UX_auth_users_google_email ON dbo.auth_users (google_email_normalized) WHERE google_email_normalized IS NOT NULL;');
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.auth_users') AND name = N'UX_auth_users_google_subject')
BEGIN
    EXEC(N'CREATE UNIQUE INDEX UX_auth_users_google_subject ON dbo.auth_users (google_subject) WHERE google_subject IS NOT NULL;');
END;

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.auth_users') AND name = N'CK_auth_users_google_subject')
BEGIN
    EXEC(N'ALTER TABLE dbo.auth_users WITH CHECK ADD CONSTRAINT CK_auth_users_google_subject CHECK (google_subject IS NULL OR (LEN(google_subject) BETWEEN 1 AND 255 AND google_subject NOT LIKE ''%[^0-9]%''));');
END;

COMMIT TRANSACTION;
GO

IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'013_google_account_login.sql')
BEGIN
    INSERT INTO dbo.schema_migrations (script_name, checksum_sha256) VALUES (N'013_google_account_login.sql', NULL);
END;
GO

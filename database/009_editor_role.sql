SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
    THROW 50001, 'dbo.auth_users does not exist.', 1;

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.auth_users')
      AND name = N'CK_auth_users_role_code'
)
BEGIN
    ALTER TABLE dbo.auth_users DROP CONSTRAINT CK_auth_users_role_code;
END;

ALTER TABLE dbo.auth_users WITH CHECK
    ADD CONSTRAINT CK_auth_users_role_code
    CHECK (role_code IN ('reader', 'editor', 'admin'));

ALTER TABLE dbo.auth_users CHECK CONSTRAINT CK_auth_users_role_code;

COMMIT TRANSACTION;
GO

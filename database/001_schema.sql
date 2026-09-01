/*
  Run with a dedicated MSSQL migration account.
  The PHP application account should have only the minimum DML permissions.
*/

CREATE TABLE dbo.auth_users
(
    user_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_auth_users PRIMARY KEY,
    username NVARCHAR(128) NOT NULL,
    username_normalized NVARCHAR(128) NOT NULL,
    display_name NVARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_code VARCHAR(32) NOT NULL CONSTRAINT DF_auth_users_role_code DEFAULT ('reader'),
    is_active BIT NOT NULL CONSTRAINT DF_auth_users_is_active DEFAULT (1),
    failed_login_count SMALLINT NOT NULL CONSTRAINT DF_auth_users_failed_login_count DEFAULT (0),
    locked_until DATETIME2(0) NULL,
    password_changed_at DATETIME2(3) NOT NULL CONSTRAINT DF_auth_users_password_changed_at DEFAULT (SYSUTCDATETIME()),
    last_login_at DATETIME2(3) NULL,
    created_at DATETIME2(3) NOT NULL CONSTRAINT DF_auth_users_created_at DEFAULT (SYSUTCDATETIME()),
    updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_auth_users_updated_at DEFAULT (SYSUTCDATETIME()),
    google_email_normalized NVARCHAR(320) NULL,
    google_subject VARCHAR(255) NULL,
    google_linked_at DATETIME2(3) NULL,
    google_last_login_at DATETIME2(3) NULL,
    rowver ROWVERSION NOT NULL,
    CONSTRAINT UQ_auth_users_username_normalized UNIQUE (username_normalized),
    CONSTRAINT CK_auth_users_role_code CHECK (role_code IN ('reader', 'editor', 'admin')),
    CONSTRAINT CK_auth_users_google_subject CHECK (google_subject IS NULL OR (LEN(google_subject) BETWEEN 1 AND 255 AND google_subject NOT LIKE '%[^0-9]%')),
    CONSTRAINT CK_auth_users_username_normalized CHECK (username_normalized NOT LIKE '%[^a-z0-9._-]%')
);
GO

CREATE UNIQUE INDEX UX_auth_users_google_email ON dbo.auth_users (google_email_normalized)
WHERE google_email_normalized IS NOT NULL;
GO
CREATE UNIQUE INDEX UX_auth_users_google_subject ON dbo.auth_users (google_subject)
WHERE google_subject IS NOT NULL;
GO

CREATE TABLE dbo.auth_rate_limits
(
    scope_code VARCHAR(32) NOT NULL,
    scope_key CHAR(64) NOT NULL,
    window_started_at DATETIME2(3) NOT NULL,
    failure_count SMALLINT NOT NULL,
    locked_until DATETIME2(3) NULL,
    updated_at DATETIME2(3) NOT NULL,
    CONSTRAINT PK_auth_rate_limits PRIMARY KEY (scope_code, scope_key),
    CONSTRAINT CK_auth_rate_limits_scope_code CHECK (scope_code IN ('ip'))
);
GO

CREATE TABLE dbo.auth_audit_events
(
    event_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_auth_audit_events PRIMARY KEY,
    occurred_at DATETIME2(3) NOT NULL CONSTRAINT DF_auth_audit_events_occurred_at DEFAULT (SYSUTCDATETIME()),
    event_type VARCHAR(64) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    actor_user_id BIGINT NULL,
    login_name_normalized NVARCHAR(128) NULL,
    client_ip VARCHAR(45) NULL,
    user_agent NVARCHAR(512) NULL,
    CONSTRAINT FK_auth_audit_events_actor_user FOREIGN KEY (actor_user_id) REFERENCES dbo.auth_users (user_id)
);
GO

CREATE INDEX IX_auth_audit_events_occurred_at
    ON dbo.auth_audit_events (occurred_at DESC);
GO

CREATE INDEX IX_auth_audit_events_login_name
    ON dbo.auth_audit_events (login_name_normalized, occurred_at DESC);
GO

/* Image/infographic asset catalog and normalized rectangle annotations.
   Run after 013_google_account_login.sql with the controlled migration identity. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.documents', N'U') IS NULL OR OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
BEGIN
    THROW 51014, 'Run migrations 001 through 013 before 014_image_asset_library.sql.', 1;
END;
GO

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_documents_extension' AND parent_object_id = OBJECT_ID(N'dbo.documents'))
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_extension;
GO
ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_extension CHECK
    (extension IN ('md','txt','pdf','docx','xlsx','pptx','png','jpg','jpeg','webp','gif'));
GO

IF OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_staged_document_revisions_extension' AND parent_object_id = OBJECT_ID(N'dbo.staged_document_revisions'))
        ALTER TABLE dbo.staged_document_revisions DROP CONSTRAINT CK_staged_document_revisions_extension;
    ALTER TABLE dbo.staged_document_revisions WITH CHECK ADD CONSTRAINT CK_staged_document_revisions_extension CHECK
        (extension IN ('pptx','pdf','docx','xlsx','md','txt','png','jpg','jpeg','webp','gif'));
END;
GO

MERGE dbo.document_types AS target
USING (VALUES
    ('visual_asset', N'AI 圖像／資訊圖表', N'AI 生成資訊圖表、圖片、視覺化素材與其修改標註。', 55)
) AS source (type_code, display_name, description, sort_order)
ON target.type_code = source.type_code
WHEN MATCHED THEN UPDATE SET display_name = source.display_name, description = source.description,
    sort_order = source.sort_order, is_active = 1, updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT (type_code, display_name, description, sort_order)
    VALUES (source.type_code, source.display_name, source.description, source.sort_order);
GO

IF OBJECT_ID(N'dbo.image_annotations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.image_annotations
    (
        annotation_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_image_annotations PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_image_annotations_public_id DEFAULT (NEWID()),
        document_id BIGINT NOT NULL,
        source_content_hash CHAR(64) NOT NULL,
        x_norm DECIMAL(9,8) NOT NULL,
        y_norm DECIMAL(9,8) NOT NULL,
        width_norm DECIMAL(9,8) NOT NULL,
        height_norm DECIMAL(9,8) NOT NULL,
        note_text NVARCHAR(1000) NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_image_annotations_status DEFAULT ('open'),
        created_by_user_id BIGINT NOT NULL,
        updated_by_user_id BIGINT NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_annotations_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_annotations_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_image_annotations_public_id UNIQUE (public_id),
        CONSTRAINT FK_image_annotations_document FOREIGN KEY (document_id) REFERENCES dbo.documents(document_id),
        CONSTRAINT FK_image_annotations_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_image_annotations_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_image_annotations_hash CHECK
            (LEN(source_content_hash) = 64 AND source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_image_annotations_bounds CHECK
            (x_norm >= 0 AND y_norm >= 0 AND width_norm > 0 AND height_norm > 0
             AND x_norm + width_norm <= 1 AND y_norm + height_norm <= 1),
        CONSTRAINT CK_image_annotations_status CHECK (status_code IN ('open','resolved','withdrawn')),
        CONSTRAINT CK_image_annotations_note CHECK (LEN(note_text) BETWEEN 1 AND 1000)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_image_annotations_document_hash' AND object_id = OBJECT_ID(N'dbo.image_annotations'))
    CREATE INDEX IX_image_annotations_document_hash
        ON dbo.image_annotations(document_id, source_content_hash, status_code, created_at)
        INCLUDE (public_id, x_norm, y_norm, width_norm, height_norm, note_text, created_by_user_id);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'014_image_asset_library.sql')
    INSERT INTO dbo.schema_migrations(script_name, checksum_sha256) VALUES (N'014_image_asset_library.sql', NULL);
GO
COMMIT TRANSACTION;
GO

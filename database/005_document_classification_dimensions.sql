/*
  Split document classification into two independent, multi-select facets:
  1) document kinds (this migration)
  2) SSDLC phase/input/output roles (existing document_phase_roles)

  The legacy dbo.documents.document_type_id column is intentionally retained
  and kept as the first selected kind for rollback compatibility.
*/

SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.document_type_links', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_type_links
    (
        document_id BIGINT NOT NULL,
        document_type_id INT NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_type_links_created_at DEFAULT (SYSUTCDATETIME()),
        created_by_user_id BIGINT NULL,
        CONSTRAINT PK_document_type_links PRIMARY KEY (document_id, document_type_id),
        CONSTRAINT FK_document_type_links_document FOREIGN KEY (document_id) REFERENCES dbo.documents (document_id) ON DELETE CASCADE,
        CONSTRAINT FK_document_type_links_type FOREIGN KEY (document_type_id) REFERENCES dbo.document_types (document_type_id),
        CONSTRAINT FK_document_type_links_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users (user_id)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_document_type_links_type_document'
      AND object_id = OBJECT_ID(N'dbo.document_type_links')
)
    CREATE INDEX IX_document_type_links_type_document
        ON dbo.document_type_links (document_type_id, document_id);
GO

MERGE dbo.document_types AS target
USING (VALUES
    ('requirements',  N'需求文件',             N'需求說明、需求追溯、範圍與交付需求。', 10),
    ('specification', N'規格書',               N'功能、介面、資料或其他可驗證規格。', 20),
    ('meeting',       N'會議／決策紀錄',       N'訪談、會議紀錄、決議與待辦事項。', 30),
    ('planning',      N'計畫書／提案書',        N'執行計畫、建議書、提案與工作規劃。', 40),
    ('architecture',  N'架構／設計文件',       N'系統、資料、API、UI 與架構設計。', 50),
    ('visual_asset',  N'AI 圖像／資訊圖表',    N'AI 生成資訊圖表、圖片、視覺化素材與其修改標註。', 55),
    ('development',   N'技術說明／程式文件',   N'技術實作、程式結構、建置與開發說明。', 60),
    ('testing',       N'測試／驗收文件',       N'測試計畫、案例、結果、缺失與驗收紀錄。', 70),
    ('deployment',    N'部署／發布文件',       N'部署步驟、發行說明、切換與回復紀錄。', 80),
    ('operations',    N'操作／維運手冊',       N'操作程序、監控、異常處理與維運紀錄。', 90),
    ('security',      N'資安／稽核文件',       N'資安基線、風險、檢核與稽核報告。', 100),
    ('system',        N'環境／主機資訊',       N'主機、網路、軟體版本與環境設定資訊。', 110),
    ('report',        N'分析／成果報告',       N'分析結果、階段成果、統計與管理報告。', 120),
    ('register',      N'清單／台帳',           N'資產、議題、風險、需求或交付項目清單。', 130),
    ('other',         N'其他文件',             N'不屬於前述種類但仍需納入文件庫的資料。', 999)
) AS source (type_code, display_name, description, sort_order)
ON target.type_code = source.type_code
WHEN MATCHED THEN
    UPDATE SET display_name = source.display_name,
               description = source.description,
               sort_order = source.sort_order,
               is_active = 1,
               updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (type_code, display_name, description, sort_order)
    VALUES (source.type_code, source.display_name, source.description, source.sort_order);
GO

INSERT INTO dbo.document_type_links (document_id, document_type_id, created_by_user_id)
SELECT d.document_id, d.document_type_id, d.updated_by_user_id
FROM dbo.documents d
WHERE d.document_type_id IS NOT NULL
  AND NOT EXISTS
      (
          SELECT 1
          FROM dbo.document_type_links link
          WHERE link.document_id = d.document_id
            AND link.document_type_id = d.document_type_id
      );
GO

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, DELETE ON dbo.document_type_links TO [TWWATER_PORTAL_APP];
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM dbo.schema_migrations
    WHERE script_name = N'005_document_classification_dimensions.sql'
)
    INSERT INTO dbo.schema_migrations (script_name, checksum_sha256)
    VALUES (N'005_document_classification_dimensions.sql', NULL);
GO

COMMIT TRANSACTION;
GO

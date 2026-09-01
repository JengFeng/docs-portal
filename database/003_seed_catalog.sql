/*
  SSDLC 階段與文件種類的基準資料；兩者是獨立的分類面向。
  可重複執行；會更新名稱、順序與啟用狀態，但不會刪除使用中的資料。
*/

SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

MERGE dbo.ssdlc_phases AS target
USING (VALUES
    ('00', N'跨階段共用', N'跨越專案生命週期的治理、追溯、決策與資安基線文件。', 0),
    ('01', N'規劃與需求分析', N'將 RFP、原始需求、訪談與會議資訊整理為可追蹤的正式需求。', 1),
    ('02', N'系統設計', N'形成資料、介面、API 與系統結構設計。', 2),
    ('03', N'開發與編碼', N'依設計規格完成實作、程式碼檢核與單元測試。', 3),
    ('04', N'測試驗證', N'以測試與驗收確認功能與品質。', 4),
    ('05', N'部署發布', N'完成建置、部署、驗證與發布留存。', 5),
    ('06', N'維護與營運', N'記錄監控、異常處理、修補與營運作業。', 6)
) AS source (phase_code, display_name, description, sort_order)
ON target.phase_code = source.phase_code
WHEN MATCHED THEN
    UPDATE SET display_name = source.display_name, description = source.description, sort_order = source.sort_order, is_active = 1, updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (phase_code, display_name, description, sort_order) VALUES (source.phase_code, source.display_name, source.description, source.sort_order);
GO

MERGE dbo.document_types AS target
USING (VALUES
    ('requirements', N'需求文件', N'需求說明、需求追溯、範圍與交付需求。', 10),
    ('specification', N'規格書', N'功能、介面、資料或其他可驗證規格。', 20),
    ('meeting', N'會議／決策紀錄', N'訪談、會議紀錄、決議與待辦事項。', 30),
    ('planning', N'計畫書／提案書', N'執行計畫、建議書、提案與工作規劃。', 40),
    ('architecture', N'架構／設計文件', N'系統、資料、API、UI 與架構設計。', 50),
    ('visual_asset', N'AI 圖像／資訊圖表', N'AI 生成資訊圖表、圖片、視覺化素材與其修改標註。', 55),
    ('development', N'技術說明／程式文件', N'技術實作、程式結構、建置與開發說明。', 60),
    ('testing', N'測試／驗收文件', N'測試計畫、案例、結果、缺失與驗收紀錄。', 70),
    ('deployment', N'部署／發布文件', N'部署步驟、發行說明、切換與回復紀錄。', 80),
    ('operations', N'操作／維運手冊', N'操作程序、監控、異常處理與維運紀錄。', 90),
    ('security', N'資安／稽核文件', N'資安基線、風險、檢核與稽核報告。', 100),
    ('system', N'環境／主機資訊', N'主機、網路、軟體版本與環境設定資訊。', 110),
    ('report', N'分析／成果報告', N'分析結果、階段成果、統計與管理報告。', 120),
    ('register', N'清單／台帳', N'資產、議題、風險、需求或交付項目清單。', 130),
    ('other', N'其他文件', N'不屬於前述種類但仍需納入文件庫的資料。', 999)
) AS source (type_code, display_name, description, sort_order)
ON target.type_code = source.type_code
WHEN MATCHED THEN
    UPDATE SET display_name = source.display_name, description = source.description, sort_order = source.sort_order, is_active = 1, updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (type_code, display_name, description, sort_order) VALUES (source.type_code, source.display_name, source.description, source.sort_order);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'003_seed_catalog.sql')
    INSERT INTO dbo.schema_migrations (script_name, checksum_sha256) VALUES (N'003_seed_catalog.sql', NULL);
GO

COMMIT TRANSACTION;
GO

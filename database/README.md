# SQL Server 部署順序

使用受控的 migration 帳號，以 `sqlcmd` 或 SSMS 依序執行：

1. `001_schema.sql`
2. `002_document_catalog.sql`
3. `003_seed_catalog.sql`
4. `005_document_classification_dimensions.sql`
5. `006_presentation_review.sql`
6. `007_markdown_review.sql`
7. `008_document_version_operations.sql`
8. `009_editor_role.sql`
9. `010_staged_document_revisions.sql`
10. `011_staging_change_request_link.sql`
11. `012_staging_return_for_changes.sql`
12. `013_google_account_login.sql`
13. `014_image_asset_library.sql`
14. 將 `004_app_permissions.template.sql` 的資料庫使用者名稱替換後執行。

網站使用帳號只需要對資料表有必要的 DML 權限；不可授予 `db_owner`、DDL 或稽核表的 `UPDATE`／`DELETE` 權限。

完成後，請在伺服器本機受控命令列設定 `PORTAL_BOOTSTRAP_*` 變數並執行：

```powershell
php scripts/create_admin.php
```

首位管理員建立後，執行下列指令確認執行環境，再由管理後台索引文件來源：

```powershell
php scripts/check_environment.php
php scripts/sync_documents.php
```

`sync_documents.php` 只會掃描 `PORTAL_DOCUMENT_ROOT` 的允許格式，新增文件預設為 `draft`；管理員分別勾選文件種類、SSDLC 階段／角色並設定發布狀態後，閱讀者才會看到文件。文件種類與 SSDLC 階段是兩組獨立的多選關聯。

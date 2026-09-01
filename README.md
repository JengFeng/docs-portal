# 供水監測文件協作平台（PHP + SQL Server）

此目錄是正式網站程式根目錄：

```text
C:\web\gary\TWWATER
```

系統採用「Discord 協作產出文件、受保護網站即時閱讀」模式。網站文件根保存目前正式版本；SQL Server 保存帳號、文件中繼資料、SSDLC 對應、稽核紀錄，以及每一版不可變更的完整文件 bytes。

## 已實作功能

- 系統帳號登入，只有管理者能建立帳號；不提供公開註冊。
- 可選用 Google Web OAuth 驗證登入；管理員必須先將已驗證 Email 綁定至既有系統帳號，首次成功登入後再固定 Google `sub` 身分，不自動建立帳號。
- `reader`／`editor`／`admin` 三種角色，所有權限均由 PHP 後端判斷；`editor` 可新增／修改、標註、建立需求及確認自己的暫存版本，只有 `admin` 可確認其他編輯者的版本。
- 登入失敗鎖定：帳號 5 次、IP 20 次；帳號與 IP 的鎖定資料存於 SQL Server。
- Secure、HttpOnly、SameSite session cookie；閒置 30 分鐘、最長 8 小時。
- CSRF 防護、登入後 session ID 更新、參數化 PDO_SQLSRV 查詢。
- 文件目錄同步：掃描受保護文件來源，新增文件預設為 `draft`，不會自動發布。
- 以不可猜測的 UUID 文件識別碼讀取、預覽與下載；URL 不含實體路徑。
- Markdown／TXT 僅以 HTML 跳脫後預覽；PDF 可受保護地內嵌；DOCX／XLSX 仍強制下載。
- PPTX 由瀏覽器使用站內 JSZip 解開 Open XML，並以 SVG／Canvas 即時預覽；開啟頁面不等待 LibreOffice 或 PDF 排程，原始簡報不會被網站程式覆寫。
- 管理員可逐頁框選、加箭頭／螢光／文字／編號標注，並建立綁定原始 SHA-256 的修改需求；需求可複製或匯出為 Markdown 後貼到 Discord。
- PNG／JPG／JPEG／WEBP／GIF 以主選單「AI 圖像／資訊圖表」獨立於一般即時文件庫管理；一般文件清單不顯示圖片資產。圖片審閱採滿版 RWD 工作區，桌面顯示畫布與右側標註清單，平板／手機改為堆疊並讓導覽與工具列在各自容器內橫向捲動。編輯者可使用選取、框選、箭頭、螢光、文字、編號、復原、重做、刪除及縮放／符合寬度工具；標註 geometry/style、建立者、時間與待處理／已處理狀態均保存，管理員可跨使用者更新狀態。所有座標使用 normalized image space，私有圖片串流及儲存都綁定來源 SHA-256；預覽前另由獨立 decoder 完整解碼，拒絕只有合法檔頭但內容截斷的圖片。
- 圖片庫支援最多 40 張多選、前移／後移排序與合併下載，可輸出動態 GIF、逐頁 PDF 或 16:9 PPTX，並可選擇是否將待處理標註燒錄到輸出檔。
- 正式 IIS 使用受 ACL 保護的獨立 `image_exporter.exe`；匯出前會將已驗證來源複製為私有不可變快照，worker 再核對 SHA-256。工作檔只建立在經 staging 安全檢查的 `PORTAL_PREUPLOAD_PREVIEW_ROOT`，每次匯出完成後立即清除。單次最多 40 張、來源合計 600 MiB／2 億像素、待處理標註 1,000 筆、輸出 256 MiB，worker 最長執行 120 秒；同一帳號只允許一件匯出，整站同時最多兩件。
- 文件種類與 SSDLC 00／01–06 階段／輸入產出角色是兩個獨立的多選面向，皆可由管理者勾選設定。
- 讀取、下載、同步、帳號與文件中繼資料異動皆寫入稽核資料表。
- 完整文件版本以 SQL Server `VARBINARY(MAX)` 不可變保存，單一版本上限 500 MiB；目前網站檔、catalog、版本 metadata 與 SQL bytes 以 SHA-256 綁定，未完成 capture 時文件不提供下載。
- 文件清單的同路徑安全替換與可還原軟封存已實作，但預設由 `PORTAL_DOCUMENT_MUTATIONS_ENABLED=0` 保持關閉；只有 migration、原子替換、crash reconciliation、權限及 RWD 驗收全部通過後才可設為 `1`。

## 目錄與資料庫設計

| 位置／資料表 | 用途 |
|---|---|
| `PORTAL_DOCUMENT_ROOT` | 受保護的實體文件來源，不能建立 IIS 虛擬目錄 |
| `dbo.auth_users` | 系統帳號、角色、密碼雜湊與登入鎖定 |
| `dbo.documents` | 文件中繼資料、發布狀態、來源版本與 UUID |
| `dbo.document_types` | 需求文件、規格書、會議紀錄、手冊、報告等文件種類基準資料 |
| `dbo.document_type_links` | 每份文件可對應多個文件種類；與 SSDLC 階段獨立 |
| `dbo.ssdlc_phases` | 00 跨階段共用與 01–06 SSDLC 階段 |
| `dbo.document_phase_roles` | 每份文件在各階段的 `input`／`output` 關聯 |
| `dbo.document_versions`、`dbo.document_version_contents` | 以來源 SHA-256 固定每個不可變版本及其完整 bytes |
| `dbo.document_mutation_operations`、`dbo.document_mutation_events` | 同路徑替換的 durable intent、狀態與crash reconciliation證據 |
| `dbo.presentation_preview_jobs`、`dbo.presentation_renditions`、`dbo.presentation_slides` | PPTX 預覽佇列、PDF 產物與投影片頁次 |
| `dbo.presentation_annotations` | 正規化座標標注、版本鎖定與處理狀態 |
| `dbo.presentation_change_requests`、`dbo.presentation_change_request_items` | 簡報修改需求及建立當下的不可變更標注快照 |
| `dbo.staged_document_revisions` | 私有分段上傳、預覽、退回、確認、Drive 驗證狀態與雜湊衝突控制 |
| `dbo.document_sync_runs` | 文件來源重新索引結果 |
| `dbo.auth_audit_events`、`dbo.document_audit_events` | 不可修改的登入與文件稽核紀錄 |

## 前置條件

1. IIS HTTPS 網站，實體路徑為 `C:\web\gary\TWWATER`。
2. PHP 8.x FastCGI，且 PHP CLI 與 IIS FastCGI 使用相同位元數與設定。
3. Microsoft ODBC Driver for SQL Server，以及相同 PHP 版本／位元數的 `pdo_sqlsrv` 擴充套件。
4. SQL Server 中的 migration 帳號與網站 application 帳號必須分開；網站帳號不可有 `db_owner`、DDL、稽核表 `UPDATE` 或 `DELETE` 權限。
5. `PORTAL_DOCUMENT_ROOT`、`PORTAL_SESSION_SAVE_PATH` 與 `PORTAL_PREUPLOAD_PREVIEW_ROOT` 必須在網站根目錄外。IIS AppPool 對文件來源只給 Read & Execute，對 session、私有預覽與轉檔 runtime 才給 Modify；正式寫入只由文件橋接器的受控身分執行。若保留舊版背景 PDF 產物供既有標注對照，`PORTAL_PRESENTATION_PREVIEW_ROOT` 與 `PORTAL_PRESENTATION_RUNTIME_ROOT` 也必須位於站外並只授予 AppPool Modify。
6. JSZip、PPTX SVG renderer 與 Fabric.js 已以站內檔案隨專案部署，不需要 CDN 或瀏覽器連線外部服務；IIS 必須將 `.mjs` 回傳為 `text/javascript`。
7. LibreOffice 僅屬相容性背景服務，不再是開啟線上 PPTX 預覽的必要條件。

## 受保護環境變數

在 IIS／Windows 的安全環境設定中建立。不要存入程式碼、網站根目錄、`.env` 或 Discord。

```text
PORTAL_DB_HOST
PORTAL_DB_NAME
PORTAL_DB_USERNAME
PORTAL_DB_PASSWORD_B64
PORTAL_DOCUMENT_ROOT
PORTAL_RATE_KEY
PORTAL_SESSION_SAVE_PATH
PORTAL_PRESENTATION_PREVIEW_ROOT
PORTAL_PRESENTATION_RUNTIME_ROOT
PORTAL_PREUPLOAD_PREVIEW_ROOT
PORTAL_STAGED_COMMIT_ROOT
PORTAL_LIBREOFFICE_PATH
PORTAL_IMAGE_EXPORT_PYTHON
```

建議同時設定：

```text
PORTAL_COOKIE_PATH=/gary/TWWATER/
PORTAL_DB_ENCRYPT=1
PORTAL_DB_TRUST_SERVER_CERTIFICATE=0
```

可參考 [`.env.example`](.env.example)，但不得將真實值放入該檔案。

## SQL Server 安裝

由 migration 帳號依序以 SQLCMD 或 SSMS 執行：

1. `database/001_schema.sql`
2. `database/002_document_catalog.sql`
3. `database/003_seed_catalog.sql`
4. `database/005_document_classification_dimensions.sql`
5. `database/006_presentation_review.sql`
6. `database/007_markdown_review.sql`
7. `database/008_document_version_operations.sql`
8. `database/009_editor_role.sql`
9. `database/010_staged_document_revisions.sql`
10. `database/011_staging_change_request_link.sql`
11. `database/012_staging_return_for_changes.sql`
12. `database/013_google_account_login.sql`
13. `database/014_image_asset_library.sql`
14. 將 `database/004_app_permissions.template.sql` 的使用者名稱改成實際 application database user 後執行。

詳細說明在 [database/README.md](database/README.md)。

## 首次啟用順序

1. 設定受保護環境變數，建立站外的 session 與文件來源資料夾 ACL。
2. 執行資料庫 migration 與 application 帳號最小權限設定。
3. 僅在伺服器本機受控命令列設定下列一次性變數，建立第一位管理員：

   ```text
   PORTAL_BOOTSTRAP_USERNAME
   PORTAL_BOOTSTRAP_DISPLAY_NAME
   PORTAL_BOOTSTRAP_PASSWORD
   ```

   ```powershell
   php scripts/create_admin.php
   ```

   建立後立即移除 `PORTAL_BOOTSTRAP_PASSWORD`。

4. 驗證執行環境與 SQL Server 連線：

   ```powershell
   php scripts/check_environment.php
   ```

5. 首次索引文件來源：

   ```powershell
   php scripts/sync_documents.php
   ```

   也可由登入後的管理後台按「重新索引」。新文件預設 `draft`，管理者需分別勾選文件種類、SSDLC 階段／角色並改為 `published`，閱讀者才會看到。

6. 瀏覽器端 PPTX 預覽不需安裝排程或 LibreOffice。若要讓尚未建立 server rendition／slide map 的版本啟用既有資料庫標注功能，可保留相容性背景工作；詳細安全界線請參考 [`scripts/PRESENTATION_PREVIEW.md`](scripts/PRESENTATION_PREVIEW.md)：

   ```powershell
   .\scripts\test_presentation_preview_worker.ps1
   ```

   背景工作失敗不會阻擋瀏覽器預覽；只會讓尚無投影片對照的版本維持唯讀模式。

7. 所有檢查通過後，才執行受保護的切換指令。它會先 PHP lint、執行健康檢查、備份現行設定，才將正式 `web.config` 啟用：

   ```powershell
   .\scripts\enable_production.ps1
   ```

   指令完成後回收 IIS AppPool。正式設定會以 `index.php` 為入口，並封鎖 `app`、`database`、`scripts`、`.html` 原型頁與設定檔。切換前不要把 PHP 入口公開。

## 文件同步原則

- 正式站的 `PORTAL_DOCUMENT_ROOT` 應指向 IIS 可讀取的實體快取；不可直接指向僅限互動使用者存取的雲端同步目錄。
- `scripts/document_bridge.ps1` 以登入中的 Windows 使用者身份監看原始工作目錄，將允許格式原子複製至實體快取，並以受 ACL 保護的本機訊號喚醒既有 PHP 索引流程。橋接器不讀取或保存 SQL 密碼、網站帳密或登入 Cookie。
- 橋接器會排除 Discord 暫存、隱藏／系統／ReparsePoint 路徑、Office 暫存檔及建置工具目錄；舊快取版本會移至隔離區，不會立即永久刪除。
- 支援：`md`、`txt`、`pdf`、`docx`、`xlsx`、`pptx`、`png`、`jpg`、`jpeg`、`webp`、`gif`。
- 新檔一律為 `draft`；已發布文件若內容改變，會自動退回 `draft`。來源消失的文件會標記為 `archived`，保留稽核紀錄。
- 自動索引與管理員手動索引共用排他鎖，避免平行執行。橋接批次尚未完成時，讀者端會暫停文件讀取，避免提供尚未審核的新版本。

## 文件橋接器啟用

必須以能讀取 Google Drive 工作目錄的使用者（目前為 `gisadmin`）登入主機，並以系統管理員 PowerShell 執行一次：

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force

& 'C:\web\gary\TWWATER\scripts\install_document_bridge.ps1' `
  -SourceRoot 'C:\Users\gisadmin\我的雲端硬碟\115供水監測' `
  -CacheRoot 'C:\TWWATER\document-cache' `
  -RuntimeRoot 'C:\TWWATER\runtime\document-bridge' `
  -SessionRoot 'C:\TWWATER\runtime\sessions' `
  -AppPoolName 'TWWATER_PortalPool'
```

安裝程序會先完整建立快取，成功後才切換 `PORTAL_DOCUMENT_ROOT`、回收 AppPool、建立「`TWWATER Document Bridge`」登入時工作排程並啟動它。既有 `C:\TWWATER\documents` Junction 不會被刪除。可先執行不碰正式環境的驗證：

```powershell
& 'C:\web\gary\TWWATER\scripts\test_document_bridge.ps1'
```

## 上線驗收

- PHP CLI、IIS FastCGI、ODBC Driver、`pdo_sqlsrv` 的版本與位元數一致。
- `check_environment.php` 全部通過，並能連線到 SQL Server。
- 管理者可登入、建立閱讀者、同步文件、分別複選文件種類與 SSDLC 階段／角色並發布。
- 閱讀者不可開啟管理後台、草稿或封存文件。
- 文件 UUID 偽造、路徑穿越、隱藏檔、副檔名偽裝均被拒絕。
- 登出及後台異動使用 POST + CSRF；登入／讀取／下載／同步／設定異動可在稽核表追蹤。
- IIS 不對 `PORTAL_DOCUMENT_ROOT` 建立虛擬目錄，並關閉 Directory Browsing、WebDAV、FTP 與 TRACE。
- 已發布 PPTX 可開啟「線上檢視與標注」；PDF 資產只能經過登入與文件權限檢查的 PHP 路由讀取，預覽根目錄沒有 IIS 虛擬目錄。
- 標注儲存前會再次核對來源 SHA-256；來源已更新時回傳衝突，不會把舊標注套到新簡報。
- 修改需求 Markdown 包含文件版本、來源 SHA-256、頁次、標注類型及正規化座標，且不含資料庫或 Discord 憑證。

# 全站視覺化修改需求工具契約

## 已確認的產品決策

- 視覺與操作基礎：沿用既有「線上簡報標註調整」工作區，不另做陌生介面；重用其工具列、標註清單、框選／箭頭／螢光／文字／編號、選取、復原／重做及逐條需求編輯概念。移除重複的左側網站頁面樹及「頁面／批次」按鈕，桌面主畫面以實際頁面為主要彈性欄；全站深藍主選單使用 sticky 固定於視窗上緣。
- 標註面：工具列的「實際頁面」下拉選單是唯一頁面切換入口，中央直接載入目前登入者有權查看的同站實際頁面；草稿、分析失敗與已退回狀態下，所有框選、箭頭、螢光、文字與編號均可用「移動／調整」後拖曳修正位置。每次新建標註後自動切換到移動模式；已退回批次第一次儲存修改時回到草稿並推進 revision。
- 文字工具：右側「文字內容／修改說明」是可編輯來源，輸入時以安全 `textContent` 即時同步到畫面標註，不再固定顯示「文字」。
- 問題關聯：一般需求預設為「獨立問題」；只有某項是另一項的細節時，才展開「關聯到其他問題（選填）」設定，不永久顯示技術性的上層問題欄位。
- 編號工具：以固定大小圓形標點呈現，中央顯示數字；只依編號標點的建立順序計算 1、2、3，不使用整體需求序號，刪除其他類型標註不得改變編號。
- 全站字級：所有頁面（含登入頁）提供小／中／大三段 allowlist 切換，預設大字型，使用同源 `localStorage` 保存偏好；主要頁面 scope 必須以 `rem` 繼承選定根字級。
- 工具：瀏覽、框選、箭頭、螢光、文字、編號；桌面滑鼠與平板／手機觸控均支援。
- 批次歷程：頁面右上方「修改歷程」按鈕與「新增需求批次」並列；點擊後開啟原生 modal 浮動視窗。批次依 `updated_at DESC, batch_id DESC` 呈現，收合列只顯示流水序號、名稱、最近更新時間及狀態；點擊該列才展開完整批次 ID、Revision、需求數、「調整前草稿」「調整後結果」「複製 ID」及目前狀態可用的儲存、產生規格、核准或退回動作。
- 前後檢視語意：自 migration `017_site_feedback_snapshots.sql` 起，每個批次 Revision／站內目標頁面可各保存一張 `before` 與一張 `after` PNG 可視區截圖。截圖由已登入、同源 iframe 的瀏覽器使用固定在站內的 `html2canvas 1.4.1` 產生；只接受 allowlist GET 目標，不接受任意 URL。保存前使用者必須明確確認畫面沒有密碼、驗證碼、token、個資或其他未遮蔽敏感內容；client redaction profile `password-explicit-v1` 會排除 password／password-autocomplete 欄位與網站明確標記 `data-feedback-snapshot-exclude` 的區塊，migration `018_site_feedback_snapshot_review.sql` 會把確認狀態與 redaction profile 寫入不可變列及稽核事件。`before` 只能由建立者／管理員在草稿、分析失敗或退回狀態保存，且送出「產生精確修改規格」前，伺服器會要求該 Revision 的每一個目標頁面都已有 `before`；分析失敗後若需求未變更，重送維持相同 Revision 與原本綁定，不複製或重新標記截圖；若使用者修改需求，既有流程會先增加 Revision，並要求重新擷取該 Revision 的 `before`。`after` 只能由管理員在批次 `completed` 後保存。截圖以 batch、Revision、kind、完整 target URL、viewport、scroll、SHA-256 綁定並存入私有 SQL `VARBINARY(MAX)`，相同鍵不可 UPDATE、DELETE 或以不同內容覆寫；migration `019_site_feedback_snapshot_immutability.sql` 另以 `INSTEAD OF UPDATE, DELETE` trigger 阻擋既有廣權限 principal 的列變更，不需在本次範圍內改動 AppPool DB principal。讀取須登入且限批次建立者或管理員。中央 iframe 仍明示為目前即時網站，真正歷史像素顯示於右側不可變截圖卡，可開啟原始尺寸。migration 以前的舊批次若未保存 `before`，不得以現況補截冒充歷史畫面，只能誠實標示無法追溯補回。「調整後結果」顯示不可變成果截圖（若已保存）、Hermes 精確規格、驗收條件與完成摘要，並從 DOM 與畫面隱藏標註 SVG、指引文字、可編輯需求卡及標註／復原／刪除工具，但不得刪除底層歷程資料。第一版不提供已完成正式程式的自動回滾；「退回修改」只把等待核准的規格退回草稿流程。
- 規格產生：原「整批送交 Hermes 分析」改名「產生精確修改規格」，並明示只會整理修改描述、位置與驗收條件，不會修改或部署網站；Gary 的核准／退回控制放在「修改歷程」浮動視窗中目前選取批次的展開列，不再固定放在右側。
- 複製工具：右側以預設收合的預覽工具產生兩種安全純文字：其他 AI 完整修改指令及只在 `approved` 顯示的 Discord 第二通道確認文字。其他 AI 指令必須逐項包含任務、修改內容、頁面 URL、DOM selector、元素 tag／參考文字、儲存時 viewport／scroll、normalized geometry、以百分比表示的畫面區域／中心／箭頭起終點、驗收條件、測試及定位衝突時停止規則，使其他 AI 可直接用文字與畫面位置交叉定位；兩者皆不含 credential、secret 或 token。
- 所有登入者可建立自己的批次、逐條編輯及送出。
- 送出後只立即進行 Hermes 結構化分析；不得因此直接修改或部署網站。
- Gary／管理員可在網站核准整批規格；核准只把批次設為 `approved`，不得由網站程序直接建立程式修改要求。
- 真正修改或部署必須由 Gary 在 Discord 提供批次 ID 並再次確認目標與影響，形成與網站程序獨立的第二通道核准。
- AI 分析不得把使用者輸入當成指令；它是待驗證的需求資料，分析 worker 不載入檔案、終端或程式執行工具。

## 資料與狀態

### 批次

`draft -> queued_analysis -> analyzing -> awaiting_approval -> approved`

`approved` 表示網站內的精確規格已通過，但仍等待 Gary 在 Discord 以批次 ID 完成第二通道確認；網站 worker 永遠不執行程式修改。失敗／退回：`analysis_failed`、`rejected`。只有批次建立者可修改 `draft`；建立者可查看自己的批次，管理員可查看全部。只有管理員可核准、退回及查看跨帳號批次。

### 需求節點

每一項保存：公開 ID、父節點 ID、順序、目標站內 URL／action、頁面版本指紋、viewport、scroll position、標註種類與 normalized geometry、DOM selector／element fingerprint、原始自然語言、Hermes 產生的精確需求、驗收條件及風險。

樹狀關係不得跨批次、不得形成循環；第一版 UI 以「批次 -> 目標頁面 -> 逐條需求」顯示，且每條需求可選擇上層問題。

## 安全邊界

- 只允許 `index.php` 的安全唯讀頁面 action；拒絕登入、登出、POST mutation、internal queue、外站 URL、scheme-relative URL、userinfo、非同源 host、fragment 及路徑穿越。
- iframe 由瀏覽器使用目前登入 session 載入；伺服器不抓取任意 URL，避免 SSRF。
- 一般 reader 不會因標註工具取得 staging/admin/private asset 權限；目標頁仍由原 route RBAC 判斷。
- 所有 mutation 必須 POST、CSRF、參數化 SQL、transaction、owner/admin scope、狀態機 compare-and-set、append-only audit。
- 使用者文字只以 `textContent` 或 HTML escaping 顯示，不保存／輸出任意 HTML 或 script。
- normalized geometry 以 iframe 可視內容 viewport 為基準，連同 CSS viewport、device pixel ratio、scrollX/Y、target URL 與 page fingerprint 綁定。
- 歷史截圖 API 使用獨立 7.5 MB JSON 上限；只接受結構、chunk 順序、CRC、唯一 IHDR、可完整解壓且 scanline 長度／filter 正確的 PNG；最大 5.5 MB、單邊 8192 px、總像素 12,000,000，且 PNG 尺寸必須與提交的 viewport 完全一致。圖片端點只以 `image/png`、`nosniff`、private immutable cache 與 SHA-256 ETag 串流，不產生公開 URL，也不把 image bytes 放入一般批次 JSON。
- Hermes 分析結果須以 request SHA-256、batch public ID、revision 與完整 item ID 集合驗證後才能匯入。
- 網站核准只寫入 `approved` 狀態與稽核，不建立 execution queue；網站端只接受 `analysis` dispatch／request／result，任何 `execution` kind 或 execution state 均 fail closed。真正修改須由 Gary 在 Discord 再次提供批次 ID 並確認目標／影響。
- 一般 Feedback 頁面與其他 GET 只讀取狀態，不得同步或匯入結果；分析結果 reconcile／import 僅能由登入頁面的 CSRF-protected POST polling 觸發。

## 自動化邊界

網站與 SQL 建立 credentialless analysis request/result projection。Hermes worker 只做無工具的結構化分析，不具備檔案、終端或部署能力；真正修改由 Discord 對話中的 Hermes 在 Gary 第二通道確認後另行執行。在修改 `C:\Users\gisadmin\AppData\Local\hermes`、AppPool 環境、webhook secret、排程或 `C:\TWWATER\runtime\staged-commit-channel` 新子目錄前，須先通知 Gary 目標與影響並取得明確同意。

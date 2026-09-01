<?php
declare(strict_types=1);

function portal_html_studio_initial_markup(): string
{
    return <<<'HTML'
<main class="generated-site">
  <header class="generated-hero">
    <div>
      <span class="generated-eyebrow">TWWATER · 即時供水監測</span>
      <h1>今天的供水狀態，一眼看清楚</h1>
      <p>集中查看測站狀態、壓力趨勢與待處理事項。</p>
    </div>
    <button type="button" id="generated-report-button">開啟今日報告</button>
  </header>
  <section class="generated-summary" aria-label="供水摘要">
    <article><span>正常測站</span><strong>18</strong><small>全部連線正常</small></article>
    <article><span>需要注意</span><strong>2</strong><small>壓力低於觀察值</small></article>
    <article><span>最後更新</span><strong>10:42</strong><small>資料延遲 18 秒</small></article>
  </section>
  <section class="generated-table-card">
    <div class="generated-section-head">
      <div><span class="generated-eyebrow">即時列表</span><h2>測站狀態</h2></div>
      <label>搜尋測站<input id="generated-station-search" type="search" placeholder="輸入測站名稱"></label>
    </div>
    <div class="generated-table-scroll">
      <table>
        <thead><tr><th>測站</th><th>區域</th><th>壓力</th><th>狀態</th><th>更新時間</th></tr></thead>
        <tbody>
          <tr><td>北區加壓站</td><td>北區</td><td>2.81 kg/cm²</td><td><span class="generated-ok">正常</span></td><td>10:42</td></tr>
          <tr><td>東門監測點</td><td>東區</td><td>1.92 kg/cm²</td><td><span class="generated-watch">注意</span></td><td>10:41</td></tr>
          <tr><td>文山配水池</td><td>南區</td><td>2.55 kg/cm²</td><td><span class="generated-ok">正常</span></td><td>10:42</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</main>
HTML;
}

function portal_html_studio_initial_css(): string
{
    return <<<'CSS'
:root{font-family:"Segoe UI","Microsoft JhengHei",sans-serif;color:#17211d;background:#f3f6f4}*{box-sizing:border-box}body{margin:0}.generated-site{width:min(1500px,calc(100% - 48px));margin:auto;padding:34px 0 60px}.generated-hero{display:flex;justify-content:space-between;align-items:end;gap:24px;border-bottom:1px solid #ccd7d1;padding:22px 0 30px}.generated-eyebrow{color:#31715f;font-size:13px;font-weight:800;letter-spacing:.08em}.generated-site h1{font-size:clamp(32px,4.1vw,58px);max-width:760px;line-height:1.05;margin:10px 0 14px}.generated-site h2{font-size:28px;margin:7px 0}.generated-hero p{font-size:19px;color:#64716b;margin:0}.generated-hero button{min-height:50px;background:#173b31;color:white;border:0;border-radius:7px;padding:0 22px;font-weight:800}.generated-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:22px 0}.generated-summary article{background:white;border:1px solid #dce3df;padding:19px}.generated-summary span,.generated-summary small{display:block;color:#64716b}.generated-summary strong{display:block;font-size:34px;margin:8px 0}.generated-table-card{background:white;border:1px solid #dce3df;padding:22px}.generated-section-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:16px}.generated-section-head label{font-size:13px;font-weight:700}.generated-section-head input{display:block;min-height:44px;width:260px;border:1px solid #b9c6bf;border-radius:7px;padding:0 12px;margin-top:5px}.generated-table-scroll{overflow:auto}.generated-site table{width:100%;border-collapse:collapse;font-size:17px}.generated-site th,.generated-site td{text-align:left;padding:15px 12px;border-bottom:1px solid #e4e8e6}.generated-site th{font-size:13px;color:#66736d}.generated-ok,.generated-watch{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700}.generated-ok{background:#dff0e8;color:#26634f}.generated-watch{background:#fff0d6;color:#8e5e14}@media(max-width:700px){.generated-site{width:calc(100% - 28px);padding-top:18px}.generated-hero{display:block}.generated-hero button{width:100%;margin-top:20px}.generated-summary{grid-template-columns:1fr}.generated-section-head{display:block}.generated-section-head input{width:100%;margin-top:12px}.generated-site table{font-size:15px}.generated-site th,.generated-site td{white-space:nowrap}}
CSS;
}

function portal_render_html_studio(array $user): void
{
    if (!portal_is_admin($user)) {
        throw new LogicException('HTML studio requires administrator role.');
    }
    $initialMarkup = portal_html_studio_initial_markup();
    $initialCss = portal_html_studio_initial_css();
    $content = '<section class="html-studio" data-studio-root>'
        . '<div class="studio-intro"><div><p class="eyebrow">ADMINISTRATOR HTML STUDIO</p><h1>HTML 工作室</h1><p>先完成一張完整大圖，再與真正執行的 HTML 同尺寸比較；確認後可繼續標註、選取元件及修改程式碼。</p></div><span class="studio-local-state" data-studio-save-state>瀏覽器草稿</span></div>'
        . '<div class="studio-toolbar" role="toolbar" aria-label="HTML 工作室模式">'
        . '<button type="button" class="is-active" data-studio-mode="source">起點大圖</button>'
        . '<button type="button" data-studio-mode="compare">大圖／HTML 比較</button>'
        . '<button type="button" data-studio-mode="preview">真實預覽</button>'
        . '<button type="button" data-studio-mode="annotate">畫面標註</button>'
        . '<button type="button" data-studio-mode="inspect">選取元件</button>'
        . '<button type="button" data-studio-mode="code">HTML／CSS</button>'
        . '<span class="studio-toolbar-spacer"></span><div class="studio-devices" aria-label="預覽尺寸">'
        . '<button type="button" class="is-active" data-studio-device="desktop">桌面</button><button type="button" data-studio-device="tablet">平板</button><button type="button" data-studio-device="mobile">手機</button></div></div>'
        . '<div class="studio-layout">'
        . '<aside class="studio-steps" aria-label="工作流程"><h2>形成流程</h2><ol><li class="is-active">完成大圖</li><li>產生並比較 HTML</li><li>圈選與調整</li><li>驗證 RWD</li><li>下載版本</li></ol><button type="button" class="secondary-button" data-studio-reset>回到初始範例</button></aside>'
        . '<section class="studio-stage">'
        . '<div class="studio-stage-heading"><strong data-studio-stage-title>起點大圖</strong><span data-studio-stage-help>使用標準工具完成一張完整畫面，不必先拆成 HTML 元件。</span></div>'
        . '<div class="studio-source" data-studio-view="source"><div class="studio-draw-toolbar" role="toolbar" aria-label="大圖編輯工具">'
        . '<button type="button" class="is-active" data-draw-tool="select">選取</button><button type="button" data-draw-tool="pen">手繪</button><button type="button" data-draw-tool="rect">矩形</button><button type="button" data-draw-tool="text">文字</button>'
        . '<label class="studio-upload-button" for="studio-image-upload">放入圖片</label><input id="studio-image-upload" type="file" accept="image/png,image/jpeg,image/webp" hidden>'
        . '<span></span><button type="button" id="studio-selection-delete" disabled>刪除選取</button><button type="button" id="studio-draw-undo">復原</button><button type="button" id="studio-draw-clear">清除</button></div>'
        . '<div class="studio-canvas-shell"><canvas id="studio-source-canvas" width="1200" height="675" aria-label="完整起點大圖"></canvas><div class="studio-canvas-selection" data-studio-canvas-selection hidden aria-hidden="true"></div></div></div>'
        . '<div class="studio-compare" data-studio-view="compare" hidden><article><header>原始完整大圖 <span>1200 × 675</span></header><div><img id="studio-source-compare" alt="原始完整大圖"></div></article><article><header>真實 HTML 渲染 <span>1200 × 675</span></header><div><iframe id="studio-html-compare" sandbox="allow-same-origin" title="初始 HTML 比較預覽"></iframe></div></article></div>'
        . '<div class="studio-preview-frame" data-studio-view="preview" hidden><span data-studio-device-label>DESKTOP</span><iframe id="studio-preview" sandbox="allow-same-origin" title="HTML 工作室真實預覽"></iframe><div class="studio-inspect-highlight" data-studio-highlight hidden></div><div class="studio-annotation-layer" data-studio-annotation-layer hidden></div></div>'
        . '</section>'
        . '<aside class="studio-inspector">'
        . '<section data-studio-panel="source"><h2>先完成一張完整大圖</h2><p>手繪、矩形、文字與圖片都保留在同一張大圖；按下產生後，右側會執行真正的初始 HTML。</p><label>畫面目的<textarea data-studio-intent>建立一個近滿版、字體清楚的供水監測入口，包含狀態摘要、測站表格與主要操作。</textarea></label><button type="button" class="primary-button" id="studio-generate-html">依大圖產生初始 HTML</button></section>'
        . '<section data-studio-panel="compare" hidden><h2>同尺寸比較</h2><p>左邊保留原圖，右邊是真正 HTML；兩側使用相同 1200 × 675 邏輯尺寸。</p><button type="button" class="primary-button" data-studio-go="annotate">圈選 HTML 差異</button><button type="button" class="secondary-button" data-studio-go="code">查看初始程式碼</button></section>'
        . '<section data-studio-panel="preview" hidden><h2>真實 HTML</h2><p>中央 iframe 執行實際 DOM 與 CSS，不是圖片。此版本不執行使用者 JavaScript。</p><button type="button" class="primary-button" data-studio-go="inspect">選取真實元件</button></section>'
        . '<section data-studio-panel="annotate" hidden><h2>畫面標註</h2><p>在中央預覽拖曳框選，再填寫修改意圖。</p><p><strong data-studio-annotation-count>0 個標註</strong></p><label>修改說明<textarea data-studio-annotation-text placeholder="例如：桌面加寬，手機改成上下排列。"></textarea></label><button type="button" class="primary-button" data-studio-save-annotation>儲存說明</button><button type="button" class="secondary-button" data-studio-clear-annotations>清除標註</button></section>'
        . '<section data-studio-panel="inspect" hidden><h2>選取真實元件</h2><p>點擊中央 HTML 的文字、表格、按鈕或區塊。</p><dl><dt>元素</dt><dd data-studio-element-name>尚未選取</dd><dt>尺寸</dt><dd data-studio-element-size>—</dd></dl><label>直接修改文字<input data-studio-quick-text disabled></label><button type="button" class="primary-button" data-studio-apply-text disabled>套用文字</button></section>'
        . '<section data-studio-panel="code" hidden><h2>HTML／CSS</h2><label>HTML<textarea id="studio-html-code" spellcheck="false">' . portal_e($initialMarkup) . '</textarea></label><label>CSS<textarea id="studio-css-code" spellcheck="false">' . portal_e($initialCss) . '</textarea></label><button type="button" class="primary-button" data-studio-apply-code>更新真實預覽</button><button type="button" class="secondary-button" id="studio-download-html">下載 HTML</button></section>'
        . '</aside></div></section>';
    portal_render_page('HTML 工作室', $content, $user, false, 'html-studio-page', false, true);
}

(function () {
  'use strict';

  /*
   * 純前端驗收原型：正式版會將 documentType 與 lifecycle 改由 MSSQL 讀取。
   * lifecycle 的每一筆連結都可表示「本階段輸入」或「本階段產出」，
   * 因此同一份文件可以同時承接兩個階段。
   */
  var stages = [
    {
      id: '00',
      label: '00 跨階段共用',
      shortLabel: '跨階段共用',
      description: '跨越全專案生命週期的治理、追溯、決策與資安基線文件。',
      expectedInputs: ['專案背景與治理規範', '共用資安基線', '跨階段決策紀錄'],
      expectedOutputs: ['需求追溯矩陣', '共用規範與基線', '跨階段工作紀錄']
    },
    {
      id: '01',
      label: '01 規劃與需求分析',
      shortLabel: '規劃與需求',
      description: '將 RFP、原始需求、訪談與會議資訊整理為可追蹤的正式需求。',
      expectedInputs: ['RFP／原始需求', '訪談、會議與決策紀錄'],
      expectedOutputs: ['正式需求文件', '系統需求規格（SRS）', '可執行規格', '需求 Feature', '需求追溯矩陣']
    },
    {
      id: '02',
      label: '02 系統設計',
      shortLabel: '系統設計',
      description: '承接需求分析產出，形成資料、介面、API 與系統結構設計。',
      expectedInputs: ['Phase 01 正式需求與追溯輸出'],
      expectedOutputs: ['資料庫 Schema', 'ER 圖', 'API 規格', 'UI 雛型', 'UML 圖']
    },
    {
      id: '03',
      label: '03 開發與編碼',
      shortLabel: '開發與編碼',
      description: '依設計規格完成實作、程式碼檢核與單元測試。',
      expectedInputs: ['Phase 02 設計規格'],
      expectedOutputs: ['實作任務', '程式碼', '單元測試結果']
    },
    {
      id: '04',
      label: '04 測試驗證',
      shortLabel: '測試驗證',
      description: '以測試案例與自動化驗證確認功能與品質，並記錄問題修正。',
      expectedInputs: ['Phase 03 實作與單元測試輸出'],
      expectedOutputs: ['Bug 追蹤紀錄', 'pytest 測試報告', 'Playwright 測試報告']
    },
    {
      id: '05',
      label: '05 部署發布',
      shortLabel: '部署發布',
      description: '完成建置、部署、驗證與發布留存，確保可回溯的上線作業。',
      expectedInputs: ['Phase 04 測試驗證輸出'],
      expectedOutputs: ['建置產物清單', '部署紀錄', 'SHA-256 驗證報告']
    },
    {
      id: '06',
      label: '06 維護與營運',
      shortLabel: '維護與營運',
      description: '接續正式發布後的監控、異常處理、修補與營運紀錄。',
      expectedInputs: ['Phase 05 部署發布輸出'],
      expectedOutputs: ['故障分析', '修補紀錄', '營運日誌']
    }
  ];

  var documentTypes = [
    { id: 'requirements', label: '需求與規格', description: '需求、範圍、交付與追溯文件' },
    { id: 'meeting', label: '會議與決策', description: '訪談、會議紀錄與決策事項' },
    { id: 'planning', label: '規劃與提案', description: '規劃書、建議書與簡報' },
    { id: 'architecture', label: '系統／架構設計', description: '資料、API、UI 與系統設計' },
    { id: 'development', label: '開發與原始碼', description: '實作任務、程式碼與單元測試' },
    { id: 'testing', label: '測試與品質', description: '測試案例、驗收與品質報告' },
    { id: 'deployment', label: '部署與發布', description: '建置、部署與發布紀錄' },
    { id: 'operations', label: '維運與稽核', description: '監控、異常、修補與營運紀錄' },
    { id: 'security', label: '資安與稽核', description: '資安基線、檢核與稽核文件' },
    { id: 'system', label: '系統環境資訊', description: '主機、環境與系統設定資訊' }
  ];

  var documents = [
    {
      id: 'phase1',
      name: '第一階段交付工項.md',
      documentType: 'requirements',
      documentTypeLabel: '需求與規格',
      extension: 'md',
      typeLabel: 'Markdown 文件',
      size: '18 KB',
      updated: '2026-08-18 16:20',
      author: 'Discord 協作流程',
      description: '彙整第一階段需要完成的供水監測功能擴充項目。',
      lifecycle: [
        { stageId: '01', role: 'output' },
        { stageId: '02', role: 'input' }
      ],
      prompt: '請協助調整「第一階段交付工項.md」：請說明要修改的段落與內容。',
      preview:
        '<h2>第一階段交付工項</h2>' +
        '<p>本文件彙整供水監測專案第一階段需完成的功能擴充範圍，供團隊確認交付內容與後續追蹤使用。</p>' +
        '<h3>一、監測資料整合</h3>' +
        '<ul><li>介接既有供水監測資料來源，建立可追溯的資料收集流程。</li><li>提供監測站點、設備與量測資料的分類檢視。</li><li>建立異常資料辨識與記錄機制。</li></ul>' +
        '<h3>二、即時資訊呈現</h3>' +
        '<ul><li>提供監測資訊總覽與站點狀態查詢。</li><li>呈現最新更新時間，協助使用者判斷資料時效。</li><li>保留後續擴充告警與趨勢分析的介面位置。</li></ul>' +
        '<h3>三、文件協作與交付</h3>' +
        '<ul><li>由 Discord 對話確認文件需求與修訂內容。</li><li>將核准後的新產出文件列入受保護的文件庫。</li><li>使用者登入後可預覽或下載，不提供前台直接編輯。</li></ul>' +
        '<p class="callout"><strong>確認重點：</strong>若以上範圍需增加、刪除或調整優先順序，請複製右側指示並回 Discord 說明。</p>'
    },
    {
      id: 'meeting',
      name: '2026-08-18 專案週會紀錄.md',
      documentType: 'meeting',
      documentTypeLabel: '會議與決策',
      extension: 'md',
      typeLabel: 'Markdown 文件',
      size: '12 KB',
      updated: '2026-08-18 15:45',
      author: '專案協作小組',
      description: '記錄遠端連線管理系統、主機申請與文件協作平台討論事項。',
      lifecycle: [
        { stageId: '00', role: 'input' },
        { stageId: '01', role: 'input' }
      ],
      prompt: '請協助調整「2026-08-18 專案週會紀錄.md」：請說明要補充或修正的會議內容。',
      preview:
        '<h2>專案週會紀錄</h2>' +
        '<p><strong>日期：</strong>2026-08-18　<strong>主題：</strong>遠端連線管理與文件協作流程</p>' +
        '<h3>討論結論</h3>' +
        '<ul><li>三台 VM 主機已提出並取得相關環境資訊。</li><li>文件協作採 Discord 產出、受保護網頁瀏覽的方式進行。</li><li>初期先確認 HTML Page Flow，再串接 PHP、MSSQL 與實際帳號。</li></ul>' +
        '<h3>待辦事項</h3>' +
        '<ul><li>確認文件庫的分類方式與文件預覽需求。</li><li>確認帳號角色僅分為管理員與閱讀者是否足夠。</li><li>完成 IIS、PHP FastCGI、PDO_SQLSRV 與 MSSQL 的部署前置作業。</li></ul>'
    },
    {
      id: 'vm',
      name: '台水遠端連線管理系統主機資訊.xlsx',
      documentType: 'system',
      documentTypeLabel: '系統環境資訊',
      extension: 'xlsx',
      typeLabel: 'Excel 活頁簿',
      size: '24 KB',
      updated: '2026-08-18 14:30',
      author: '系統管理小組',
      description: '整理連線安全系統、密碼驗證及遠端控管伺服器的環境與作業系統。',
      lifecycle: [
        { stageId: '00', role: 'input' },
        { stageId: '05', role: 'input' }
      ],
      prompt: '請協助調整「台水遠端連線管理系統主機資訊.xlsx」：請說明要修正的主機資訊或欄位。',
      preview:
        '<h2>台水遠端連線管理系統主機資訊</h2>' +
        '<p>此類 Office 檔案在正式平台中將提供下載；若伺服器具備安全的預覽服務，可再加上內嵌預覽。</p>' +
        '<h3>主機摘要</h3>' +
        '<ul><li><strong>Web 安全服務主機：</strong>Windows Server（示意主機）。</li><li><strong>密碼驗證伺服器：</strong>保留供後續驗證服務使用。</li><li><strong>遠端控管伺服器：</strong>Ubuntu LTS（示意主機）。</li></ul>' +
        '<p class="callout"><strong>正式行為：</strong>Excel 檔案將透過已驗證的下載路徑提供，不會將文件來源直接公開為 IIS 虛擬目錄。</p>'
    },
    {
      id: 'proposal',
      name: '供水監測文件即時瀏覽平台建議方案.pptx',
      documentType: 'planning',
      documentTypeLabel: '規劃與提案',
      extension: 'pptx',
      typeLabel: 'PowerPoint 簡報',
      size: '1.8 MB',
      updated: '2026-08-17 20:10',
      author: '專案協作小組',
      description: '說明 Discord 協作、網頁文件瀏覽及帳號驗證的建議架構。',
      lifecycle: [
        { stageId: '01', role: 'output' },
        { stageId: '02', role: 'input' }
      ],
      prompt: '請協助調整「供水監測文件即時瀏覽平台建議方案.pptx」：請說明要修改的頁面或內容。',
      preview:
        '<h2>文件即時瀏覽平台建議方案</h2>' +
        '<p>PowerPoint 簡報的正式操作以受保護下載為主。本頁以文字摘要示範閱讀頁在非文字檔案時可呈現的資訊。</p>' +
        '<h3>建議架構</h3>' +
        '<ul><li>Discord：提出需求、取得協作回應、確認修改內容。</li><li>PHP + MSSQL：帳號驗證、角色控管、稽核與安全下載。</li><li>公開文件來源：僅存放後續可供團隊檢視的產出文件。</li></ul>'
    },
    {
      id: 'deployment',
      name: '遠端連線管理系統部署規劃.md',
      documentType: 'deployment',
      documentTypeLabel: '部署與發布',
      extension: 'md',
      typeLabel: 'Markdown 文件',
      size: '21 KB',
      updated: '2026-08-17 18:35',
      author: '系統管理小組',
      description: '彙整 IIS、PHP、MSSQL 與文件存取權限的部署準備工作。',
      lifecycle: [
        { stageId: '05', role: 'output' },
        { stageId: '06', role: 'input' }
      ],
      prompt: '請協助調整「遠端連線管理系統部署規劃.md」：請說明要調整的部署步驟。',
      preview:
        '<h2>遠端連線管理系統部署規劃</h2>' +
        '<h3>部署原則</h3>' +
        '<ul><li>IIS 對外提供 HTTPS 網站，PHP 負責驗證與文件讀取。</li><li>文件實體資料夾不直接映射為 IIS 虛擬目錄。</li><li>MSSQL 僅保存帳號、角色、登入控制與稽核資訊。</li></ul>' +
        '<h3>上線前檢核</h3>' +
        '<ul><li>安裝 PHP FastCGI、Microsoft ODBC Driver 與 PDO_SQLSRV。</li><li>建立最小權限的資料庫帳號與 IIS AppPool 檔案權限。</li><li>以測試帳號確認登入、文件預覽、下載與登出流程。</li></ul>'
    }
  ];

  function bySelector(selector, root) {
    return (root || document).querySelector(selector);
  }

  function allBySelector(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function showToast(message) {
    var toast = bySelector('#prototype-toast');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('visible');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(function () {
      toast.classList.remove('visible');
    }, 2800);
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
    });
  }

  function documentUrl(documentItem) {
    return 'document.html?doc=' + encodeURIComponent(documentItem.id);
  }

  function findStage(stageId) {
    return stages.filter(function (stage) { return stage.id === stageId; })[0] || null;
  }

  function findDocumentType(typeId) {
    return documentTypes.filter(function (type) { return type.id === typeId; })[0] || null;
  }

  function roleLabel(role, includeStageContext) {
    if (role === 'input') return includeStageContext ? '本階段輸入' : '輸入';
    return includeStageContext ? '本階段產出' : '產出';
  }

  function lifecycleFor(item, stageId, role) {
    return (item.lifecycle || []).filter(function (link) {
      return (stageId === 'all' || link.stageId === stageId) && (!role || role === 'all' || link.role === role);
    });
  }

  function matchesStageRole(item, stageId, role) {
    return lifecycleFor(item, stageId, role).length > 0;
  }

  function lifecycleSummary(item) {
    return (item.lifecycle || []).map(function (link) {
      var stage = findStage(link.stageId);
      return (stage ? stage.label : link.stageId) + '：' + roleLabel(link.role);
    }).join('；');
  }

  function stageNames(item) {
    var labels = [];
    (item.lifecycle || []).forEach(function (link) {
      var stage = findStage(link.stageId);
      var label = stage ? stage.label : link.stageId;
      if (labels.indexOf(label) === -1) labels.push(label);
    });
    return labels.join('、');
  }

  function makeLifecycleChips(item, selectedStageId) {
    var relevant = (item.lifecycle || []).filter(function (link) {
      return selectedStageId === 'all' || link.stageId === selectedStageId;
    });
    var links = relevant.length ? relevant : (item.lifecycle || []);
    return links.map(function (link) {
      var stage = findStage(link.stageId);
      var text = (stage ? stage.id : link.stageId) + ' ' + roleLabel(link.role);
      return '<span class="relation-chip ' + escapeHtml(link.role) + '">' + escapeHtml(text) + '</span>';
    }).join('');
  }

  function makeDocumentRow(item, selectedStageId) {
    return '<article class="document-row">' +
      '<span class="file-icon ' + escapeHtml(item.extension) + '">' + escapeHtml(item.extension.toUpperCase()) + '</span>' +
      '<div class="document-row-main">' +
        '<a class="document-row-title" href="' + documentUrl(item) + '">' + escapeHtml(item.name) + '</a>' +
        '<div class="document-row-detail">' + escapeHtml(item.description) + '</div>' +
        '<div class="document-row-tags"><span class="file-category">' + escapeHtml(item.documentTypeLabel) + '</span>' + makeLifecycleChips(item, selectedStageId) + '</div>' +
      '</div>' +
      '<div class="document-row-meta"><span>' + escapeHtml(item.typeLabel) + '</span><time>' + escapeHtml(item.updated) + '</time></div>' +
    '</article>';
  }

  function makeDocumentGroup(title, description, list, selectedStageId) {
    if (!list.length) {
      return '<section class="artifact-group empty-group"><div class="artifact-group-heading"><div><h3>' + escapeHtml(title) + '</h3><p>' + escapeHtml(description) + '</p></div><span class="count-badge">0 件</span></div><p class="group-empty-note">目前尚未列入對應文件；可依上方「預期文件」補齊。</p></section>';
    }
    return '<section class="artifact-group"><div class="artifact-group-heading"><div><h3>' + escapeHtml(title) + '</h3><p>' + escapeHtml(description) + '</p></div><span class="count-badge">' + list.length + ' 件</span></div><div class="artifact-group-rows">' + list.map(function (item) { return makeDocumentRow(item, selectedStageId); }).join('') + '</div></section>';
  }

  function renderDocumentRows(list, state) {
    var root = bySelector('#document-list');
    var count = bySelector('#documents-count');
    var badge = bySelector('#documents-badge');
    if (!root) return;

    if (count) count.textContent = '顯示 ' + list.length + ' / ' + documents.length + ' 件文件';
    if (badge) badge.textContent = list.length + ' 件';

    if (!list.length) {
      root.innerHTML = '<div class="empty-state"><strong>找不到符合條件的文件</strong><span>可改用其他階段、文件類型或清除關鍵字重新查看。</span></div>';
      return;
    }

    if (state.viewMode === 'stage' && state.stageId !== 'all' && state.ioRole === 'all') {
      var inputs = list.filter(function (item) { return matchesStageRole(item, state.stageId, 'input'); });
      var outputs = list.filter(function (item) { return matchesStageRole(item, state.stageId, 'output'); });
      root.innerHTML = makeDocumentGroup('先備輸入文件', '本階段開始前應確認或承接的文件。', inputs, state.stageId) +
        makeDocumentGroup('本階段產出文件', '完成本階段後，可供後續階段使用的文件。', outputs, state.stageId);
      return;
    }

    root.innerHTML = list.map(function (item) {
      return makeDocumentRow(item, state.viewMode === 'stage' ? state.stageId : 'all');
    }).join('');
  }

  function renderSidebar(state) {
    var eyebrow = bySelector('#filter-eyebrow');
    var heading = bySelector('#filter-heading');
    var list = bySelector('#library-filter-list');
    var tip = bySelector('#library-filter-tip');
    if (!list) return;

    if (state.viewMode === 'stage') {
      if (eyebrow) eyebrow.textContent = 'SSDLC STAGES';
      if (heading) heading.textContent = '選擇階段';
      if (tip) tip.innerHTML = '<strong>階段對應原則</strong><span>每份文件可同時標示為本階段產出與下一階段輸入，保留跨階段追溯關係。</span>';
      list.innerHTML = [{ id: 'all', label: '全部階段', description: '查看目前全部對應文件' }].concat(stages).map(function (entry) {
        var count = entry.id === 'all' ? documents.length : documents.filter(function (item) {
          return matchesStageRole(item, entry.id, 'all');
        }).length;
        return '<button class="category-button stage-filter-button' + (state.stageId === entry.id ? ' active' : '') + '" type="button" data-stage-filter="' + escapeHtml(entry.id) + '" title="' + escapeHtml(entry.description || entry.label) + '">' +
          '<span>' + escapeHtml(entry.label) + '</span><b>' + count + '</b></button>';
      }).join('');
      allBySelector('[data-stage-filter]', list).forEach(function (button) {
        button.addEventListener('click', function () {
          state.stageId = button.getAttribute('data-stage-filter') || 'all';
          state.ioRole = 'all';
          renderLibrary(state);
        });
      });
      return;
    }

    if (eyebrow) eyebrow.textContent = 'DOCUMENT TYPES';
    if (heading) heading.textContent = '選擇文件類型';
    if (tip) tip.innerHTML = '<strong>類型與格式分開管理</strong><span>例如「需求與規格」是文件類型；MD、XLSX、PPTX 則是檔案格式。</span>';
    list.innerHTML = [{ id: 'all', label: '全部文件類型', description: '查看目前全部文件' }].concat(documentTypes).map(function (entry) {
      var count = entry.id === 'all' ? documents.length : documents.filter(function (item) {
        return item.documentType === entry.id;
      }).length;
      return '<button class="category-button type-filter-button' + (state.typeId === entry.id ? ' active' : '') + '" type="button" data-type-filter="' + escapeHtml(entry.id) + '" title="' + escapeHtml(entry.description || entry.label) + '">' +
        '<span>' + escapeHtml(entry.label) + '</span><b>' + count + '</b></button>';
    }).join('');
    allBySelector('[data-type-filter]', list).forEach(function (button) {
      button.addEventListener('click', function () {
        state.typeId = button.getAttribute('data-type-filter') || 'all';
        renderLibrary(state);
      });
    });
  }

  function renderStageOverview(state) {
    var root = bySelector('#stage-overview');
    if (!root) return;

    if (state.viewMode !== 'stage') {
      root.hidden = true;
      return;
    }

    root.hidden = false;
    var title = bySelector('#stage-title', root);
    var eyebrow = bySelector('#stage-eyebrow', root);
    var description = bySelector('#stage-description', root);
    var expected = bySelector('#stage-expected-artifacts', root);
    var inputCount = bySelector('#stage-input-count', root);
    var outputCount = bySelector('#stage-output-count', root);
    var stage = state.stageId === 'all' ? null : findStage(state.stageId);
    var inputs = documents.filter(function (item) { return matchesStageRole(item, state.stageId, 'input'); }).length;
    var outputs = documents.filter(function (item) { return matchesStageRole(item, state.stageId, 'output'); }).length;

    if (inputCount) inputCount.textContent = inputs;
    if (outputCount) outputCount.textContent = outputs;
    allBySelector('[data-io-filter]', root).forEach(function (button) {
      var active = button.getAttribute('data-io-filter') === state.ioRole;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    if (!stage) {
      if (eyebrow) eyebrow.textContent = 'SSDLC OVERVIEW';
      if (title) title.textContent = '全部 SSDLC 階段';
      if (description) description.textContent = 'SSDLC-Skill 的 00 跨階段共用與 01–06 階段，皆以輸入與產出串接；點選左側任一階段可查看對應文件。';
      if (expected) {
        expected.innerHTML = '<div class="all-stage-grid">' + stages.map(function (entry) {
          var stageCount = documents.filter(function (item) { return matchesStageRole(item, entry.id, 'all'); }).length;
          return '<button class="all-stage-card" type="button" data-stage-card="' + entry.id + '"><span>' + entry.id + '</span><strong>' + escapeHtml(entry.shortLabel) + '</strong><small>' + stageCount + ' 件已對應</small></button>';
        }).join('') + '</div>';
        allBySelector('[data-stage-card]', expected).forEach(function (button) {
          button.addEventListener('click', function () {
            state.stageId = button.getAttribute('data-stage-card') || '01';
            state.ioRole = 'all';
            renderLibrary(state);
          });
        });
      }
      return;
    }

    if (eyebrow) eyebrow.textContent = 'SSDLC ' + stage.id;
    if (title) title.textContent = stage.label;
    if (description) description.textContent = stage.description;
    if (expected) {
      expected.innerHTML = '<div class="expected-artifact-set input-set"><span>README 建議輸入</span><div>' + stage.expectedInputs.map(function (item) {
        return '<i>' + escapeHtml(item) + '</i>';
      }).join('') + '</div></div>' +
        '<div class="expected-artifact-set output-set"><span>README 預期產出</span><div>' + stage.expectedOutputs.map(function (item) {
          return '<i>' + escapeHtml(item) + '</i>';
        }).join('') + '</div></div>';
    }
  }

  function renderListHeading(state) {
    var title = bySelector('#file-list-title');
    var description = bySelector('#file-list-description');
    if (!title || !description) return;

    if (state.viewMode === 'type') {
      var type = state.typeId === 'all' ? null : findDocumentType(state.typeId);
      title.textContent = type ? type.label + '文件' : '全部文件類型';
      description.textContent = type ? type.description + '；可由列內標籤查看所屬 SSDLC 階段與角色。' : '依文件用途瀏覽；檔案格式與 SSDLC 階段會顯示於列內標籤。';
      return;
    }

    var stage = state.stageId === 'all' ? null : findStage(state.stageId);
    if (!stage) {
      title.textContent = 'SSDLC 全部文件';
      description.textContent = '依目前選擇的角色與關鍵字篩選；選擇單一階段後會分為輸入與產出。';
      return;
    }
    title.textContent = stage.label + '文件';
    description.textContent = state.ioRole === 'input' ? '顯示本階段先備輸入文件。' : state.ioRole === 'output' ? '顯示本階段完成後的產出文件。' : '依輸入／產出關係分組，便於確認階段交接。';
  }

  function filterDocuments(state, keyword) {
    return documents.filter(function (item) {
      var viewMatches = state.viewMode === 'stage'
        ? matchesStageRole(item, state.stageId, state.ioRole)
        : (state.typeId === 'all' || item.documentType === state.typeId);
      if (!viewMatches) return false;
      if (!keyword) return true;
      var text = [item.name, item.documentTypeLabel, item.typeLabel, item.description, lifecycleSummary(item), stageNames(item)].join(' ').toLocaleLowerCase('zh-Hant');
      return text.indexOf(keyword) !== -1;
    });
  }

  function renderLibrary(state) {
    var search = bySelector('#document-search');
    var keyword = search ? search.value.trim().toLocaleLowerCase('zh-Hant') : '';
    renderSidebar(state);
    renderStageOverview(state);
    renderListHeading(state);
    renderDocumentRows(filterDocuments(state, keyword), state);
  }

  function initLibrary() {
    var root = bySelector('#document-list');
    if (!root) return;

    var state = { viewMode: 'stage', stageId: '01', typeId: 'all', ioRole: 'all' };
    var search = bySelector('#document-search');
    renderLibrary(state);

    allBySelector('[data-view-mode]').forEach(function (button) {
      button.addEventListener('click', function () {
        var mode = button.getAttribute('data-view-mode') || 'stage';
        state.viewMode = mode;
        state.ioRole = 'all';
        allBySelector('[data-view-mode]').forEach(function (entry) {
          var active = entry === button;
          entry.classList.toggle('active', active);
          entry.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        renderLibrary(state);
      });
    });

    allBySelector('[data-io-filter]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.ioRole = button.getAttribute('data-io-filter') || 'all';
        renderLibrary(state);
      });
    });

    allBySelector('[data-clear-library]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.stageId = '01';
        state.typeId = 'all';
        state.ioRole = 'all';
        if (search) search.value = '';
        renderLibrary(state);
      });
    });

    if (search) {
      search.addEventListener('input', function () {
        renderLibrary(state);
      });
    }

    allBySelector('[data-refresh-documents]').forEach(function (button) {
      button.addEventListener('click', function () {
        renderLibrary(state);
        showToast('已重新整理文件庫。正式版會在此取得最新文件與階段標記。');
      });
    });
  }

  function readDocument() {
    var parameters = new URLSearchParams(window.location.search);
    var selected = parameters.get('doc');
    var found = documents.filter(function (item) { return item.id === selected; })[0];
    return found || (selected ? null : documents[0]);
  }

  function setText(selector, value) {
    allBySelector(selector).forEach(function (element) {
      element.textContent = value;
    });
  }

  function renderDocumentLifecycle(item) {
    var root = bySelector('[data-document-lifecycle-list]');
    if (!root) return;
    root.innerHTML = (item.lifecycle || []).map(function (link) {
      var stage = findStage(link.stageId);
      return '<li class="' + escapeHtml(link.role) + '"><span>' + escapeHtml(link.stageId) + '</span><div><strong>' + escapeHtml(stage ? stage.shortLabel : link.stageId) + '</strong><small>' + escapeHtml(roleLabel(link.role, true)) + '</small></div></li>';
    }).join('');
  }

  function initDocument() {
    var target = bySelector('[data-document-body]');
    if (!target) return;
    var item = readDocument();
    if (!item) {
      window.location.replace('status.html#not-found');
      return;
    }

    document.title = item.name + '｜供水監測文件協作平台';
    setText('[data-document-name]', item.name);
    setText('[data-document-title]', item.name.replace(/\.[^.]+$/, ''));
    setText('[data-document-category]', item.documentTypeLabel);
    setText('[data-document-description]', item.description);
    setText('[data-document-format]', item.typeLabel);
    setText('[data-document-size]', item.size);
    setText('[data-document-updated]', item.updated);
    setText('[data-document-author]', item.author);
    setText('[data-document-icon]', item.extension.toUpperCase());
    setText('[data-document-stage-summary]', lifecycleSummary(item));
    setText('[data-document-stages]', stageNames(item));
    allBySelector('[data-document-icon]').forEach(function (element) {
      element.className = 'file-icon ' + item.extension;
    });
    target.innerHTML = item.preview;
    renderDocumentLifecycle(item);

    var prompt = bySelector('[data-discord-prompt]');
    var contextualPrompt = '【' + lifecycleSummary(item) + '】\n' + item.prompt;
    if (prompt) prompt.textContent = contextualPrompt;

    allBySelector('[data-copy-document-link]').forEach(function (button) {
      button.addEventListener('click', function () {
        copyText(window.location.href, '文件連結已複製。');
      });
    });

    allBySelector('[data-copy-discord-prompt]').forEach(function (button) {
      button.addEventListener('click', function () {
        copyText(contextualPrompt, 'Discord 修改指示已複製。');
      });
    });

    allBySelector('[data-demo-download]').forEach(function (button) {
      button.addEventListener('click', function () {
        showToast('這是原型：正式版會透過已驗證的路徑下載原始檔。');
      });
    });
  }

  function copyText(value, message) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(function () {
        showToast(message);
      }).catch(function () {
        showToast('瀏覽器未允許自動複製，請手動複製內容。');
      });
      return;
    }
    var temporary = document.createElement('textarea');
    temporary.value = value;
    temporary.setAttribute('readonly', '');
    temporary.style.position = 'fixed';
    temporary.style.opacity = '0';
    document.body.appendChild(temporary);
    temporary.select();
    try {
      document.execCommand('copy');
      showToast(message);
    } catch (error) {
      showToast('瀏覽器未允許自動複製，請手動複製內容。');
    }
    document.body.removeChild(temporary);
  }

  function initLogin() {
    var form = bySelector('[data-demo-login]');
    if (!form) return;
    var message = bySelector('[data-login-message]');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var username = form.elements.username.value.trim();
      var password = form.elements.password.value;
      if (!username || !password) {
        if (message) message.textContent = '請先填寫帳號與密碼，才能模擬登入流程。';
        return;
      }
      if (username.toLocaleLowerCase() === 'locked') {
        if (message) message.textContent = '帳號或密碼有誤，或帳號暫時無法登入。（示意錯誤狀態）';
        return;
      }
      if (message) message.textContent = '';
      window.location.href = 'documents.html';
    });
  }

  function initPasswordToggle() {
    allBySelector('[data-toggle-password]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = bySelector('input', button.parentElement);
        if (!input) return;
        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.textContent = isPassword ? '隱藏' : '顯示';
        button.setAttribute('aria-label', isPassword ? '隱藏密碼' : '顯示密碼');
      });
    });
  }

  function initMockActions() {
    allBySelector('[data-mock-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        showToast(button.getAttribute('data-mock-action'));
      });
    });
  }

  function initAccountDialog() {
    var dialog = bySelector('#account-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var title = bySelector('#account-dialog-title', dialog);
    var description = bySelector('#account-dialog-description', dialog);
    var username = bySelector('#account-username', dialog);

    allBySelector('[data-open-account-dialog]').forEach(function (button) {
      button.addEventListener('click', function () {
        var accountName = button.getAttribute('data-account-name');
        if (accountName) {
          title.textContent = '管理系統帳號';
          description.textContent = '此處示範檢視帳號、調整角色或啟用狀態的管理流程；尚未寫入資料庫。';
          username.value = accountName;
        } else {
          title.textContent = '新增系統帳號';
          description.textContent = '正式版會由管理員設定帳號、角色與初始密碼；密碼僅可於受控後台輸入。';
          username.value = '';
        }
        dialog.showModal();
      });
    });

    allBySelector('[data-close-account-dialog]', dialog).forEach(function (button) {
      button.addEventListener('click', function () {
        dialog.close();
      });
    });

    var form = bySelector('[data-demo-account-form]', dialog);
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        dialog.close();
        showToast('帳號儲存流程已完成示意；正式版才會寫入 MSSQL。');
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPasswordToggle();
    initLogin();
    initLibrary();
    initDocument();
    initMockActions();
    initAccountDialog();
  });
}());

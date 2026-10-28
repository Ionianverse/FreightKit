(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const RULE_KEY = 'freightkit.boRemarks.rules.v1';
  const PROCUREMENT_KEY = 'freightkit.boRemarks.procurement.v1';
  const DEFAULT_RULES = {
    priority: 'eta',
    transferBasis: 'transit',
    targetMonths: 3,
    plannedEnabled: true,
    plannedRemark: 'Planned Order, Without Allocation',
    plannedAction: 'ISR',
    stages: [
      { id: 'transfer', enabled: true, name: 'Transfer in transit', source: 'transfer', formFilter: '*', itemPattern: '*', processGroup: '', processField: 'wip', remark: 'stock on xfer {qty} pcs of {item} (transfer: {transfers})', action: '{transfers}' },
      { id: 'available', enabled: true, name: 'Available stock at destination', source: 'stock', formFilter: '*', itemPattern: '*', processGroup: '', processField: 'stock', remark: 'available in WH{wh}: {qty} pcs of {item}', action: '' },
      { id: 'process', enabled: false, name: 'Operation WIP (map a process pool)', source: 'process', formFilter: '*', itemPattern: '*', processGroup: '', processField: 'wip', remark: 'in {group} WIP: {qty} pcs of {item}', action: '' }
    ],
    vendors: [
      { enabled: true, pattern: 'BOLT*D', vendor: 'BHAM' },
      { enabled: true, pattern: 'MJGAS**D', vendor: 'Needs review: CHAMPION / SPEC' },
      { enabled: true, pattern: 'MJGASHP**D', vendor: 'WARCO' },
      { enabled: true, pattern: 'SGDPBOX**DD', vendor: 'Liberty Packaging' }
    ]
  };

  let rules = loadRules();
  let procurementOverrides = loadProcurementOverrides();
  let session = null;
  let visibleRows = [];

  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function loadRules() {
    try {
      const stored = JSON.parse(localStorage.getItem(RULE_KEY) || 'null');
      if (stored && Array.isArray(stored.stages) && Array.isArray(stored.vendors)) {
        return { ...clone(DEFAULT_RULES), ...stored, stages: stored.stages, vendors: stored.vendors };
      }
    } catch (_) { /* Start with the safe defaults if saved data is unreadable. */ }
    return clone(DEFAULT_RULES);
  }
  function loadProcurementOverrides() {
    try { return JSON.parse(localStorage.getItem(PROCUREMENT_KEY) || '{}') || {}; } catch (_) { return {}; }
  }
  function saveProcurementOverrides() {
    try { localStorage.setItem(PROCUREMENT_KEY, JSON.stringify(procurementOverrides)); } catch (_) { /* Private browsing may block storage. */ }
  }
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  }
  function clean(value) { return String(value == null ? '' : value).trim(); }
  function norm(value) { return clean(value).toLowerCase().replace(/[^a-z0-9]/g, ''); }
  function num(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    const s = clean(value).replace(/[,$]/g, '');
    if (!s || s === '-') return 0;
    const parsed = Number(s);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  function fmt(value) { return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
  function headIndex(headers, candidates) {
    const h = headers.map(norm);
    for (const candidate of candidates) {
      const i = h.indexOf(norm(candidate));
      if (i >= 0) return i;
    }
    return -1;
  }
  function headerScore(row, neededGroups) {
    const h = (row || []).map(norm);
    return neededGroups.reduce((score, group) => score + (group.some((x) => h.includes(norm(x))) ? 1 : 0), 0);
  }
  function findHeader(matrix, required, limit = 30) {
    let best = null;
    for (let r = 0; r < Math.min(matrix.length, limit); r++) {
      const score = headerScore(matrix[r], required);
      if (!best || score > best.score) best = { row: r, score };
      if (score === required.length) return { row: r, score };
    }
    return best;
  }
  function nonemptyRows(ws) {
    return XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: true, blankrows: true });
  }
  function fileExtension(file) { return file.name.toLowerCase().split('.').pop(); }
  async function readFileWorkbook(file) {
    if (!file) throw new Error('Choose a file first.');
    if (file.size > 30 * 1024 * 1024) throw new Error(`${file.name} is larger than the 30 MB browser limit.`);
    const ext = fileExtension(file);
    if (ext === 'csv' || ext === 'tsv' || file.type === 'text/csv') {
      const bytes = new Uint8Array(await file.arrayBuffer());
      const isUtf16 = (bytes[0] === 0xff && bytes[1] === 0xfe) || (bytes[0] === 0xfe && bytes[1] === 0xff);
      const text = new TextDecoder(isUtf16 ? (bytes[0] === 0xfe ? 'utf-16be' : 'utf-16le') : 'utf-8').decode(bytes);
      const firstLine = text.split(/\r?\n/, 1)[0] || '';
      const workbook = XLSX.read(text.replace(/^\uFEFF/, ''), { type: 'string', FS: firstLine.includes('\t') ? '\t' : ',', raw: true, cellDates: true });
      return { workbook, sourceType: 'text' };
    }
    const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array', raw: true, cellDates: true, cellFormula: true });
    return { workbook, sourceType: 'workbook' };
  }
  function getRowValue(row, index) { return index >= 0 && row ? row[index] : ''; }
  function prettyDate(value) {
    if (value instanceof Date && Number.isFinite(value.getTime())) return value.toLocaleDateString();
    if (typeof value === 'number' && window.XLSX && XLSX.SSF) {
      const parsed = XLSX.SSF.parse_date_code(value);
      if (parsed) return `${String(parsed.m).padStart(2, '0')}/${String(parsed.d).padStart(2, '0')}/${parsed.y}`;
    }
    return clean(value);
  }
  function dateSortValue(value) {
    if (value instanceof Date) return value.getTime();
    if (typeof value === 'number' && value > 10000 && value < 100000) return (value - 25569) * 86400000;
    const s = clean(value);
    if (!s) return Number.POSITIVE_INFINITY;
    const parsed = Date.parse(s);
    if (Number.isFinite(parsed)) return parsed;
    const m = s.match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})$/);
    if (m) {
      let year = Number(m[3]);
      if (year < 100) year += 2000;
      return new Date(year, Number(m[1]) - 1, Number(m[2])).getTime();
    }
    return Number.POSITIVE_INFINITY;
  }
  function locateBoSheet(workbook) {
    const candidates = [];
    for (const name of workbook.SheetNames) {
      const matrix = nonemptyRows(workbook.Sheets[name]);
      const hit = findHeader(matrix, [
        ['Item'], ['Order#', 'Order #', 'Customer Order #'], ['Line#', 'Line #'], ['BO', 'Quantity'], ['Remarks', 'Remark'], ['Actions', 'Action']
      ], 35);
      candidates.push({ name, matrix, headerRow: hit ? hit.row : 0, score: hit ? hit.score : 0 });
    }
    candidates.sort((a, b) => b.score - a.score || a.headerRow - b.headerRow);
    return candidates[0] || null;
  }
  function mapBoRows(matrix, headerRow, sheetName) {
    const headers = matrix[headerRow] || [];
    const col = {
      item: headIndex(headers, ['Item', 'Material']),
      warehouse: headIndex(headers, ['WH', 'Whse/Branch', 'Warehouse', 'WHSE']),
      qty: headIndex(headers, ['BO', 'Quantity', 'Back Order Qty', 'Qty']),
      order: headIndex(headers, ['Order#', 'Order #', 'Customer Order #', 'Customer Order']),
      line: headIndex(headers, ['Line#', 'Line #', 'Line']),
      status: headIndex(headers, ['Status', 'Co Line Status']),
      allocate: headIndex(headers, ['Allocate']),
      eta: headIndex(headers, ['New ETA Date', 'New ETADate', 'Current ETA', 'Promise Date', 'Due Date', 'ETA']),
      remark: headIndex(headers, ['Remarks', 'Remark']),
      action: headIndex(headers, ['Actions', 'Action']),
      mainItem: headIndex(headers, ['Main item', 'Main Item']),
      form: headIndex(headers, ['Form'])
    };
    if (col.item < 0 || col.qty < 0 || col.remark < 0) throw new Error(`The selected BO sheet “${sheetName}” needs Item, BO/Quantity, and Remarks columns.`);
    const rows = [];
    for (let r = headerRow + 1; r < matrix.length; r++) {
      const raw = matrix[r];
      const item = clean(getRowValue(raw, col.item));
      if (!item) continue;
      const qty = num(getRowValue(raw, col.qty));
      if (!qty && !clean(getRowValue(raw, col.order)) && !clean(getRowValue(raw, col.line))) continue;
      rows.push({
        index: rows.length,
        sourceRow: r,
        excelRow: r + 1,
        sheetName,
        item,
        warehouse: clean(getRowValue(raw, col.warehouse)),
        qty,
        order: clean(getRowValue(raw, col.order)),
        line: clean(getRowValue(raw, col.line)),
        status: clean(getRowValue(raw, col.status)),
        allocate: clean(getRowValue(raw, col.allocate)),
        mainItem: clean(getRowValue(raw, col.mainItem)),
        form: clean(getRowValue(raw, col.form)),
        eta: getRowValue(raw, col.eta),
        remark: clean(getRowValue(raw, col.remark)),
        action: clean(getRowValue(raw, col.action)),
        columnMap: col
      });
    }
    return { rows, columns: col, headers };
  }
  function inspectStockWorkbook(workbook) {
    const tables = [];
    const stageSheets = [];
    const processRows = [];
    const processPoolNames = new Set();
    for (const name of workbook.SheetNames) {
      const matrix = nonemptyRows(workbook.Sheets[name]);
      const hit = findHeader(matrix, [['Item'], ['Whse/Branch', 'Warehouse', 'WHSE'], ['Available']], 15);
      if (hit && hit.score === 3) {
        const headers = matrix[hit.row] || [];
        const item = headIndex(headers, ['Item']);
        const warehouse = headIndex(headers, ['Whse/Branch', 'Warehouse', 'WHSE']);
        const available = headIndex(headers, ['Available']);
        const canMake = headIndex(headers, ['Can Make']);
        const onTransfer = headIndex(headers, ['On Transfer']);
        const onOrder = headIndex(headers, ['On Order']);
        const grn = headIndex(headers, ['GRN Lines', 'GRN  Lines']);
        const nonNettable = headIndex(headers, ['Non-Nettable Stock', 'Non-Nettable']);
        const forecast = headIndex(headers, ['Ave Forecast', 'Average Forecast']);
        for (let r = hit.row + 1; r < matrix.length; r++) {
          const raw = matrix[r];
          const keyItem = clean(getRowValue(raw, item));
          const keyWh = clean(getRowValue(raw, warehouse));
          if (!keyItem || !keyWh) continue;
          tables.push({
            item: keyItem,
            warehouse: keyWh,
            available: num(getRowValue(raw, available)),
            canMake: num(getRowValue(raw, canMake)),
            onTransfer: num(getRowValue(raw, onTransfer)),
            onOrder: num(getRowValue(raw, onOrder)),
            grn: num(getRowValue(raw, grn)),
            nonNettable: num(getRowValue(raw, nonNettable)),
            forecast: num(getRowValue(raw, forecast)),
            sheet: name,
            excelRow: r + 1
          });
        }
      }
      const inventoryHeaderRows = matrix.slice(0, 3);
      const hasItemBlocks = inventoryHeaderRows.some((row) => row.some((v) => norm(v) === 'item'));
      if (hasItemBlocks && /inventory/i.test(name)) {
        stageSheets.push(name);
        const headerRowIndex = inventoryHeaderRows.findIndex((row) => row.some((v) => norm(v) === 'item'));
        const stageHeaders = inventoryHeaderRows[headerRowIndex] || [];
        const itemColumns = [];
        stageHeaders.forEach((value, index) => { if (norm(value) === 'item') itemColumns.push(index); });
        for (const itemCol of itemColumns) {
          const nextItem = itemColumns.find((col) => col > itemCol);
          const stop = nextItem == null ? stageHeaders.length : nextItem;
          const group = clean(matrix[0]?.[itemCol]) || `Inventory block ${XLSX.utils.encode_col(itemCol)}`;
          const fieldCols = {};
          for (let c = itemCol + 1; c < stop; c++) {
            const header = norm(stageHeaders[c]);
            if (header === 'stock') fieldCols.stock = c;
            else if (header === 'wip') fieldCols.wip = c;
            else if (header === 'onxfer' || header === 'ontransfer') fieldCols.onTransfer = c;
            else if (header === 'nsqcstock' || header === 'nonnettablestock') fieldCols.nonNettable = c;
            else if (header === 'ongrn' || header === 'grn') fieldCols.grn = c;
          }
          processPoolNames.add(group);
          for (let r = headerRowIndex + 1; r < matrix.length; r++) {
            const raw = matrix[r];
            const item = clean(raw[itemCol]);
            if (!item) continue;
            const record = { item, warehouse: '*', group, sheet: name, stock: 0, wip: 0, onTransfer: 0, nonNettable: 0, grn: 0 };
            Object.entries(fieldCols).forEach(([field, col]) => { record[field] = num(raw[col]); });
            processRows.push(record);
          }
        }
      }
    }
    return { tables, stageSheets, processRows, processPoolNames: [...processPoolNames] };
  }
  function mapTransfers(matrix) {
    const hit = findHeader(matrix, [['Transfer'], ['Item'], ['Quantity'], ['To Whse', 'Destination Warehouse'], ['Shipped'], ['Received']], 10);
    if (!hit || hit.score < 5) throw new Error('Transfer file needs Transfer, Item, Quantity, To Whse, and at least one of Shipped/Received.');
    const headers = matrix[hit.row] || [];
    const col = {
      transfer: headIndex(headers, ['Transfer', 'Transfer #', 'Transfer Number']),
      line: headIndex(headers, ['Line', 'Line #']),
      item: headIndex(headers, ['Item', 'Material']),
      qty: headIndex(headers, ['Quantity', 'Qty']),
      wh: headIndex(headers, ['To Whse', 'To Warehouse', 'Destination Warehouse', 'Warehouse']),
      shipped: headIndex(headers, ['Shipped', 'Qty Shipped']),
      received: headIndex(headers, ['Received', 'Qty Received']),
      type: headIndex(headers, ['Type']),
      date: headIndex(headers, ['Date', 'Transfer Date', 'Ship Date', 'ETA', 'Expected Date'])
    };
    if (col.transfer < 0 || col.item < 0 || col.qty < 0 || col.wh < 0) throw new Error('Could not map required transfer columns.');
    const rows = [];
    for (let r = hit.row + 1; r < matrix.length; r++) {
      const raw = matrix[r];
      const item = clean(getRowValue(raw, col.item));
      if (!item) continue;
      rows.push({
        item, warehouse: clean(getRowValue(raw, col.wh)), transfer: clean(getRowValue(raw, col.transfer)),
        line: clean(getRowValue(raw, col.line)), quantity: num(getRowValue(raw, col.qty)),
        shipped: num(getRowValue(raw, col.shipped)), received: num(getRowValue(raw, col.received)),
        type: clean(getRowValue(raw, col.type)), date: getRowValue(raw, col.date), sourceRow: r + 1
      });
    }
    return { rows, dateColumn: col.date >= 0 };
  }
  function poolKey(item, wh) { return `${norm(item)}|${norm(wh)}`; }
  function groupByPool(records, quantityFn) {
    const map = new Map();
    for (const row of records) {
      const key = poolKey(row.item, row.warehouse);
      const quantity = Math.max(0, quantityFn(row));
      if (!map.has(key)) map.set(key, { quantity: 0, records: [] });
      const pool = map.get(key);
      pool.quantity += quantity;
      if (quantity > 0) pool.records.push({ row, remaining: quantity });
    }
    return map;
  }
  function takeFromPool(map, key, needed) {
    const pool = map.get(key);
    if (!pool || needed <= 0) return { qty: 0, records: [] };
    let remaining = Math.min(needed, pool.quantity);
    let takenQty = 0;
    const used = [];
    for (const allocation of pool.records) {
      if (remaining <= 0) break;
      if (allocation.remaining <= 0) continue;
      const take = Math.min(remaining, allocation.remaining);
      allocation.remaining -= take;
      pool.quantity -= take;
      remaining -= take;
      takenQty += take;
      used.push({ row: allocation.row, qty: take });
    }
    return { qty: takenQty, records: used };
  }
  function eligibleTransfer(row) {
    if (rules.transferBasis === 'unshipped') return Math.max(0, row.quantity - row.shipped);
    if (rules.transferBasis === 'outstanding') return Math.max(0, row.quantity - row.received);
    return Math.max(0, row.shipped - row.received);
  }
  function renderTemplate(template, data) {
    return clean(template).replace(/\{(item|qty|wh|transfers|group)\}/g, (_, token) => clean(data[token]));
  }
  function plannedUnallocated(row) {
    return norm(row.status) === 'planned' && ['no', 'n', 'false', '0'].includes(norm(row.allocate));
  }
  function matchesPatternList(patternList, value) {
    const patterns = clean(patternList).split(',').map((x) => x.trim()).filter(Boolean);
    return !patterns.length || patterns.some((pattern) => wildcardMatch(pattern, value));
  }
  function extractBom(workbook) {
    for (const name of workbook.SheetNames) {
      const matrix = nonemptyRows(workbook.Sheets[name]);
      const hit = findHeader(matrix, [['Item'], ['Material'], ['Per Quantity']], 20);
      if (!hit || hit.score < 3) continue;
      const headers = matrix[hit.row] || [];
      const parentCol = headIndex(headers, ['Item']);
      const materialCol = headIndex(headers, ['Material']);
      const qtyCol = headIndex(headers, ['Per Quantity']);
      const map = new Map();
      for (let r = hit.row + 1; r < matrix.length; r++) {
        const raw = matrix[r];
        const parent = clean(raw[parentCol]);
        const material = clean(raw[materialCol]);
        const per = num(raw[qtyCol]);
        if (!parent || !material || per <= 0) continue;
        const key = norm(parent);
        if (!map.has(key)) map.set(key, []);
        map.get(key).push({ item: material, per, sourceRow: r + 1, sheet: name });
      }
      if (map.size) return { map, sheet: name, rows: [...map.values()].reduce((n, list) => n + list.length, 0) };
    }
    return { map: new Map(), sheet: '', rows: 0 };
  }
  function buildSuggestions(boRows, stockRows, transferRows, processRows = [], bomInfo = { map: new Map() }) {
    const stockPool = groupByPool(stockRows, (r) => r.available);
    const transferPool = groupByPool(transferRows, eligibleTransfer);
    const processPools = new Map();
    for (const stage of rules.stages.filter((s) => s.source === 'process' && s.processGroup)) {
      const records = processRows.filter((r) => norm(r.group) === norm(stage.processGroup));
      processPools.set(norm(stage.processGroup), groupByPool(records, (r) => num(r[stage.processField || 'wip'])));
    }
    const ordered = [...boRows].sort((a, b) => {
      if (rules.priority !== 'eta') return a.sourceRow - b.sourceRow;
      return dateSortValue(a.eta) - dateSortValue(b.eta) || a.sourceRow - b.sourceRow;
    });
    for (const row of ordered) {
      row.suggestedRemark = '';
      row.suggestedAction = '';
      row.reasonParts = [];
      row.coveredQty = 0;
      row.remainingQty = Math.max(0, row.qty);
      row.ruleStatus = 'No mapped rule';
      if (rules.plannedEnabled && plannedUnallocated(row)) {
        row.suggestedRemark = clean(rules.plannedRemark);
        row.suggestedAction = clean(rules.plannedAction);
        row.reasonParts.push('Status is Planned and Allocate is NO.');
        row.remainingQty = 0;
        row.coveredQty = row.qty;
        row.ruleStatus = 'Planned / unallocated rule';
        continue;
      }
      const bomRows = norm(row.item) === norm(row.mainItem) ? (bomInfo.map.get(norm(row.item)) || []) : [];
      const requests = bomRows.length ? bomRows.map((entry) => ({ item: entry.item, qty: row.qty * entry.per, parent: row.item, per: entry.per })) : [{ item: row.item, qty: row.qty, parent: '', per: 1 }];
      row.usedBom = bomRows.length > 0;
      const rowRemarks = [];
      const rowActions = [];
      let totalRemaining = 0;
      for (const request of requests) {
        let remaining = Math.max(0, request.qty);
        if (row.usedBom) row.reasonParts.push(`BO material map: ${request.item} × ${fmt(request.per)} per ${row.item}.`);
        for (const stage of rules.stages) {
          if (!stage.enabled || remaining <= 0 || !matchesPatternList(stage.formFilter, row.form) || !matchesPatternList(stage.itemPattern, request.item)) continue;
          let source;
          let key;
          if (stage.source === 'transfer') { source = transferPool; key = poolKey(request.item, row.warehouse); }
          else if (stage.source === 'stock') { source = stockPool; key = poolKey(request.item, row.warehouse); }
          else if (stage.source === 'process') { source = processPools.get(norm(stage.processGroup)); key = poolKey(request.item, '*'); }
          else {
            row.reasonParts.push(`${stage.name}: no quantity source is configured.`);
            continue;
          }
          if (!source) {
            row.reasonParts.push(`${stage.name}: process pool “${stage.processGroup || 'not set'}” is not mapped in the loaded stock workbook.`);
            continue;
          }
          const taken = takeFromPool(source, key, remaining);
          if (taken.qty <= 0) continue;
          row.coveredQty += taken.qty;
          remaining = Math.max(0, remaining - taken.qty);
          const ids = [...new Set(taken.records.map((x) => x.row.transfer).filter(Boolean))].join(', ');
          const templateData = { item: request.item, qty: fmt(taken.qty), wh: row.warehouse, transfers: ids, group: stage.processGroup || stage.name };
          const remark = renderTemplate(stage.remark, templateData);
          const action = renderTemplate(stage.action, templateData);
          if (remark) rowRemarks.push(remark);
          if (action) rowActions.push(action);
          if (stage.source === 'transfer') {
            const sourceDetails = taken.records.map((x) => `${x.row.transfer}${x.row.line ? `/${x.row.line}` : ''}: ${fmt(x.qty)} pcs`).join(', ');
            row.reasonParts.push(`${stage.name} for ${request.item}: ${fmt(taken.qty)} pcs; ${sourceDetails || 'matched transfer rows'}.`);
          } else if (stage.source === 'stock') {
            const sourceSheets = [...new Set(taken.records.map((x) => x.row.sheet))].join(', ');
            row.reasonParts.push(`${stage.name} for ${request.item}: ${fmt(taken.qty)} pcs from Available at ${sourceSheets || `WH ${row.warehouse}`}.`);
          } else {
            row.reasonParts.push(`${stage.name} for ${request.item}: ${fmt(taken.qty)} pcs from ${stage.processGroup} / ${stage.processField}.`);
          }
        }
        if (remaining > 0) {
          totalRemaining += remaining;
          if (rowRemarks.length) rowRemarks.push(`${fmt(remaining)} pcs of ${request.item} still need a supply rule`);
        }
      }
      row.remainingQty = totalRemaining;
      row.suggestedRemark = rowRemarks.join('; ');
      row.suggestedAction = [...new Set(rowActions)].join(', ');
      if (row.coveredQty > 0 && row.remainingQty <= 0) row.ruleStatus = 'Mapped sources cover BO quantity';
      else if (row.coveredQty > 0) row.ruleStatus = 'Partial quantity mapped';
      else if (row.reasonParts.length || row.usedBom) row.ruleStatus = 'Needs rule configuration';
    }
    return ordered.map((row) => {
      row.hasSuggestion = Boolean(row.suggestedRemark && row.suggestedRemark !== row.remark);
      row.useSuggestion = false;
      if (row.suggestedRemark === row.remark && row.suggestedRemark) row.ruleStatus = 'Already matches';
      return row;
    });
  }

  function chooseHeaderSheet(workbook, preferredName) {
    if (preferredName && workbook.Sheets[preferredName]) {
      const matrix = nonemptyRows(workbook.Sheets[preferredName]);
      const hit = findHeader(matrix, [['Item'], ['Order#', 'Order #', 'Customer Order #'], ['Line#', 'Line #'], ['BO', 'Quantity'], ['Remarks', 'Remark']], 40);
      if (hit && hit.score >= 4) return { name: preferredName, matrix, headerRow: hit.row, score: hit.score };
    }
    return locateBoSheet(workbook);
  }
  async function analyzeFiles(event) {
    event.preventDefault();
    const boFile = $('bo-file').files[0];
    const stockFile = $('stock-file').files[0];
    const transferFile = $('transfer-file').files[0];
    setStatus('Reading source files in this browser…');
    $('bo-results').hidden = true;
    try {
      if (!boFile || !stockFile || !transferFile) throw new Error('Select the BO report, stock workbook, and transfer export.');
      if (!window.XLSX) throw new Error('The local spreadsheet reader did not load. Refresh the page and try again.');
      const [boRead, stockRead, transferRead] = await Promise.all([readFileWorkbook(boFile), readFileWorkbook(stockFile), readFileWorkbook(transferFile)]);
      const boSheet = chooseHeaderSheet(boRead.workbook, $('bo-sheet-choice')?.value || '');
      if (!boSheet || boSheet.score < 4) throw new Error('Could not find a BO sheet with order, item, quantity, and remarks columns.');
      const boParsed = mapBoRows(boSheet.matrix, boSheet.headerRow, boSheet.name);
      const stockInfo = inspectStockWorkbook(stockRead.workbook);
      if (!stockInfo.tables.length) throw new Error('Could not find a stock table with Item, Whse/Branch, and Available columns.');
      const transferSheetName = transferRead.workbook.SheetNames[0];
      const transferMatrix = nonemptyRows(transferRead.workbook.Sheets[transferSheetName]);
      const transferInfo = mapTransfers(transferMatrix);
      const bomInfo = extractBom(boRead.workbook);
      const rows = buildSuggestions(boParsed.rows, stockInfo.tables, transferInfo.rows, stockInfo.processRows, bomInfo);
      const pivot = extractProcurement(boRead.workbook);
      session = {
        boFile, boWorkbook: boRead.workbook, boSheetName: boSheet.name, boHeaderRow: boSheet.headerRow,
        boColumns: boParsed.columns, rows, stockSheets: [...new Set(stockInfo.tables.map((r) => r.sheet))],
        stockRows: stockInfo.tables.length, stockRowsData: stockInfo.tables, stageSheets: stockInfo.stageSheets,
        processRowsData: stockInfo.processRows, processPoolNames: stockInfo.processPoolNames, bomInfo,
        transferRows: transferInfo.rows, transferHasDate: transferInfo.dateColumn,
        procurement: pivot, boSourceType: boRead.sourceType
      };
      renderRuleStages();
      renderResults();
      setStatus(`Loaded ${rows.length.toLocaleString()} BO lines from “${boSheet.name}”, ${stockInfo.tables.length.toLocaleString()} stock records, and ${transferInfo.rows.length.toLocaleString()} transfer rows.`, true);
      $('bo-results').hidden = false;
      if (typeof $('bo-results').scrollIntoView === 'function') $('bo-results').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
      setStatus(error.message || 'Could not analyze these files.', false, true);
    }
  }
  function setStatus(message, success = false, isError = false) {
    const el = $('bo-status');
    el.textContent = message;
    el.classList.toggle('is-success', success);
    el.classList.toggle('is-error', isError);
  }
  function drawKpi(label, value, note = '') {
    return `<div class="card"><span class="bo-kpi-label">${esc(label)}</span><strong class="bo-kpi-value">${esc(value)}</strong>${note ? `<span class="muted-small">${esc(note)}</span>` : ''}</div>`;
  }
  function renderResults() {
    if (!session) return;
    const rows = session.rows;
    const changes = rows.filter((r) => r.hasSuggestion).length;
    const unsupported = rows.filter((r) => !r.hasSuggestion && r.ruleStatus !== 'Already matches').length;
    const covered = rows.filter((r) => r.ruleStatus === 'Mapped sources cover BO quantity').length;
    $('bo-kpis').innerHTML = [
      drawKpi('BO lines read', fmt(rows.length), `${session.boSheetName}, header row ${session.boHeaderRow + 1}`),
      drawKpi('Draft changes', fmt(changes), 'Unchecked until you select them'),
      drawKpi('Fully covered', fmt(covered), 'By the currently mapped stages'),
      drawKpi('Needs a rule or review', fmt(unsupported), 'No supported draft is hidden')
    ].join('');
    const warnings = [];
    if (!session.transferHasDate) warnings.push('The transfer file has no transfer date/ETA column. Drafts can include transfer IDs but will not invent a date.');
    if (!session.stageSheets.length) warnings.push('No separate Inventory process schedule was detected. Assembly, SB line, MT, and component paths are not mapped by the current exact-item rules.');
    if (session.bomInfo.rows) warnings.push(`Found ${session.bomInfo.rows.toLocaleString()} parent-to-material rows in “${session.bomInfo.sheet}”. Pack/kit lines can be expanded to those component quantities for review.`);
    else warnings.push('No Item / Material / Per Quantity component map was found in the BO workbook. Parent pack/kit rows will stay unassigned until a component map is available.');
    if (session.boSourceType === 'text') warnings.push('The BO source is text/CSV. It will export as an Excel workbook so the review and source columns stay together.');
    if (session.procurement && !session.procurement.rows.length) warnings.push('A material pivot was found, but it has no calculated need-to-order quantities available to this browser.');
    $('bo-warnings').innerHTML = warnings.map((w) => `<div class="bo-warning">${esc(w)}</div>`).join('');
    filterAndRenderRows();
    renderProcurement();
  }
  function rowIdentity(row) { return `${row.order || '—'} / ${row.line || '—'}`; }
  function rowHtml(row) {
    const statusClass = row.ruleStatus === 'Mapped sources cover BO quantity' || row.ruleStatus === 'Already matches' ? 'covered' : row.hasSuggestion ? 'changed' : '';
    const statusText = row.ruleStatus === 'Mapped sources cover BO quantity' ? 'Covered' : row.ruleStatus === 'Already matches' ? 'Matches' : row.hasSuggestion ? 'Draft' : row.ruleStatus;
    const evidence = row.reasonParts.length ? row.reasonParts.join(' ') : 'No exact item + warehouse source or enabled rule matched this BO line.';
    return `<tr data-row-index="${row.index}" class="${row.hasSuggestion ? 'has-change' : 'no-change'}">
      <td><input type="checkbox" class="row-approve" aria-label="Approve suggestion for ${esc(rowIdentity(row))}" ${row.useSuggestion ? 'checked' : ''} ${row.hasSuggestion ? '' : 'disabled'}></td>
      <td><strong>${esc(rowIdentity(row))}</strong><span class="bo-row-sub">${esc(row.status || 'Status not mapped')} · source row ${row.excelRow}</span><span class="row-status ${statusClass}">${esc(statusText)}</span></td>
      <td><span class="bo-row-item">${esc(row.item)}</span><span class="bo-row-sub">BO ${esc(fmt(row.qty))} · WH ${esc(row.warehouse || '—')}</span><span class="bo-row-sub">ETA ${esc(prettyDate(row.eta) || 'not set')}</span></td>
      <td class="bo-current">${esc(row.remark || '—')}</td>
      <td class="bo-suggestion ${row.hasSuggestion ? '' : 'bo-no-suggestion'}">${esc(row.suggestedRemark || 'No draft — keep current text and review/configure a rule.')}${row.suggestedAction ? `<span class="bo-row-sub">Action: ${esc(row.suggestedAction)}</span>` : ''}</td>
      <td>${esc(evidence)}${row.remainingQty > 0 && row.coveredQty > 0 ? `<span class="bo-row-sub">Unmapped remainder: ${esc(fmt(row.remainingQty))}</span>` : ''}</td>
    </tr>`;
  }
  function filterAndRenderRows() {
    if (!session) return;
    const mode = $('review-filter').value;
    const q = clean($('review-search').value).toLocaleLowerCase();
    visibleRows = session.rows.filter((row) => {
      if (mode === 'changes' && !row.hasSuggestion) return false;
      if (mode === 'review' && !(row.ruleStatus.includes('Needs') || row.ruleStatus.includes('Partial') || row.ruleStatus === 'No mapped rule')) return false;
      if (mode === 'covered' && !['Mapped sources cover BO quantity', 'Already matches'].includes(row.ruleStatus)) return false;
      if (q && ![row.order, row.line, row.item, row.warehouse, row.status, row.remark, row.suggestedRemark].join(' ').toLocaleLowerCase().includes(q)) return false;
      return true;
    });
    $('bo-review-body').innerHTML = visibleRows.map(rowHtml).join('') || '<tr><td colspan="6">No lines match this filter.</td></tr>';
    $('review-count').textContent = `${visibleRows.length.toLocaleString()} shown · ${session.rows.filter((r) => r.useSuggestion).length.toLocaleString()} approved`;
    $('export-count').textContent = `${session.rows.filter((r) => r.useSuggestion).length.toLocaleString()} approved suggestions`;
  }
  function approvedRows() { return session ? session.rows.filter((r) => r.useSuggestion && r.hasSuggestion) : []; }
  function exportApprovedUpdates() {
    if (!session) return setStatus('Analyze files first.', false, true);
    const accepted = approvedRows();
    if (!accepted.length) return setStatus('Select at least one draft suggestion before exporting.', false, true);
    const wb = XLSX.utils.book_new();
    const summary = [
      ['BO Remarks approved update file'],
      ['Source BO file', session.boFile.name],
      ['Source worksheet', session.boSheetName],
      ['Reviewed on', $('as-of-date').value || 'Not set'],
      ['Approved changes', accepted.length],
      [],
      ['Apply changes to the source worksheet using Source Excel row, Order, Line, Item, and Warehouse as cross-checks. This file does not rewrite the original report.']
    ];
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summary), 'Read me');
    const rows = [[
      'Source worksheet', 'Source Excel row', 'Customer Order #', 'Line #', 'Item', 'Warehouse', 'BO quantity',
      'Current remark', 'New remark', 'Current action', 'New action', 'Rule status', 'Evidence'
    ]];
    accepted.forEach((row) => rows.push([
      row.sheetName, row.excelRow, row.order, row.line, row.item, row.warehouse, row.qty,
      row.remark, row.suggestedRemark, row.action, row.suggestedAction || row.action, row.ruleStatus, row.reasonParts.join(' ')
    ]));
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), 'Approved updates');
    const originalName = session.boFile.name.replace(/\.(xlsx|xls|csv)$/i, '');
    XLSX.writeFile(wb, `${originalName}-approved-remarks.xlsx`, { compression: true });
    setStatus(`Downloaded an update file with ${accepted.length.toLocaleString()} approved changes and source row references.`, true);
  }
  function csvEscape(value) { return `"${String(value == null ? '' : value).replace(/"/g, '""')}"`; }
  function downloadCsv(filename, rows) {
    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\r\n');
    const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1200);
  }
  function exportReviewCsv() {
    if (!session) return;
    const data = [['Order', 'Line', 'Item', 'BO Qty', 'Warehouse', 'Status', 'ETA', 'Current Remark', 'Suggested Remark', 'Suggested Action', 'Rule Status', 'Reason', 'Approved']];
    session.rows.forEach((r) => data.push([r.order, r.line, r.item, r.qty, r.warehouse, r.status, prettyDate(r.eta), r.remark, r.suggestedRemark, r.suggestedAction, r.ruleStatus, r.reasonParts.join(' '), r.useSuggestion ? 'Yes' : 'No']));
    downloadCsv('bo-remarks-review.csv', data);
  }

  function findColumnByHints(headers, hints) { return headIndex(headers, hints); }
  function extractProcurement(workbook) {
    let candidate = null;
    for (const name of workbook.SheetNames) {
      const matrix = nonemptyRows(workbook.Sheets[name]);
      const hit = findHeader(matrix, [['Material'], ['Need to place order', 'Need to order'], ['Open PO', 'Sum of Open PO']], 15);
      if (hit && hit.score >= 2) {
        candidate = { name, matrix, headerRow: hit.row, score: hit.score };
        if (hit.score === 3) break;
      }
    }
    if (!candidate) return null;
    const headers = candidate.matrix[candidate.headerRow] || [];
    const col = {
      material: findColumnByHints(headers, ['Material']), item: findColumnByHints(headers, ['Item']), cat: findColumnByHints(headers, ['Cat', 'Category']),
      demand: findColumnByHints(headers, ['Max of Sum of BO', 'Sum of BO', 'BO demand']), available: findColumnByHints(headers, ['AVL', 'Available']),
      open: findColumnByHints(headers, ['Open PO', 'SUM OF OPEN po']), need: findColumnByHints(headers, ['Need to place order', 'Need to order'])
    };
    const byMaterial = new Map();
    for (let r = candidate.headerRow + 1; r < candidate.matrix.length; r++) {
      const raw = candidate.matrix[r];
      const material = clean(getRowValue(raw, col.material));
      if (!material || norm(material) === 'grandtotal') continue;
      const item = clean(getRowValue(raw, col.item));
      const demand = col.demand >= 0 ? getRowValue(raw, col.demand) : '';
      const available = col.available >= 0 ? getRowValue(raw, col.available) : '';
      const open = col.open >= 0 ? getRowValue(raw, col.open) : '';
      const need = col.need >= 0 ? getRowValue(raw, col.need) : '';
      const key = norm(material);
      if (!byMaterial.has(key)) byMaterial.set(key, { material, item, category: clean(getRowValue(raw, col.cat)), demand: num(demand), available: num(available), open: num(open), need: num(need), calculated: typeof need === 'number' || clean(need) !== '', sourceSheet: candidate.name });
      else {
        const current = byMaterial.get(key);
        if (!current.item && item) current.item = item;
        if (typeof need === 'number') {
          current.calculated = true;
          if (need > current.need) current.need = need;
        }
        if (typeof demand === 'number' && demand > current.demand) current.demand = demand;
      }
    }
    return { sheet: candidate.name, rows: [...byMaterial.values()].filter((r) => r.need > 0 || !r.calculated), headerRow: candidate.headerRow, columns: col };
  }
  function wildcardMatch(pattern, value) {
    const source = clean(pattern).toUpperCase().replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
    return source ? new RegExp(`^${source}$`, 'i').test(clean(value)) : false;
  }
  function vendorFor(item) {
    const hits = rules.vendors.filter((r) => r.enabled && wildcardMatch(r.pattern, item));
    if (!hits.length) return 'Needs vendor rule';
    const unique = [...new Set(hits.map((r) => clean(r.vendor)))];
    return unique.length > 1 ? 'Review: overlapping rules' : unique[0];
  }
  function renderProcurement() {
    if (!session) return;
    const procure = session.procurement;
    if (!procure || !procure.rows.length) {
      $('procurement-empty').hidden = false;
      $('procurement-content').hidden = true;
      $('procurement-empty').textContent = procure
        ? `Found “${procure.sheet}”, but no calculated Need to place order rows are available. This may be a pivot cache issue or the report was not recalculated before saving.`
        : 'No material-requirement pivot was found in the BO workbook. The page will not infer component needs from kit or pack demand.';
      return;
    }
    $('procurement-empty').hidden = true;
    $('procurement-content').hidden = false;
    const total = procure.rows.reduce((sum, row) => sum + row.need, 0);
    $('procurement-kpis').innerHTML = [
      drawKpi('Materials flagged', fmt(procure.rows.length), `Source: ${procure.sheet}`),
      drawKpi('Need to order', fmt(total), 'Units from source pivot'),
      drawKpi('Vendor rules to review', fmt(procure.rows.filter((r) => vendorFor(r.material).includes('review') || vendorFor(r.material).includes('Needs')).length), 'Vendor mappings remain editable')
    ].join('');
    $('procurement-notice').innerHTML = `<div class="bo-warning">This view reads the report’s material-requirements pivot. It does not calculate a three-month replenishment quantity or guess a needed-by date.</div>`;
    $('procurement-body').innerHTML = procure.rows.map((row, i) => {
      const key = norm(row.material);
      const override = procurementOverrides[key] || {};
      const vendor = override.vendor || vendorFor(row.material);
      const notes = override.notes || '';
      return `<tr data-procurement-key="${esc(key)}"><td><strong>${esc(row.material)}</strong></td><td>${esc(row.item || '—')}<span class="bo-row-sub">${esc(row.category || 'Category not set')}</span></td><td>${esc(row.calculated ? fmt(row.demand) : 'Not calculated')}</td><td>${esc(row.calculated ? fmt(row.available) : '—')}</td><td>${esc(row.calculated ? fmt(row.open) : '—')}</td><td class="${row.calculated ? '' : 'need-review'}">${esc(row.calculated ? fmt(row.need) : 'Refresh pivot')}</td><td><input class="vendor-override" type="text" value="${esc(vendor)}" aria-label="Vendor for ${esc(row.material)}"></td><td><input class="po-eta-notes" type="text" value="${esc(notes)}" placeholder="PO number / confirmed ETA" aria-label="PO and ETA notes for ${esc(row.material)}"></td></tr>`;
    }).join('');
  }
  function exportProcurementCsv() {
    if (!session || !session.procurement) return;
    const data = [['Material', 'BO item', 'Category', 'BO demand', 'Available', 'Open PO', 'Need to order', 'Vendor', 'PO / ETA notes', 'Pivot sheet']];
    session.procurement.rows.forEach((r) => {
      const extra = procurementOverrides[norm(r.material)] || {};
      data.push([r.material, r.item, r.category, r.demand, r.available, r.open, r.need, extra.vendor || vendorFor(r.material), extra.notes || '', r.sourceSheet]);
    });
    downloadCsv('procurement-review.csv', data);
  }

  function renderRuleStages() {
    const poolNames = session ? session.processPoolNames : [];
    $('process-pool-options').innerHTML = poolNames.map((name) => `<option value="${esc(name)}"></option>`).join('');
    $('rule-stage-body').innerHTML = rules.stages.map((stage, i) => `<tr data-stage-index="${i}">
      <td><input class="stage-enabled" type="checkbox" ${stage.enabled ? 'checked' : ''} aria-label="Enable ${esc(stage.name)}"></td>
      <td><input class="stage-name" type="text" value="${esc(stage.name)}" aria-label="Stage name"></td>
      <td><select class="stage-source" aria-label="Stage data source"><option value="transfer" ${stage.source === 'transfer' ? 'selected' : ''}>Transfer rows</option><option value="stock" ${stage.source === 'stock' ? 'selected' : ''}>Warehouse Available</option><option value="process" ${stage.source === 'process' ? 'selected' : ''}>Process pool</option><option value="manual" ${stage.source === 'manual' ? 'selected' : ''}>Manual / unmapped</option></select></td>
      <td><input class="stage-form-filter" type="text" value="${esc(stage.formFilter || '*')}" placeholder="* or PACK,KIT" aria-label="Applicable BO forms"><input class="stage-item-pattern" type="text" value="${esc(stage.itemPattern || '*')}" placeholder="* or item pattern" aria-label="Applicable component items"></td>
      <td><input class="process-group" list="process-pool-options" type="text" value="${esc(stage.processGroup || '')}" placeholder="Inventory group" aria-label="Process pool name"><select class="process-field" aria-label="Process quantity field"><option value="wip" ${stage.processField === 'wip' ? 'selected' : ''}>WIP</option><option value="stock" ${stage.processField === 'stock' ? 'selected' : ''}>Stock</option><option value="onTransfer" ${stage.processField === 'onTransfer' ? 'selected' : ''}>On Xfer</option><option value="nonNettable" ${stage.processField === 'nonNettable' ? 'selected' : ''}>NS / QC</option><option value="grn" ${stage.processField === 'grn' ? 'selected' : ''}>GRN</option></select></td>
      <td><input class="stage-remark template-input" type="text" value="${esc(stage.remark)}" aria-label="Remark template"></td>
      <td><input class="stage-action template-input" type="text" value="${esc(stage.action)}" aria-label="Action template"></td>
      <td><div class="rule-order"><button type="button" class="stage-up" aria-label="Move ${esc(stage.name)} up" ${i === 0 ? 'disabled' : ''}>↑</button><button type="button" class="stage-down" aria-label="Move ${esc(stage.name)} down" ${i === rules.stages.length - 1 ? 'disabled' : ''}>↓</button></div></td>
      <td><button type="button" class="rule-delete stage-delete">Remove</button></td>
    </tr>`).join('');
  }
  function renderVendorRules() {
    $('vendor-rule-body').innerHTML = rules.vendors.map((rule, i) => `<tr data-vendor-index="${i}"><td><input class="vendor-enabled" type="checkbox" ${rule.enabled ? 'checked' : ''} aria-label="Enable vendor rule"></td><td><input class="vendor-pattern" type="text" value="${esc(rule.pattern)}" aria-label="Material pattern"></td><td><input class="vendor-name" type="text" value="${esc(rule.vendor)}" aria-label="Vendor name"></td><td><button type="button" class="rule-delete vendor-delete">Remove</button></td></tr>`).join('');
  }
  function renderRuleSettings() {
    $('priority-mode').value = rules.priority;
    $('transfer-basis').value = rules.transferBasis;
    $('target-months').value = rules.targetMonths;
    $('planned-rule-enabled').checked = rules.plannedEnabled;
    $('planned-remark').value = rules.plannedRemark;
    $('planned-action').value = rules.plannedAction;
    renderRuleStages();
    renderVendorRules();
  }
  function pullRuleSettings() {
    rules.priority = $('priority-mode').value;
    rules.transferBasis = $('transfer-basis').value;
    rules.targetMonths = Math.max(0, num($('target-months').value));
    rules.plannedEnabled = $('planned-rule-enabled').checked;
    rules.plannedRemark = $('planned-remark').value;
    rules.plannedAction = $('planned-action').value;
    rules.stages = [...$('rule-stage-body').querySelectorAll('tr')].map((tr, i) => ({
      id: rules.stages[i]?.id || `stage-${i + 1}`,
      enabled: tr.querySelector('.stage-enabled').checked,
      name: tr.querySelector('.stage-name').value,
      source: tr.querySelector('.stage-source').value,
      formFilter: tr.querySelector('.stage-form-filter').value,
      itemPattern: tr.querySelector('.stage-item-pattern').value,
      processGroup: tr.querySelector('.process-group').value,
      processField: tr.querySelector('.process-field').value,
      remark: tr.querySelector('.stage-remark').value,
      action: tr.querySelector('.stage-action').value
    }));
    rules.vendors = [...$('vendor-rule-body').querySelectorAll('tr')].map((tr) => ({
      enabled: tr.querySelector('.vendor-enabled').checked,
      pattern: tr.querySelector('.vendor-pattern').value,
      vendor: tr.querySelector('.vendor-name').value
    }));
  }
  function saveRules() {
    pullRuleSettings();
    try {
      localStorage.setItem(RULE_KEY, JSON.stringify(rules));
      $('rules-status').textContent = 'Saved in this browser.';
      $('rules-status').className = 'formula-status valid';
    } catch (_) {
      $('rules-status').textContent = 'Browser storage is unavailable. Export a rules file to keep a copy.';
      $('rules-status').className = 'formula-status invalid';
    }
    if (session) {
      const rawBoRows = session.rows.map((r) => ({ ...r, useSuggestion: false }));
      session.rows = buildSuggestions(rawBoRows, getStockRecordsFromSession(), session.transferRows, session.processRowsData, session.bomInfo);
      renderResults();
    }
  }
  function getStockRecordsFromSession() {
    return session ? session.stockRowsData : [];
  }
  function downloadJson(filename, data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1200);
  }
  async function importRules(file) {
    if (!file) return;
    try {
      const imported = JSON.parse(await file.text());
      if (!imported || !Array.isArray(imported.stages) || !Array.isArray(imported.vendors)) throw new Error('This file does not contain a BO rules set.');
      rules = { ...clone(DEFAULT_RULES), ...imported, stages: imported.stages, vendors: imported.vendors };
      renderRuleSettings();
      saveRules();
    } catch (error) {
      $('rules-status').textContent = error.message || 'Could not import this rules file.';
      $('rules-status').className = 'formula-status invalid';
    } finally { $('import-rules').value = ''; }
  }
  function moveStage(index, delta) {
    pullRuleSettings();
    const target = index + delta;
    if (target < 0 || target >= rules.stages.length) return;
    const [row] = rules.stages.splice(index, 1);
    rules.stages.splice(target, 0, row);
    renderRuleStages();
  }
  function addStage() {
    pullRuleSettings();
    rules.stages.push({ id: `stage-${Date.now()}`, enabled: false, name: 'New process stage', source: 'manual', formFilter: '*', itemPattern: '*', processGroup: '', processField: 'wip', remark: '', action: '' });
    renderRuleStages();
  }
  function addVendorRule() {
    pullRuleSettings();
    rules.vendors.push({ enabled: true, pattern: '', vendor: '' });
    renderVendorRules();
  }
  function removeStage(index) { pullRuleSettings(); rules.stages.splice(index, 1); renderRuleStages(); }
  function removeVendor(index) { pullRuleSettings(); rules.vendors.splice(index, 1); renderVendorRules(); }
  function setActivePanel(name) {
    document.querySelectorAll('.bo-tab').forEach((tab) => {
      const active = tab.dataset.panel === name;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    document.querySelectorAll('.bo-panel').forEach((panel) => {
      const active = panel.id === `panel-${name}`;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
  }
  function clearSession() {
    session = null;
    visibleRows = [];
    $('bo-input-form').reset();
    $('bo-results').hidden = true;
    $('procurement-content').hidden = true;
    $('procurement-empty').hidden = false;
    $('procurement-empty').textContent = 'Load and analyze a BO workbook on the Daily update page. The procurement view will use its material-requirement data when available.';
    setStatus('No files loaded yet.');
  }

  $('bo-input-form').addEventListener('submit', analyzeFiles);
  $('review-filter').addEventListener('change', filterAndRenderRows);
  $('review-search').addEventListener('input', filterAndRenderRows);
  $('bo-review-body').addEventListener('change', (event) => {
    const checkbox = event.target.closest('.row-approve');
    if (!checkbox || !session) return;
    const row = session.rows.find((r) => r.index === Number(checkbox.closest('tr').dataset.rowIndex));
    if (row) row.useSuggestion = checkbox.checked;
    $('review-count').textContent = `${visibleRows.length.toLocaleString()} shown · ${session.rows.filter((r) => r.useSuggestion).length.toLocaleString()} approved`;
    $('export-count').textContent = `${session.rows.filter((r) => r.useSuggestion).length.toLocaleString()} approved suggestions`;
  });
  $('select-visible').addEventListener('change', (event) => {
    visibleRows.forEach((row) => { if (row.hasSuggestion) row.useSuggestion = event.target.checked; });
    filterAndRenderRows();
  });
  $('export-bo').addEventListener('click', exportApprovedUpdates);
  $('export-review-csv').addEventListener('click', exportReviewCsv);
  $('export-procurement').addEventListener('click', exportProcurementCsv);
  $('clear-session').addEventListener('click', clearSession);
  document.querySelectorAll('.bo-tab').forEach((tab) => tab.addEventListener('click', () => setActivePanel(tab.dataset.panel)));
  $('rule-stage-body').addEventListener('click', (event) => {
    const tr = event.target.closest('tr'); if (!tr) return;
    const i = Number(tr.dataset.stageIndex);
    if (event.target.closest('.stage-up')) moveStage(i, -1);
    if (event.target.closest('.stage-down')) moveStage(i, 1);
    if (event.target.closest('.stage-delete')) removeStage(i);
  });
  $('vendor-rule-body').addEventListener('click', (event) => {
    const tr = event.target.closest('tr'); if (!tr || !event.target.closest('.vendor-delete')) return;
    removeVendor(Number(tr.dataset.vendorIndex));
  });
  $('procurement-body').addEventListener('input', (event) => {
    const tr = event.target.closest('tr'); if (!tr) return;
    const key = tr.dataset.procurementKey;
    procurementOverrides[key] = procurementOverrides[key] || {};
    if (event.target.classList.contains('vendor-override')) procurementOverrides[key].vendor = event.target.value;
    if (event.target.classList.contains('po-eta-notes')) procurementOverrides[key].notes = event.target.value;
    saveProcurementOverrides();
  });
  $('save-rules').addEventListener('click', saveRules);
  $('export-rules').addEventListener('click', () => { pullRuleSettings(); downloadJson('freightkit-bo-remarks-rules.json', rules); });
  $('import-rules').addEventListener('change', (event) => importRules(event.target.files[0]));
  $('reset-rules').addEventListener('click', () => { rules = clone(DEFAULT_RULES); renderRuleSettings(); saveRules(); });
  $('add-stage').addEventListener('click', addStage);
  $('add-vendor-rule').addEventListener('click', addVendorRule);
  document.addEventListener('keydown', (event) => {
    const tab = event.target.closest && event.target.closest('.bo-tab');
    if (!tab) return;
    const tabs = [...document.querySelectorAll('.bo-tab')];
    const current = tabs.indexOf(tab);
    let next = current;
    if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
    else if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
    else return;
    event.preventDefault(); tabs[next].focus(); tabs[next].click();
  });

  renderRuleSettings();
  setActivePanel('daily');
  const asOf = $('as-of-date');
  if (asOf && !asOf.value) {
    const today = new Date();
    asOf.value = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  }
})();

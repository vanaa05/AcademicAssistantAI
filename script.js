// ============================================================
// script.js — Academic Assistant AI
// Single-page application logic: auth, navigation, PDF, AI features
// ============================================================

/* ── API base URL ────────────────────────────────────────────
 *
 * SAME-ORIGIN deployment (XAMPP / shared PHP host):
 *   Leave API_BASE as an empty string ''.
 *   All API calls resolve relative to whatever domain serves index.html.
 *   This is the default and requires no change.
 *
 * SPLIT deployment (GitHub Pages frontend + separate PHP backend host):
 *   Set API_BASE to the full URL of the PHP host, NO trailing slash.
 *   Example:
 *     const API_BASE = 'https://mysite.infinityfreeapp.com';
 *   The PHP host must have CORS enabled (api/config.php already handles this).
 *
 * ─────────────────────────────────────────────────────────── */
const API_BASE = '';   // ← set to your PHP host URL for GitHub Pages deployments

/* ── Config ─────────────────────────────────────────────────── */
const API = {
  // When API_BASE is empty, these are bare relative paths (no leading slash)
  // so they resolve relative to the current page directory — correct for both
  // XAMPP (http://localhost/AcademicAssistantAI/) and same-origin PHP hosts.
  // When API_BASE is set to a full URL, concatenation produces an absolute URL.
  auth:      API_BASE ? API_BASE + '/api/auth.php'      : 'api/auth.php',
  materials: API_BASE ? API_BASE + '/api/materials.php'  : 'api/materials.php',
  ai:        API_BASE ? API_BASE + '/api/ai.php'         : 'api/ai.php',
  quizzes:   API_BASE ? API_BASE + '/api/quizzes.php'    : 'api/quizzes.php',
};

// Configure PDF.js worker
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

/* ── App State ───────────────────────────────────────────────── */
const State = {
  user:            null,
  materials:       [],
  selectedMaterial: null,   // { id, original_name, extracted_text }

  // Tutor
  tutorSessionId:  null,
  tutorHistory:    [],
  tutorDocId:      null,

  // Quiz
  currentQuiz:     null,    // { quiz_id, questions, answers:{idx:option}, current }
  quizConfig:      { material_id: null, count: 5, difficulty: 'easy' },

  // Insights
  insightsData:    null,
};

/* ══════════════════════════════════════════════════════════════
   UTILITY FUNCTIONS
══════════════════════════════════════════════════════════════ */
async function apiGet(endpoint, params = {}) {
  // endpoint is either a relative path ('api/auth.php') or an absolute URL.
  // new URL() handles both: absolute URLs are used as-is; relative paths are
  // resolved against the current page location (preserving the subdirectory).
  const url = new URL(endpoint, window.location.href);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  const res = await fetch(url.toString(), { credentials: 'include' });
  return res.json();
}

async function apiPost(endpoint, params = {}, body = {}) {
  const url = new URL(endpoint, window.location.href);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  const res = await fetch(url.toString(), {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return res.json();
}

async function apiDelete(endpoint, params = {}, body = {}) {
  const url = new URL(endpoint, window.location.href);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  const res = await fetch(url.toString(), {
    method: 'DELETE', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return res.json();
}

// Toast
function toast(message, type = 'info', duration = 4000) {
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span>${message}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => {
    el.classList.add('removing');
    setTimeout(() => el.remove(), 320);
  }, duration);
}

// Show/hide element
function show(id) { document.getElementById(id)?.classList.remove('hidden'); }
function hide(id) { document.getElementById(id)?.classList.add('hidden'); }
function setText(id, text) { const el = document.getElementById(id); if (el) el.textContent = text; }

// ============================================================
// renderMarkdown(text)
// Converts AI-generated Markdown to safe HTML.
// Supports: headings, bold, italic, code blocks, inline code,
// blockquotes, ordered/unordered lists (nested), horizontal
// rules, tables, and line breaks.
// Math delimiters ($...$ $$...$$ \(...\) \[...\]) are preserved
// as-is so KaTeX can process them after insertion into the DOM.
// ============================================================
function renderMarkdown(text) {
  if (!text) return '';

  // ── Step 1: Protect math expressions from HTML-escaping ──
  // We replace math blocks with numbered placeholders, escape
  // the rest, then restore the math and let KaTeX render it.
  const mathStore = [];
  function saveMath(expr) {
    mathStore.push(expr);
    return '\x00MATH' + (mathStore.length - 1) + '\x00';
  }

  // Protect in order: display math first, then inline
  let s = text
    // $$ ... $$ block math
    .replace(/\$\$([\s\S]+?)\$\$/g, (_, m) => saveMath('$$' + m + '$$'))
    // \[ ... \] block math
    .replace(/\\\[([\s\S]+?)\\\]/g, (_, m) => saveMath('\\[' + m + '\\]'))
    // \( ... \) inline math
    .replace(/\\\((.+?)\\\)/g, (_, m) => saveMath('\\(' + m + '\\)'))
    // $ ... $ inline math (not $$, not inside words)
    .replace(/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/g, (_, m) => saveMath('$' + m + '$'));

  // ── Step 2: HTML-escape the non-math content ──
  s = s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  // ── Step 3: Block-level elements (process line by line) ──
  const lines    = s.split('\n');
  const output   = [];
  let   inPre    = false;
  let   preLines = [];
  let   inTable  = false;
  let   tableBuf = [];
  let   listStack = []; // [{type:'ul'|'ol', indent:number}]

  function flushList() {
    while (listStack.length) {
      output.push('</' + listStack.pop().type + '>');
    }
  }
  function flushTable() {
    if (!tableBuf.length) return;
    let html = '<div class="md-table-wrap"><table class="md-table">';
    tableBuf.forEach((row, ri) => {
      const tag = ri === 0 ? 'th' : 'td';
      html += '<tr>' + row.map(cell =>
        `<${tag}>${cell.trim()}</${tag}>`
      ).join('') + '</tr>';
    });
    html += '</table></div>';
    output.push(html);
    tableBuf = [];
    inTable  = false;
  }

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];

    // ── Fenced code block ──
    if (/^```/.test(line)) {
      if (!inPre) {
        flushList(); flushTable();
        inPre = true; preLines = [];
      } else {
        const code = preLines.join('\n');
        output.push('<pre><code>' + code + '</code></pre>');
        inPre = false;
      }
      continue;
    }
    if (inPre) { preLines.push(line); continue; }

    // ── Table row detection: | cell | cell | ──
    const isTableRow = /^\s*\|.+\|\s*$/.test(line);
    const isSepRow   = /^\s*\|[\s\-:|]+\|\s*$/.test(line);
    if (isTableRow) {
      flushList();
      if (isSepRow) { inTable = true; continue; } // skip separator row
      const cells = line.split('|').slice(1, -1);
      tableBuf.push(cells);
      inTable = true;
      continue;
    } else if (inTable) {
      flushTable();
    }

    // ── Blank line ──
    if (/^\s*$/.test(line)) {
      flushList();
      output.push('<br class="md-br">');
      continue;
    }

    // ── ATX Headings ──
    let m;
    if ((m = line.match(/^(#{1,6})\s+(.+)$/))) {
      flushList(); flushTable();
      const lvl = Math.min(m[1].length, 6);
      output.push(`<h${lvl}>${inlineFormat(m[2])}</h${lvl}>`);
      continue;
    }

    // ── HR ──
    if (/^(\*{3,}|-{3,}|_{3,})\s*$/.test(line)) {
      flushList(); flushTable();
      output.push('<hr/>');
      continue;
    }

    // ── Blockquote ──
    if (/^&gt;\s?/.test(line)) {
      flushList();
      output.push('<blockquote>' + inlineFormat(line.replace(/^&gt;\s?/, '')) + '</blockquote>');
      continue;
    }

    // ── Unordered list ──
    if ((m = line.match(/^(\s*)[-*+]\s+(.+)$/))) {
      flushTable();
      const indent = m[1].length;
      const curType = 'ul';
      if (!listStack.length || indent > listStack[listStack.length-1].indent) {
        output.push('<ul>');
        listStack.push({type:'ul', indent});
      } else if (indent < listStack[listStack.length-1].indent) {
        while (listStack.length && indent < listStack[listStack.length-1].indent) {
          output.push('</' + listStack.pop().type + '>');
        }
      }
      output.push('<li>' + inlineFormat(m[2]) + '</li>');
      continue;
    }

    // ── Ordered list ──
    if ((m = line.match(/^(\s*)\d+[.)]\s+(.+)$/))) {
      flushTable();
      const indent = m[1].length;
      if (!listStack.length || indent > listStack[listStack.length-1].indent) {
        output.push('<ol>');
        listStack.push({type:'ol', indent});
      } else if (indent < listStack[listStack.length-1].indent) {
        while (listStack.length && indent < listStack[listStack.length-1].indent) {
          output.push('</' + listStack.pop().type + '>');
        }
      }
      output.push('<li>' + inlineFormat(m[2]) + '</li>');
      continue;
    }

    // ── Regular paragraph line ──
    flushList(); flushTable();
    output.push('<p>' + inlineFormat(line) + '</p>');
  }

  // Flush any open blocks
  if (inPre) output.push('<pre><code>' + preLines.join('\n') + '</code></pre>');
  flushList();
  flushTable();

  // ── Step 4: Collapse consecutive <br class="md-br"> into one ──
  let html = output.join('\n')
    .replace(/(<br class="md-br">\s*){2,}/g, '<br class="md-br">')
    .replace(/<p>\s*<\/p>/g, '');

  // ── Step 5: Restore math placeholders ──
  html = html.replace(/\x00MATH(\d+)\x00/g, (_, idx) => mathStore[+idx]);

  return html;
}

// ── Inline formatting (bold, italic, code, links) ──────────────
function inlineFormat(text) {
  return text
    // Inline code (protect from further processing)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    // Bold+italic
    .replace(/\*{3}(.+?)\*{3}/g, '<strong><em>$1</em></strong>')
    // Bold
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/__(.+?)__/g, '<strong>$1</strong>')
    // Italic
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/_([^_]+)_/g, '<em>$1</em>')
    // Strikethrough
    .replace(/~~(.+?)~~/g, '<del>$1</del>')
    // Auto-link bare URLs
    .replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
}

// ============================================================
// renderMath(element)
// Runs KaTeX auto-render on an element once KaTeX has loaded.
// Delimiters: $$...$$ \[...\] for display; $...$ \(...\) inline.
// ============================================================
function renderMath(element) {
  if (!element) return;
  // KaTeX auto-render is loaded with `defer` — check it's available
  if (typeof renderMathInElement === 'function') {
    try {
      renderMathInElement(element, {
        delimiters: [
          { left: '$$',  right: '$$',  display: true  },
          { left: '\\[', right: '\\]', display: true  },
          { left: '\\(', right: '\\)', display: false },
          { left: '$',   right: '$',   display: false },
        ],
        throwOnError: false,
        errorColor:   '#cc0000',
        // Don't process content inside code blocks
        ignoredTags:  ['script','noscript','style','textarea','pre','code','option'],
      });
    } catch (e) { /* silently ignore render errors */ }
  }
}

// Format file size
function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Greeting
function getGreeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

/* ══════════════════════════════════════════════════════════════
   AUTHENTICATION
══════════════════════════════════════════════════════════════ */
function switchAuth(type) {
  document.getElementById('login-form').classList.toggle('active', type === 'login');
  document.getElementById('register-form').classList.toggle('active', type === 'register');
}

async function submitLogin() {
  const email    = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;
  const errEl    = document.getElementById('login-error');

  errEl.classList.add('hidden');
  if (!email || !password) { errEl.textContent = 'Please fill in all fields.'; errEl.classList.remove('hidden'); return; }

  setText('login-btn-text', 'Signing in…');
  show('login-spinner');
  document.querySelector('#login-form .btn-primary').disabled = true;

  try {
    const res = await apiPost(API.auth, { action: 'login' }, { email, password });
    if (res.success) {
      State.user = res.data;
      onLoginSuccess();
    } else {
      errEl.textContent = res.error || 'Login failed.';
      errEl.classList.remove('hidden');
    }
  } catch {
    errEl.textContent = 'Network error. Please try again.';
    errEl.classList.remove('hidden');
  } finally {
    setText('login-btn-text', 'Sign In');
    hide('login-spinner');
    document.querySelector('#login-form .btn-primary').disabled = false;
  }
}

async function submitRegister() {
  const full_name = document.getElementById('reg-name').value.trim();
  const email     = document.getElementById('reg-email').value.trim();
  const password  = document.getElementById('reg-password').value;
  const errEl     = document.getElementById('reg-error');

  errEl.classList.add('hidden');
  if (!full_name || !email || !password) {
    errEl.textContent = 'Please fill in all fields.';
    errEl.classList.remove('hidden'); return;
  }

  setText('reg-btn-text', 'Creating account…');
  show('reg-spinner');
  document.querySelector('#register-form .btn-primary').disabled = true;

  try {
    const res = await apiPost(API.auth, { action: 'register' }, { full_name, email, password });
    if (res.success) {
      State.user = res.data;
      onLoginSuccess();
    } else {
      errEl.textContent = res.error || 'Registration failed.';
      errEl.classList.remove('hidden');
    }
  } catch {
    errEl.textContent = 'Network error. Please try again.';
    errEl.classList.remove('hidden');
  } finally {
    setText('reg-btn-text', 'Create Account');
    hide('reg-spinner');
    document.querySelector('#register-form .btn-primary').disabled = false;
  }
}

function onLoginSuccess() {
  document.getElementById('auth-overlay').classList.add('hidden');
  document.getElementById('app').classList.remove('hidden');
  const u = State.user;
  setText('sidebar-name',  u.full_name);
  setText('sidebar-email', u.email);
  document.getElementById('sidebar-avatar').textContent = u.full_name.charAt(0).toUpperCase();
  // Populate mobile topbar avatar initial
  const mobUser = document.getElementById('mobile-topbar-user');
  if (mobUser) mobUser.textContent = u.full_name.charAt(0).toUpperCase();
  loadDashboard();
  loadMaterials();
}

async function logout() {
  await apiGet(API.auth, { action: 'logout' });
  State.user = null;
  State.materials = [];
  State.selectedMaterial = null;
  document.getElementById('app').classList.add('hidden');
  document.getElementById('auth-overlay').classList.remove('hidden');
  toast('Logged out successfully', 'info');
}

async function checkSession() {
  try {
    const res = await apiGet(API.auth, { action: 'me' });
    if (res.success) {
      State.user = res.data;
      onLoginSuccess();
    }
  } catch { /* not logged in */ }
}

/* ══════════════════════════════════════════════════════════════
   NAVIGATION
══════════════════════════════════════════════════════════════ */
function navigate(page) {
  // Deactivate all pages & nav items
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  document.getElementById('page-' + page)?.classList.add('active');
  document.querySelector(`[data-page="${page}"]`)?.classList.add('active');

  // Refresh data on page entry
  if (page === 'dashboard')  loadDashboard();
  if (page === 'materials')  renderMaterialsList();
  if (page === 'summarizer') populateDocSelects();
  if (page === 'tutor')      populateDocSelects();
  if (page === 'quiz')       {
    populateDocSelects(); loadQuizHistory();
    // Always show config view when navigating to quiz page
    show('quiz-config-view');
    hide('quiz-active-view');
    hide('quiz-results-view');
  }
  if (page === 'insights')   loadInsights();
}

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  // On mobile (≤700px) use the mobile drawer behaviour instead
  if (window.innerWidth <= 700) {
    openMobileSidebar();
  } else {
    sidebar.classList.toggle('collapsed');
  }
}

function openMobileSidebar() {
  document.getElementById('sidebar').classList.add('mobile-open');
  document.getElementById('sidebar-overlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sidebar-overlay').classList.remove('active');
  document.body.style.overflow = '';
}

// Set up nav links — also close mobile sidebar on navigation
document.querySelectorAll('.nav-item[data-page]').forEach(el => {
  el.addEventListener('click', (e) => {
    e.preventDefault();
    closeMobileSidebar();
    navigate(el.dataset.page);
  });
});

/* ══════════════════════════════════════════════════════════════
   DASHBOARD
══════════════════════════════════════════════════════════════ */
async function loadDashboard() {
  if (!State.user) return;

  setText('dash-greeting', `${getGreeting()}, ${State.user.full_name.split(' ')[0]}!`);

  try {
    const res = await apiGet(API.quizzes, { action: 'stats' });
    if (res.success) {
      const d = res.data;
      setText('stat-materials', d.materials || 0);
      setText('stat-quizzes',   d.overall.total_quizzes || 0);
      setText('stat-avg-score', d.overall.total_quizzes > 0
        ? Math.round(d.overall.avg_score) + '%' : '—');
      const best = d.history.length
        ? Math.max(...d.history.map(h => h.score)) + '%' : '—';
      setText('stat-streak', best);

      // Recent materials
      const matContainer = document.getElementById('dash-recent-materials');
      if (State.materials.length === 0) {
        matContainer.innerHTML = '<div class="empty-state-sm">No materials uploaded yet</div>';
      } else {
        matContainer.innerHTML = State.materials.slice(0, 4).map(m => `
          <div class="recent-mat-item">
            <i class="fa-solid fa-file-pdf recent-mat-icon"></i>
            <div class="recent-mat-info">
              <div class="recent-mat-name">${escHtml(m.original_name)}</div>
              <div class="recent-mat-date">${formatDate(m.uploaded_at)} · ${formatSize(m.file_size)}</div>
            </div>
          </div>`).join('');
      }

      // Recent activity from quiz history
      const actContainer = document.getElementById('dash-activity');
      if (d.history.length === 0) {
        actContainer.innerHTML = '<div class="empty-state-sm">No recent activity</div>';
      } else {
        actContainer.innerHTML = d.history.slice(0, 4).map(q => `
          <div class="activity-item">
            <i class="fa-solid fa-circle-question activity-icon"></i>
            <div>
              <div class="activity-text">
                Quiz on <strong>${escHtml(q.material_name)}</strong> — 
                <span style="color:${q.score>=70?'#16A34A':q.score>=50?'#D97706':'#DC2626'}">${q.score}%</span>
              </div>
              <div class="activity-time">${formatDate(q.completed_at)}</div>
            </div>
          </div>`).join('');
      }
    }
  } catch (e) { console.error(e); }
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ══════════════════════════════════════════════════════════════
   MY MATERIALS — Upload & Management
══════════════════════════════════════════════════════════════ */
async function loadMaterials() {
  try {
    const res = await apiGet(API.materials, { action: 'list' });
    if (res.success) {
      State.materials = res.data;
      renderMaterialsList();
    }
  } catch (e) { console.error(e); }
}

function renderMaterialsList() {
  const container = document.getElementById('materials-list');
  const countEl   = document.getElementById('mat-count');
  if (!container) return;

  setText('stat-materials', State.materials.length);
  if (countEl) countEl.textContent = State.materials.length;

  if (State.materials.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <i class="fa-solid fa-folder-open"></i>
        <h3>No documents yet</h3>
        <p>Upload your first PDF to get started</p>
      </div>`;
    return;
  }

  container.innerHTML = State.materials.map(m => {
    const isSelected = State.selectedMaterial?.id === m.id;
    return `
      <div class="material-item ${isSelected ? 'selected' : ''}" id="mat-item-${m.id}">
        <i class="fa-solid fa-file-pdf material-icon"></i>
        <div class="material-info">
          <div class="material-name">${escHtml(m.original_name)}</div>
          <div class="material-meta">
            ${formatSize(m.file_size)} 
            ${m.page_count ? '· ' + m.page_count + ' pages ' : ''}
            · ${formatDate(m.uploaded_at)}
          </div>
        </div>
        <span class="material-status ${m.status}">${m.status}</span>
        <div class="material-actions">
          <button class="btn-select ${isSelected ? 'selected' : ''}"
            onclick="selectMaterial(${m.id})"
            ${m.status !== 'ready' ? 'disabled title="Document is still processing"' : ''}>
            ${isSelected ? '<i class="fa-solid fa-check"></i> Selected' : 'Select'}
          </button>
          <button class="btn-remove" onclick="deleteMaterial(${m.id})">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </div>`;
  }).join('');
}

async function selectMaterial(id) {
  const mat = State.materials.find(m => m.id === id);
  if (!mat || mat.status !== 'ready') return;

  // Fetch extracted text if not cached
  if (!mat.extracted_text) {
    try {
      const res = await apiGet(API.materials, { action: 'get_text', id });
      if (res.success) {
        mat.extracted_text = res.data.extracted_text;
      }
    } catch { /* ignore */ }
  }

  State.selectedMaterial = mat;
  renderMaterialsList();
  populateDocSelects();
  toast(`"${mat.original_name}" selected as active document`, 'success');
}

async function deleteMaterial(id) {
  const mat = State.materials.find(m => m.id === id);
  if (!mat) return;
  if (!confirm(`Delete "${mat.original_name}"? This cannot be undone.`)) return;

  try {
    const res = await apiDelete(API.materials, { action: 'delete' }, { id });
    if (res.success) {
      State.materials = State.materials.filter(m => m.id !== id);
      if (State.selectedMaterial?.id === id) State.selectedMaterial = null;
      renderMaterialsList();
      populateDocSelects();
      toast('Document deleted', 'success');
    } else {
      toast(res.error || 'Delete failed', 'error');
    }
  } catch { toast('Network error', 'error'); }
}

/* ── Drag & Drop ─────────────────────────────────────────────── */
const dropZone = document.getElementById('drop-zone');
if (dropZone) {
  dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) uploadFile(file);
  });
  dropZone.addEventListener('click', (e) => {
    if (e.target === dropZone || e.target.closest('.upload-inner')) {
      if (!e.target.classList.contains('btn')) {
        document.getElementById('file-input').click();
      }
    }
  });
}

function handleFileSelect(event) {
  const file = event.target.files[0];
  if (file) uploadFile(file);
  event.target.value = '';
}

async function uploadFile(file) {
  // Client-side checks
  if (file.type !== 'application/pdf') {
    showMatNotification('Only PDF files are accepted.', 'error'); return;
  }
  if (file.size > 20 * 1024 * 1024) {
    showMatNotification('File exceeds the 20 MB limit.', 'error'); return;
  }

  show('upload-progress');
  setText('upload-filename', file.name);
  setText('upload-status-text', 'Uploading file…');
  setProgress(30);

  const formData = new FormData();
  formData.append('file', file);

  let material_id = null;

  try {
    const uploadRes = await fetch(`${API.materials}?action=upload`, {
      method: 'POST', credentials: 'include', body: formData,
    });
    const uploadData = await uploadRes.json();

    if (!uploadData.success) {
      hide('upload-progress');
      showMatNotification(uploadData.error || 'Upload failed.', 'error');
      return;
    }

    material_id = uploadData.data.id;
    setProgress(50);
    setText('upload-status-text', 'Extracting text from PDF…');

    // Add to local state immediately
    State.materials.unshift(uploadData.data);
    renderMaterialsList();

    // Extract text client-side with PDF.js
    const extractedData = await extractPdfText(file, (progress) => {
      setProgress(50 + Math.round(progress * 0.4));
    });

    setProgress(90);
    setText('upload-status-text', 'Saving extracted content…');

    // Save text to server
    const saveRes = await apiPost(API.materials, { action: 'save_text' }, {
      id:         material_id,
      text:       extractedData.text,
      page_count: extractedData.pageCount,
    });

    if (saveRes.success) {
      // Update local state
      const idx = State.materials.findIndex(m => m.id === material_id);
      if (idx !== -1) {
        State.materials[idx].status     = 'ready';
        State.materials[idx].page_count = extractedData.pageCount;
        State.materials[idx].extracted_text = extractedData.text;
      }
      setProgress(100);
      setTimeout(() => {
        hide('upload-progress');
        renderMaterialsList();
        showMatNotification(`"${file.name}" uploaded and processed successfully!`, 'success');
        populateDocSelects();
      }, 600);
    } else {
      hide('upload-progress');
      showMatNotification('File uploaded but text extraction failed. Try re-uploading.', 'error');
    }

  } catch (err) {
    console.error(err);
    hide('upload-progress');
    showMatNotification('Upload failed: ' + err.message, 'error');
    if (material_id) {
      // Mark as error
      const idx = State.materials.findIndex(m => m.id === material_id);
      if (idx !== -1) State.materials[idx].status = 'error';
      renderMaterialsList();
    }
  }
}

function setProgress(pct) {
  const bar = document.getElementById('upload-progress-bar');
  if (bar) bar.style.width = pct + '%';
}

function showMatNotification(msg, type) {
  const el = document.getElementById('mat-notification');
  if (!el) return;
  el.textContent = msg;
  el.className   = `notification ${type}`;
  el.classList.remove('hidden');
  setTimeout(() => el.classList.add('hidden'), 5000);
}

// PDF.js text extraction
async function extractPdfText(file, onProgress) {
  if (typeof pdfjsLib === 'undefined') {
    return { text: '', pageCount: 0 };
  }
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = async (e) => {
      try {
        const arrayBuffer  = e.target.result;
        const pdf          = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
        const pageCount    = pdf.numPages;
        let text           = '';

        for (let i = 1; i <= pageCount; i++) {
          const page    = await pdf.getPage(i);
          const content = await page.getTextContent();
          const pageText = content.items.map(item => item.str).join(' ');
          text += pageText + '\n';
          if (onProgress) onProgress(i / pageCount);
        }

        if (text.trim().length < 50) {
          // Likely a scanned PDF — warn but continue
          console.warn('PDF appears to contain little readable text (possibly scanned).');
        }

        resolve({ text: text.trim(), pageCount });
      } catch (err) {
        reject(new Error('PDF parsing failed: ' + err.message));
      }
    };
    reader.onerror = () => reject(new Error('File read error'));
    reader.readAsArrayBuffer(file);
  });
}

/* ── Populate doc selects across pages ──────────────────────── */
function populateDocSelects() {
  const ids = ['sum-doc-select', 'tutor-doc-select', 'quiz-doc-select'];
  const readyMats = State.materials.filter(m => m.status === 'ready');

  ids.forEach(id => {
    const sel = document.getElementById(id);
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">— Choose a document —</option>' +
      readyMats.map(m =>
        `<option value="${m.id}" ${State.selectedMaterial?.id == m.id ? 'selected' : ''}>
          ${escHtml(m.original_name)}
        </option>`
      ).join('');
    // Restore previous selection if still available
    if (current && readyMats.find(m => m.id == current)) sel.value = current;
  });
}

/* ══════════════════════════════════════════════════════════════
   AI SUMMARIZER
══════════════════════════════════════════════════════════════ */
function onSumDocChange() {
  const sel = document.getElementById('sum-doc-select');
  const btn = document.getElementById('sum-generate-btn');
  btn.disabled = !sel.value;
}

// Radio group selection highlight
document.querySelectorAll('.radio-item').forEach(label => {
  label.addEventListener('click', () => {
    document.querySelectorAll('.radio-item').forEach(l => l.classList.remove('active'));
    label.classList.add('active');
  });
});

async function generateSummary() {
  const material_id = document.getElementById('sum-doc-select').value;
  if (!material_id) { toast('Select a document first', 'error'); return; }

  const type = document.querySelector('input[name="sum-type"]:checked')?.value || 'quick';
  const typeNames = { quick: 'Quick Summary', detailed: 'Detailed Summary', topic: 'Topic-Based Summary', keypoints: 'Key Points' };

  hide('summary-placeholder');
  hide('summary-result');
  show('summary-loading');
  document.getElementById('sum-generate-btn').disabled = true;

  try {
    const res = await apiPost(API.ai, { action: 'summarize' }, { material_id: +material_id, type });
    if (res.success) {
      const sumEl = document.getElementById('summary-content');
      sumEl.innerHTML = renderMarkdown(res.data.summary);
      renderMath(sumEl);
      setText('summary-result-title', typeNames[type] || 'Summary');
      hide('summary-loading');
      show('summary-result');
      State._lastSummaryText = res.data.summary;
    } else {
      toast(res.error || 'Summary generation failed', 'error');
      hide('summary-loading');
      show('summary-placeholder');
    }
  } catch (e) {
    toast('Network error', 'error');
    hide('summary-loading');
    show('summary-placeholder');
  } finally {
    document.getElementById('sum-generate-btn').disabled = false;
  }
}

function copySummary() {
  const text = State._lastSummaryText || document.getElementById('summary-content').innerText;
  navigator.clipboard.writeText(text).then(() => toast('Summary copied to clipboard', 'success'));
}

/* ══════════════════════════════════════════════════════════════
   AI TUTOR
══════════════════════════════════════════════════════════════ */
function onTutorDocChange() {
  const sel = document.getElementById('tutor-doc-select');
  const btn = document.getElementById('tutor-send-btn');
  btn.disabled = !sel.value;
  if (sel.value != State.tutorDocId) {
    // New document — reset session
    State.tutorSessionId = null;
    State.tutorHistory   = [];
    State.tutorDocId     = sel.value ? +sel.value : null;
  }
}

function useSuggestedPrompt(btn) {
  const input = document.getElementById('tutor-input');
  input.value = btn.textContent;
  input.focus();
  autoResizeTextarea(input);
}

function autoResizeTextarea(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function handleTutorKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendTutorMessage();
  }
}

async function sendTutorMessage() {
  const input     = document.getElementById('tutor-input');
  const question  = input.value.trim();
  const docSelEl  = document.getElementById('tutor-doc-select');
  const material_id = +docSelEl.value;

  if (!question) return;
  if (!material_id) { toast('Please select a document first', 'error'); return; }

  input.value = '';
  autoResizeTextarea(input);
  document.getElementById('tutor-send-btn').disabled = true;

  appendChatMessage('user', question);
  const typingId = appendTypingIndicator();

  State.tutorHistory.push({ role: 'user', content: question });

  try {
    const res = await apiPost(API.ai, { action: 'tutor' }, {
      material_id,
      question,
      session_id: State.tutorSessionId || 0,
      history:    State.tutorHistory.slice(-8),
    });

    removeTypingIndicator(typingId);

    if (res.success) {
      State.tutorSessionId = res.data.session_id;
      const answer = res.data.answer;
      State.tutorHistory.push({ role: 'assistant', content: answer });
      appendChatMessage('assistant', answer);
    } else {
      appendChatMessage('assistant', '⚠ ' + (res.error || 'Something went wrong.'));
    }
  } catch {
    removeTypingIndicator(typingId);
    appendChatMessage('assistant', '⚠ Network error. Please try again.');
  } finally {
    document.getElementById('tutor-send-btn').disabled = !docSelEl.value;
  }
}

function appendChatMessage(role, content) {
  const messages = document.getElementById('chat-messages');
  const isUser   = role === 'user';
  const div      = document.createElement('div');
  div.className  = `chat-message ${isUser ? 'user' : ''}`;

  if (isUser) {
    div.innerHTML = `
      <div class="chat-avatar user"><i class="fa-solid fa-user"></i></div>
      <div class="chat-bubble user">${escHtml(content)}</div>`;
  } else {
    div.innerHTML = `
      <div class="chat-avatar tutor"><i class="fa-solid fa-robot"></i></div>
      <div class="chat-bubble tutor">
        <div class="markdown-body"></div>
        <div class="chat-bubble-footer">
          <button class="copy-msg-btn" onclick="copyMessage(this)" title="Copy">
            <i class="fa-solid fa-copy"></i> Copy
          </button>
        </div>
      </div>`;
    // Set innerHTML then run math rendering
    const mdEl = div.querySelector('.markdown-body');
    mdEl.innerHTML = renderMarkdown(content);
    renderMath(mdEl);
  }

  messages.appendChild(div);
  messages.scrollTop = messages.scrollHeight;
}

let typingCounter = 0;
function appendTypingIndicator() {
  const id = 'typing-' + (++typingCounter);
  const messages = document.getElementById('chat-messages');
  const div = document.createElement('div');
  div.className = 'chat-typing';
  div.id = id;
  div.innerHTML = `
    <div class="chat-avatar tutor"><i class="fa-solid fa-robot"></i></div>
    <div class="chat-bubble tutor">
      <div class="loading-dots"><span></span><span></span><span></span></div>
    </div>`;
  messages.appendChild(div);
  messages.scrollTop = messages.scrollHeight;
  return id;
}

function removeTypingIndicator(id) {
  document.getElementById(id)?.remove();
}

function copyMessage(btn) {
  const text = btn.closest('.chat-bubble').querySelector('.markdown-body')?.innerText || '';
  navigator.clipboard.writeText(text).then(() => toast('Copied', 'success'));
}

function clearTutorChat() {
  State.tutorSessionId = null;
  State.tutorHistory   = [];
  document.getElementById('chat-messages').innerHTML = `
    <div class="chat-welcome">
      <div class="chat-avatar tutor"><i class="fa-solid fa-robot"></i></div>
      <div class="chat-bubble tutor">
        <p>Hello! I'm your AI Tutor. Select a document from the left panel and ask me anything about it. I'll answer based strictly on your uploaded material.</p>
      </div>
    </div>`;
}

/* ══════════════════════════════════════════════════════════════
   QUIZ GENERATOR
══════════════════════════════════════════════════════════════ */
function selectOption(btn, hiddenId) {
  const group = btn.closest('.btn-group');
  group.querySelectorAll('.btn-option').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(hiddenId).value = btn.dataset.value;
}

async function generateQuiz() {
  const material_id = +document.getElementById('quiz-doc-select').value;
  const count       = +document.getElementById('quiz-count').value;
  const difficulty  = document.getElementById('quiz-difficulty').value;
  const errEl       = document.getElementById('quiz-gen-error');

  errEl.classList.add('hidden');

  if (!material_id) {
    errEl.textContent = 'Please select a document.';
    errEl.classList.remove('hidden'); return;
  }

  const btn = document.getElementById('quiz-generate-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating Quiz…';

  try {
    const res = await apiPost(API.ai, { action: 'quiz' }, { material_id, count, difficulty });
    if (res.success) {
      const { quiz_id, questions } = res.data;
      State.currentQuiz = {
        quiz_id,
        questions,
        answers: {},
        current: 0,
        material_id,
        difficulty,
      };
      renderQuizUI();
    } else {
      errEl.textContent = res.error || 'Quiz generation failed. Please try again.';
      errEl.classList.remove('hidden');
    }
  } catch {
    errEl.textContent = 'Network error. Please try again.';
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Quiz';
  }
}

function renderQuizUI() {
  const q = State.currentQuiz;
  if (!q) return;

  hide('quiz-config-view');
  hide('quiz-results-view');
  show('quiz-active-view');

  const mat = State.materials.find(m => m.id === q.material_id);
  setText('quiz-active-title', mat ? mat.original_name : 'Quiz');
  setText('quiz-active-subtitle', `${q.questions.length} questions · ${q.difficulty.charAt(0).toUpperCase() + q.difficulty.slice(1)}`);

  renderQuestion(q.current);
  renderDots();
  updateQuizProgress();
}

function renderQuestion(idx) {
  const q   = State.currentQuiz;
  const que = q.questions[idx];
  if (!que) return;

  q.current = idx;
  setText('quiz-q-num', `Question ${idx + 1} of ${q.questions.length}`);
  setText('quiz-q-text', que.question);

  const optionsEl = document.getElementById('quiz-options');
  const opts = ['A', 'B', 'C', 'D'];
  optionsEl.innerHTML = opts.map(opt => {
    const selected = q.answers[idx] === opt;
    return `
      <div class="quiz-option ${selected ? 'selected' : ''}" onclick="selectQuizAnswer('${opt}', ${idx})">
        <div class="option-label">${opt}</div>
        <div class="option-text">${escHtml(que.options[opt] || '')}</div>
      </div>`;
  }).join('');

  document.getElementById('quiz-prev-btn').disabled = idx === 0;

  const isLast = idx === q.questions.length - 1;
  const nextBtn = document.getElementById('quiz-next-btn');
  nextBtn.innerHTML = isLast
    ? 'Review <i class="fa-solid fa-flag-checkered"></i>'
    : 'Next <i class="fa-solid fa-chevron-right"></i>';

  if (isLast) {
    show('quiz-submit-row');
    updateUnansweredWarn();
  } else {
    hide('quiz-submit-row');
  }

  updateQuizProgress();
  renderDots();
}

function selectQuizAnswer(option, idx) {
  State.currentQuiz.answers[idx] = option;
  renderQuestion(idx);
}

function quizNav(dir) {
  const q   = State.currentQuiz;
  const next = q.current + dir;
  if (next >= 0 && next < q.questions.length) {
    renderQuestion(next);
  }
}

function renderDots() {
  const q = State.currentQuiz;
  const container = document.getElementById('quiz-dots');
  container.innerHTML = q.questions.map((_, i) => {
    const cls = i === q.current ? 'quiz-dot current' :
                q.answers[i] !== undefined ? 'quiz-dot answered' : 'quiz-dot';
    return `<button class="${cls}" onclick="renderQuestion(${i})" title="Question ${i+1}"></button>`;
  }).join('');
}

function updateQuizProgress() {
  const q = State.currentQuiz;
  const pct = ((q.current + 1) / q.questions.length) * 100;
  document.getElementById('quiz-progress-bar').style.width = pct + '%';
  setText('quiz-question-counter', `${q.current + 1} / ${q.questions.length}`);
}

function updateUnansweredWarn() {
  const q = State.currentQuiz;
  const unanswered = q.questions.length - Object.keys(q.answers).length;
  const warnEl = document.getElementById('quiz-unanswered-warn');
  if (unanswered > 0) {
    warnEl.textContent = `You have ${unanswered} unanswered question${unanswered > 1 ? 's' : ''}. You can still submit.`;
    warnEl.classList.remove('hidden');
  } else {
    warnEl.classList.add('hidden');
  }
  show('quiz-submit-row');
}

async function submitQuiz() {
  const q = State.currentQuiz;

  // Build answers array
  const answers = Object.entries(q.answers).map(([idx, option]) => ({
    question_index: +idx, selected_option: option,
  }));

  try {
    const res = await apiPost(API.quizzes, { action: 'submit' }, {
      quiz_id:   q.quiz_id,
      answers,
      questions: q.questions,
    });

    if (res.success) {
      const { score, correct_count, total } = res.data;
      showQuizResults(score, correct_count, total);
      loadQuizHistory();
    } else {
      toast(res.error || 'Failed to submit quiz', 'error');
    }
  } catch {
    toast('Network error', 'error');
  }
}

function showQuizResults(score, correctCount, total) {
  hide('quiz-active-view');
  show('quiz-results-view');

  const wrong   = Object.entries(State.currentQuiz.answers).filter(([i,a]) =>
    a !== State.currentQuiz.questions[i].correct).length;
  const skipped = total - Object.keys(State.currentQuiz.answers).length;

  // Score circle animation
  setText('results-pct', score + '%');
  const circumference = 327;
  const offset = circumference - (score / 100) * circumference;
  const ring = document.getElementById('score-ring');
  ring.style.strokeDashoffset = circumference;
  const color = score >= 70 ? '#16A34A' : score >= 50 ? '#F59E0B' : '#DC2626';
  ring.style.stroke = color;
  setTimeout(() => { ring.style.transition = 'stroke-dashoffset 1s ease'; ring.style.strokeDashoffset = offset; }, 100);

  // Verdict
  const verdict = score >= 80 ? 'Excellent work!' : score >= 60 ? 'Good job, keep it up!' : 'Keep studying — you can do it!';
  setText('results-verdict', verdict);
  setText('res-correct',  correctCount);
  setText('res-wrong',    wrong);
  setText('res-skipped',  skipped);

  // Detailed answers
  const container = document.getElementById('results-answers');
  const q = State.currentQuiz;
  container.innerHTML = q.questions.map((que, i) => {
    const selected  = q.answers[i];
    const isCorrect = selected === que.correct;
    const isSkipped = selected === undefined;
    const iconCls   = isCorrect ? 'correct' : isSkipped ? 'skipped' : 'wrong';
    const icon      = isCorrect ? 'fa-circle-check' : isSkipped ? 'fa-circle-minus' : 'fa-circle-xmark';

    return `
      <div class="result-answer-item">
        <div class="result-answer-header">
          <i class="fa-solid ${icon} result-answer-icon ${iconCls}"></i>
          <div>
            <div class="result-q-text">${escHtml(que.question)}</div>
            <div class="result-q-meta">
              ${!isSkipped ? `Your answer: <strong>${selected}: ${escHtml(que.options[selected] || '')}</strong>` : '<em>Not answered</em>'}
              ${!isCorrect ? ` · Correct: <strong style="color:#16A34A">${que.correct}: ${escHtml(que.options[que.correct] || '')}</strong>` : ''}
            </div>
          </div>
        </div>
        <div class="result-answer-detail">
          <div class="result-explanation"><strong>Explanation:</strong> ${escHtml(que.explanation || 'No explanation provided.')}</div>
          ${que.topic ? `<span class="result-topic-tag"><i class="fa-solid fa-tag"></i> ${escHtml(que.topic)}</span>` : ''}
        </div>
      </div>`;
  }).join('');
}

function retakeQuiz() {
  State.currentQuiz.answers = {};
  State.currentQuiz.current = 0;
  renderQuizUI();
}

async function loadQuizHistory() {
  try {
    const res = await apiGet(API.quizzes, { action: 'list' });
    if (res.success) {
      const container = document.getElementById('quiz-history-list');
      if (!container) return;
      if (res.data.length === 0) {
        container.innerHTML = '<div class="empty-state-sm">No quizzes taken yet</div>';
        return;
      }
      container.innerHTML = res.data.slice(0, 8).map(q => {
        const score    = q.score ?? '—';
        const scorePct = typeof q.score === 'number' ? q.score : 0;
        const cls      = scorePct >= 70 ? 'good' : scorePct >= 50 ? 'avg' : 'poor';
        return `
          <div class="quiz-history-item">
            <div class="quiz-hist-score ${cls}">${score}${typeof q.score === 'number' ? '%' : ''}</div>
            <div class="quiz-hist-info">
              <div class="quiz-hist-name">${escHtml(q.material_name)}</div>
              <div class="quiz-hist-meta">${q.question_count}Q · ${q.difficulty} · ${formatDate(q.completed_at || q.created_at)}</div>
            </div>
          </div>`;
      }).join('');
    }
  } catch (e) { console.error(e); }
}

/* ══════════════════════════════════════════════════════════════
   LEARNING INSIGHTS
══════════════════════════════════════════════════════════════ */
let scoreChart = null;
let topicChart = null;

async function loadInsights() {
  try {
    const res = await apiGet(API.quizzes, { action: 'stats' });
    if (!res.success) return;

    const d = res.data;
    State.insightsData = d;

    const hasData = d.overall.total_quizzes > 0;
    document.getElementById('insights-empty').classList.toggle('hidden', hasData);
    document.getElementById('insights-content').style.display = hasData ? '' : 'none';
    if (!hasData) return;

    // Stats
    setText('ins-total-quizzes',   d.overall.total_quizzes || 0);
    setText('ins-avg-score',       Math.round(d.overall.avg_score || 0) + '%');
    setText('ins-total-questions', d.overall.total_questions || 0);
    const best = d.history.length ? Math.max(...d.history.map(h => h.score)) + '%' : '—';
    setText('ins-best-score', best);

    // Score history chart
    const labels  = d.history.map((_, i) => 'Quiz ' + (d.history.length - i)).reverse();
    const scores  = [...d.history].reverse().map(h => h.score);

    if (scoreChart) scoreChart.destroy();
    const scoreCtx = document.getElementById('score-history-chart')?.getContext('2d');
    if (scoreCtx) {
      scoreChart = new Chart(scoreCtx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Score (%)',
            data: scores,
            fill: true,
            borderColor: '#2563EB',
            backgroundColor: 'rgba(37,99,235,.1)',
            tension: 0.4,
            pointBackgroundColor: '#2563EB',
            pointRadius: 5,
          }],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { min: 0, max: 100, grid: { color: '#F1F5F9' } }, x: { grid: { display: false } } },
        },
      });
    }

    // Topic performance chart
    const topicLabels = d.topics.slice(0, 8).map(t => t.topic);
    const topicAccs   = d.topics.slice(0, 8).map(t => parseFloat(t.accuracy));
    const topicColors = topicAccs.map(a => a >= 70 ? 'rgba(22,163,74,.7)' : a >= 50 ? 'rgba(245,158,11,.7)' : 'rgba(220,38,38,.7)');

    if (topicChart) topicChart.destroy();
    const topicCtx = document.getElementById('topic-chart')?.getContext('2d');
    if (topicCtx && topicLabels.length) {
      topicChart = new Chart(topicCtx, {
        type: 'bar',
        data: {
          labels: topicLabels,
          datasets: [{
            label: 'Accuracy (%)',
            data: topicAccs,
            backgroundColor: topicColors,
            borderRadius: 6,
          }],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { min: 0, max: 100, grid: { color: '#F1F5F9' } }, x: { grid: { display: false }, ticks: { maxRotation: 45 } } },
          indexAxis: 'x',
        },
      });
    }

    // Strong & weak topics
    const strongEl = document.getElementById('strong-topics');
    const weakEl   = document.getElementById('weak-topics');
    const strongTopics = d.topics.filter(t => t.accuracy >= 70).slice(0, 5);
    const weakTopics   = d.topics.filter(t => t.accuracy < 70).sort((a,b) => a.accuracy - b.accuracy).slice(0, 5);

    strongEl.innerHTML = strongTopics.length
      ? strongTopics.map(t => topicItem(t, '#16A34A')).join('')
      : '<div class="empty-state-sm">Complete more quizzes to see strong topics</div>';

    weakEl.innerHTML = weakTopics.length
      ? weakTopics.map(t => topicItem(t, '#DC2626')).join('')
      : '<div class="empty-state-sm">No weak topics identified yet</div>';

  } catch (e) { console.error(e); }
}

function topicItem(t, color) {
  const pct = parseFloat(t.accuracy) || 0;
  return `
    <div class="topic-item">
      <span class="topic-item-name">${escHtml(t.topic)}</span>
      <div class="topic-item-bar-wrap">
        <div class="topic-item-bar" style="width:${pct}%;background:${color}"></div>
      </div>
      <span class="topic-item-pct" style="color:${color}">${pct}%</span>
    </div>`;
}

async function loadRecommendations() {
  const card = document.getElementById('recommendations-card');
  card.style.display = '';
  show('recommendations-loading');
  hide('recommendations-content');

  const material_id = State.selectedMaterial?.id || 0;

  try {
    const res = await apiPost(API.ai, { action: 'recommend' }, { material_id });
    if (res.success) {
      const recEl = document.getElementById('recommendations-content');
      recEl.innerHTML = renderMarkdown(res.data.recommendations);
      renderMath(recEl);
      hide('recommendations-loading');
      show('recommendations-content');
    } else {
      hide('recommendations-loading');
      document.getElementById('recommendations-content').textContent = res.error || 'Failed to load recommendations.';
      show('recommendations-content');
    }
  } catch {
    hide('recommendations-loading');
    document.getElementById('recommendations-content').textContent = 'Network error.';
    show('recommendations-content');
  }
}

/* ══════════════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
  // Login on Enter key
  document.getElementById('login-password')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') submitLogin();
  });
  document.getElementById('reg-password')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') submitRegister();
  });

  // Check existing session
  await checkSession();
});

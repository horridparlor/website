(() => {
  const API = '../api/poem-center';
  const { escapeHtml, renderPoemLines, fitPoemText } = window.PoemFitText;

  const authToken = localStorage.getItem('authToken');
  const authHeader = () => authToken ? { Authorization: 'Bearer ' + authToken } : {};

  async function api(method, path, data) {
    const opts = { method, headers: { ...authHeader() } };
    let url = API + '/' + path;
    if (method === 'GET') {
      if (data) {
        const params = new URLSearchParams();
        Object.entries(data).forEach(([k, v]) => { if (v !== undefined && v !== null) params.set(k, v); });
        const qs = params.toString();
        if (qs) url += '?' + qs;
      }
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data || {});
    }
    let res;
    try { res = await fetch(url, opts); }
    catch { return { ok: false, status: 0, data: null }; }
    let json = null;
    try { json = await res.json(); } catch {}
    return { ok: res.ok, status: res.status, data: json };
  }

  const el = {
    authGate: document.getElementById('auth-gate'),
    app: document.getElementById('app'),
    tabs: {
      poems: document.getElementById('tab-poems'),
      books: document.getElementById('tab-books'),
      tags: document.getElementById('tab-tags'),
      stats: document.getElementById('tab-stats'),
    },
    views: {
      poems: document.getElementById('view-poems'),
      books: document.getElementById('view-books'),
      tags: document.getElementById('view-tags'),
      stats: document.getElementById('view-stats'),
    },
    poemBookFilter: document.getElementById('poem-book-filter'),
    poemSearch: document.getElementById('poem-search'),
    btnNewPoem: document.getElementById('btn-new-poem'),
    btnExportPoems: document.getElementById('btn-export-poems'),
    poemList: document.getElementById('poem-list'),
    newBookTitle: document.getElementById('new-book-title'),
    btnNewBook: document.getElementById('btn-new-book'),
    bookList: document.getElementById('book-list'),
    newTagName: document.getElementById('new-tag-name'),
    newTagColor: document.getElementById('new-tag-color'),
    btnNewTag: document.getElementById('btn-new-tag'),
    tagList: document.getElementById('tag-list'),
    statsContent: document.getElementById('stats-content'),
    toast: document.getElementById('toast'),
  };

  const state = {
    books: [],
    tags: [],
    languages: [],
    poems: [],
    currentPoem: null,
    editorAnchor: null,
    bookReorder: null,
    bookAddPoem: null,
    selectedTagIds: new Set(),
    expandedBookId: null,
    view: 'poems',
    collapsed: { preview: true, content: false },
    statsData: null,
  };

  const showToast = (msg, isError) => {
    el.toast.textContent = msg;
    el.toast.style.background = isError ? '#ff0066' : '#00aa77';
    el.toast.style.display = 'block';
    setTimeout(() => { el.toast.style.display = 'none'; }, 3200);
  };

  const fmtDate = (s) => s ? new Date(s.replace(' ', 'T')).toLocaleString() : '—';
  const todayStr = () => new Date().toISOString().slice(0, 10);
  const fmtWrittenDate = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    if (!y || !m || !d) return '';
    return `${d}.${m}.${y}`;
  };

  // Written-on date is entered as three Day/Month/Year selects (DD.MM.YYYY order),
  // since a native <input type="date"> can't be reordered in Firefox.
  function buildDateSelectsHtml(iso) {
    const [y, m, d] = (iso || todayStr()).split('-').map(Number);
    const pad = (n) => String(n).padStart(2, '0');
    const currentYear = new Date().getFullYear();
    const days = Array.from({ length: 31 }, (_, i) => i + 1);
    const months = Array.from({ length: 12 }, (_, i) => i + 1);
    const years = [];
    for (let yr = currentYear + 1; yr >= 2024; yr--) years.push(yr);
    return `
      <select id="ed-written-day">${days.map(dd => `<option value="${pad(dd)}" ${dd === d ? 'selected' : ''}>${pad(dd)}</option>`).join('')}</select>
      <select id="ed-written-month">${months.map(mm => `<option value="${pad(mm)}" ${mm === m ? 'selected' : ''}>${pad(mm)}</option>`).join('')}</select>
      <select id="ed-written-year">${years.map(yr => `<option value="${yr}" ${yr === y ? 'selected' : ''}>${yr}</option>`).join('')}</select>
    `;
  }
  function getWrittenDateValue() {
    const d = document.getElementById('ed-written-day').value;
    const m = document.getElementById('ed-written-month').value;
    const y = document.getElementById('ed-written-year').value;
    return `${y}-${m}-${d}`;
  }

  // ── Tabs ─────────────────────────────────────────────────────────────
  function switchTab(view) {
    state.view = view;
    Object.entries(el.tabs).forEach(([k, btn]) => btn.classList.toggle('active', k === view));
    Object.entries(el.views).forEach(([k, sec]) => sec.style.display = k === view ? '' : 'none');
    const url = new URL(location.href);
    if (view === 'poems') url.searchParams.delete('view'); else url.searchParams.set('view', view);
    history.replaceState({}, '', url);
    if (view === 'poems') loadPoems();
    if (view === 'books') loadBooks();
    if (view === 'tags') loadTags();
    if (view === 'stats') loadStats();
  }
  el.tabs.poems.addEventListener('click', () => switchTab('poems'));
  el.tabs.books.addEventListener('click', () => switchTab('books'));
  el.tabs.tags.addEventListener('click', () => switchTab('tags'));
  el.tabs.stats.addEventListener('click', () => switchTab('stats'));

  // ── Tags (shared) ────────────────────────────────────────────────────
  async function ensureTagsLoaded() {
    if (state.tags.length) return;
    const res = await api('GET', 'tags');
    if (res.ok) state.tags = res.data.tags || [];
  }

  // ── Languages (shared) ───────────────────────────────────────────────
  async function ensureLanguagesLoaded() {
    if (state.languages.length) return;
    const res = await api('GET', 'languages');
    if (res.ok) state.languages = res.data.languages || [];
  }

  async function loadTags() {
    const res = await api('GET', 'tags');
    if (!res.ok) { showToast('Failed to load tags', true); return; }
    state.tags = res.data.tags || [];
    renderTagList();
  }

  function renderTagList() {
    if (!state.tags.length) {
      el.tagList.innerHTML = '<div class="pc-empty">No tags yet.</div>';
      return;
    }
    el.tagList.innerHTML = state.tags.map(t => `
      <div class="pc-card" data-tag-id="${t.id}" style="cursor:default;">
        <div class="pc-card-title" style="display:flex;align-items:center;gap:0.5rem;">
          <span class="dot" style="width:0.9rem;height:0.9rem;border-radius:50%;background:${escapeHtml(t.color || '#00ffcc')};display:inline-block;flex:0 0 auto;"></span>
          <input type="text" class="tag-name-input" value="${escapeHtml(t.name)}" data-id="${t.id}" style="flex:1;min-width:0;background:#1a1a1a;color:#fff;border:1px solid #333;border-radius:6px;padding:0.3rem 0.5rem;font-size:0.95rem;font-family:inherit;" />
        </div>
        <div class="pc-card-meta">
          <span>${t.poemCount} poem${t.poemCount === 1 ? '' : 's'}</span>
          <input type="color" class="tag-color-input" value="${t.color || '#00ffcc'}" data-id="${t.id}" style="width:2.2rem;height:1.6rem;padding:0;border:none;background:none;cursor:pointer;" />
          <button class="button danger tag-delete-btn" data-id="${t.id}" type="button" style="padding:0.2rem 0.6rem;font-size:0.78rem;">Delete</button>
        </div>
      </div>
    `).join('');

    el.tagList.querySelectorAll('.tag-name-input').forEach(input => {
      input.addEventListener('click', (e) => e.stopPropagation());
      input.addEventListener('change', async () => {
        const name = input.value.trim();
        if (!name) { showToast('Tag name cannot be empty', true); loadTags(); return; }
        const res = await api('PUT', 'tags', { id: Number(input.dataset.id), name });
        if (res.ok) { showToast('Tag updated'); loadTags(); } else showToast((res.data && res.data.error) || 'Failed to update tag', true);
      });
    });

    el.tagList.querySelectorAll('.tag-color-input').forEach(input => {
      input.addEventListener('change', async () => {
        const res = await api('PUT', 'tags', { id: Number(input.dataset.id), color: input.value });
        if (res.ok) { showToast('Tag updated'); loadTags(); } else showToast('Failed to update tag', true);
      });
    });
    el.tagList.querySelectorAll('.tag-delete-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this tag? It will be removed from all poems.')) return;
        const res = await api('DELETE', 'tags', { id: Number(btn.dataset.id) });
        if (res.ok) { showToast('Tag deleted'); loadTags(); } else showToast('Failed to delete tag', true);
      });
    });
  }

  el.btnNewTag.addEventListener('click', async () => {
    const name = el.newTagName.value.trim();
    if (!name) return;
    const res = await api('POST', 'tags', { name, color: el.newTagColor.value });
    if (res.ok) {
      el.newTagName.value = '';
      showToast('Tag created');
      loadTags();
    } else {
      showToast((res.data && res.data.error) || 'Failed to create tag', true);
    }
  });

  // ── Books ────────────────────────────────────────────────────────────
  async function loadBooks() {
    const res = await api('GET', 'books');
    if (!res.ok) { showToast('Failed to load books', true); return; }
    state.books = res.data.books || [];
    populateBookFilter();
    renderBookList();
  }

  function tagPoemCount(tagId) {
    const t = state.tags.find(x => x.id === tagId);
    return t ? (t.poemCount || 0) : 0;
  }

  function populateBookFilter() {
    const current = el.poemBookFilter.value;
    el.poemBookFilter.innerHTML = '<option value="">All poems</option><option value="0">Unsorted</option>' +
      state.books.map(b => `<option value="${b.id}">${escapeHtml(b.title)} (${b.poemCount})</option>`).join('');
    el.poemBookFilter.value = current;
  }

  function renderBookList() {
    if (!state.books.length) {
      el.bookList.innerHTML = '<div class="pc-empty">No books yet. Create one above.</div>';
      return;
    }
    el.bookList.innerHTML = state.books.map(b => `
      <div class="pc-card" data-book-id="${b.id}">
        <div class="pc-card-title">${escapeHtml(b.title)}</div>
        <div class="pc-card-meta">
          <span class="pc-badge">${b.poemCount} poem${b.poemCount === 1 ? '' : 's'}</span>
          ${(b.tags || []).map(t => `<span class="pc-tag-chip" style="cursor:default;color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}22;">${escapeHtml(t.name)}<span class="tag-chip-count">(${t.count})</span></span>`).join('')}
          ${(b.languages || []).map(l => `<span class="pc-tag-chip" style="cursor:default;">${escapeHtml(l.name)}<span class="tag-chip-count">(${l.count})</span></span>`).join('')}
          ${b.isPublished ? '<span class="pc-badge published">Published</span>' : ''}
        </div>
        ${state.expandedBookId === b.id ? `<div class="pc-book-detail" data-detail-for="${b.id}"><div class="pc-loading">Loading…</div></div>` : ''}
      </div>
    `).join('');

    el.bookList.querySelectorAll('.pc-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('[data-detail-for]') || e.target.closest('.pc-tag-chip')) return;
        const id = Number(card.dataset.bookId);
        state.expandedBookId = state.expandedBookId === id ? null : id;
        renderBookList();
        if (state.expandedBookId === id) loadBookDetail(id);
      });
    });
  }

  const ARROW_UP_SVG = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 7.5L6 4l3.5 3.5"/></svg>';
  const ARROW_DOWN_SVG = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4.5L6 8l3.5-3.5"/></svg>';

  let bookAddPoemDebounce = null;

  async function loadBookDetail(bookId) {
    const book = state.books.find(b => b.id === bookId);
    await ensureTagsLoaded();
    const res = await api('GET', 'poems', { bookId });
    const poems = (res.ok ? (res.data.poems || []) : []).slice().sort((a, b) => a.sortOrder - b.sortOrder);
    const detail = document.querySelector(`[data-detail-for="${bookId}"]`);
    if (!detail || !book) return;

    const addPoem = state.bookAddPoem && state.bookAddPoem.bookId === bookId ? state.bookAddPoem : null;

    const reorder = state.bookReorder && state.bookReorder.bookId === bookId ? state.bookReorder : null;
    const rows = reorder ? reorder.staged : poems;
    const isTight = poems.length === 0 || poems.every((p, i) => p.sortOrder === i + 1);

    detail.innerHTML = `
      <div class="pc-editor-row"><label>Title</label><input type="text" class="book-title-input" value="${escapeHtml(book.title)}" /></div>
      <div class="pc-editor-row"><label>Description</label><textarea class="book-desc-input" style="min-height:70px;">${escapeHtml(book.description || '')}</textarea></div>
      <div class="pc-actions">
        <button class="button secondary book-save-btn" type="button">Save</button>
        <button class="button ${book.isPublished ? 'danger' : 'secondary'} book-publish-btn" type="button">${book.isPublished ? 'Unpublish' : 'Publish'}</button>
        ${reorder
          ? `<button class="button book-reorder-confirm-btn" type="button">Confirm Order</button>
             <button class="button secondary book-reorder-cancel-btn" type="button">Cancel</button>`
          : `<button class="button secondary book-reorder-btn" type="button" ${poems.length < 2 ? 'disabled' : ''}>Reorder</button>`}
        <button class="button secondary book-export-btn" type="button">Export Book PDF</button>
        ${!reorder && !isTight ? '<button class="button secondary book-tighten-btn" type="button">Tighten</button>' : ''}
        <button class="button danger book-delete-btn" type="button">Delete Book</button>
      </div>
      <div class="pc-section-title" style="margin-top:1rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
        <span>Poems in this book</span>
        ${!reorder ? `<button class="button secondary book-add-poem-toggle-btn" type="button" style="padding:0.25rem 0.6rem;font-size:0.78rem;">${addPoem ? 'Cancel' : '+ Add Poem'}</button>` : ''}
      </div>
      ${addPoem ? `
        <div class="pc-add-poem-panel">
          <input type="text" class="book-add-poem-search" placeholder="Search poems to add…" value="${escapeHtml(addPoem.query)}" />
          <div class="pc-add-poem-results" id="book-add-poem-results"></div>
        </div>
      ` : ''}
      ${rows.length ? rows.map((p, i) => {
        const isOpen = !reorder && state.currentPoem && state.currentPoem.id === p.id &&
          state.editorAnchor && state.editorAnchor.view === 'books' && state.editorAnchor.bookId === bookId;
        return `
        <div class="pc-book-poem-wrap" data-poem-wrap="${p.id}">
          <div class="pc-book-poem-row ${isOpen ? 'active' : ''} ${reorder ? 'reorder-mode' : ''}" data-poem-id="${p.id}">
            <span class="pc-position-wrap"><input type="text" inputmode="numeric" class="pc-position-input" value="${p.sortOrder}." data-poem-id="${p.id}" ${reorder ? 'disabled' : ''} /></span>
            ${reorder ? `
              <button class="pc-reorder-btn" data-dir="up" ${i === 0 ? 'disabled' : ''} type="button" aria-label="Move up">${ARROW_UP_SVG}</button>
              <button class="pc-reorder-btn" data-dir="down" ${i === rows.length - 1 ? 'disabled' : ''} type="button" aria-label="Move down">${ARROW_DOWN_SVG}</button>
            ` : ''}
            <div class="pc-book-poem-info">
              <div class="pc-card-title"><span class="title-text">${escapeHtml(p.title)}</span><span class="pc-card-date">${escapeHtml(fmtWrittenDate(p.writtenDate))}</span></div>
              <div class="pc-card-meta">
                <span>${escapeHtml(p.author)}</span>
                ${p.isPublished ? '<span class="pc-badge published">Published</span>' : ''}
                ${(p.tags || []).map(t => `<span class="pc-tag-chip" style="color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}22;">${escapeHtml(t.name)}<span class="tag-chip-count">(${tagPoemCount(t.id)})</span></span>`).join('')}
                ${p.languageName ? `<span class="pc-tag-chip">${escapeHtml(p.languageName)}</span>` : ''}
              </div>
            </div>
          </div>
          ${isOpen ? `<div class="pc-inline-editor-slot" data-editor-for="${p.id}"></div>` : ''}
        </div>
      `; }).join('') : '<div class="pc-empty">No poems in this book yet.</div>'}
    `;

    detail.querySelector('.book-save-btn').addEventListener('click', async () => {
      const title = detail.querySelector('.book-title-input').value.trim();
      const description = detail.querySelector('.book-desc-input').value;
      if (!title) return showToast('Title required', true);
      const r = await api('PUT', 'books', { id: bookId, title, description });
      if (r.ok) { showToast('Book saved'); await loadBooks(); loadBookDetail(bookId); } else showToast('Failed to save book', true);
    });
    detail.querySelector('.book-publish-btn').addEventListener('click', async () => {
      const r = await api('POST', 'publish', { type: 'book', id: bookId, isPublished: !book.isPublished });
      if (r.ok) { showToast(book.isPublished ? 'Unpublished' : 'Published'); await loadBooks(); loadBookDetail(bookId); } else showToast('Failed', true);
    });
    detail.querySelector('.book-delete-btn').addEventListener('click', async () => {
      if (!confirm('Delete this book? Its poems will become unsorted, not deleted.')) return;
      const r = await api('DELETE', 'books', { id: bookId });
      if (r.ok) { showToast('Book deleted'); state.expandedBookId = null; state.bookReorder = null; state.bookAddPoem = null; loadBooks(); } else showToast('Failed to delete book', true);
    });
    detail.querySelector('.book-export-btn').addEventListener('click', () => exportBookPDF(book, poems));

    const reorderBtn = detail.querySelector('.book-reorder-btn');
    if (reorderBtn) {
      reorderBtn.addEventListener('click', () => {
        state.bookAddPoem = null;
        state.bookReorder = {
          bookId,
          original: poems.map(p => ({ id: p.id, sortOrder: p.sortOrder })),
          staged: poems.map(p => ({ id: p.id, title: p.title, sortOrder: p.sortOrder })),
        };
        loadBookDetail(bookId);
      });
    }
    const tightenBtn = detail.querySelector('.book-tighten-btn');
    if (tightenBtn) tightenBtn.addEventListener('click', () => tightenBookOrder(bookId, poems));

    const addPoemToggleBtn = detail.querySelector('.book-add-poem-toggle-btn');
    if (addPoemToggleBtn) {
      addPoemToggleBtn.addEventListener('click', () => {
        state.bookAddPoem = addPoem ? null : { bookId, query: '' };
        loadBookDetail(bookId);
      });
    }
    const addPoemSearchInput = detail.querySelector('.book-add-poem-search');
    if (addPoemSearchInput) {
      addPoemSearchInput.focus();
      addPoemSearchInput.setSelectionRange(addPoemSearchInput.value.length, addPoemSearchInput.value.length);
      addPoemSearchInput.addEventListener('input', () => {
        state.bookAddPoem.query = addPoemSearchInput.value;
        clearTimeout(bookAddPoemDebounce);
        bookAddPoemDebounce = setTimeout(() => renderAddPoemResults(bookId), 300);
      });
      renderAddPoemResults(bookId);
    }

    if (reorder) {
      detail.querySelector('.book-reorder-confirm-btn').addEventListener('click', () => confirmBookReorder(bookId));
      detail.querySelector('.book-reorder-cancel-btn').addEventListener('click', () => {
        state.bookReorder = null;
        loadBookDetail(bookId);
      });
      detail.querySelectorAll('.pc-reorder-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('[data-poem-id]');
          const id = Number(row.dataset.poemId);
          const staged = state.bookReorder.staged;
          const idx = staged.findIndex(p => p.id === id);
          const dir = btn.dataset.dir;
          const swapIdx = dir === 'up' ? idx - 1 : idx + 1;
          if (swapIdx < 0 || swapIdx >= staged.length) return;
          const tmp = staged[idx].sortOrder;
          staged[idx].sortOrder = staged[swapIdx].sortOrder;
          staged[swapIdx].sortOrder = tmp;
          staged.sort((a, b) => a.sortOrder - b.sortOrder);
          loadBookDetail(bookId);
        });
      });
    } else {
      detail.querySelectorAll('.pc-position-input').forEach(input => {
        input.addEventListener('change', () => handlePositionEdit(bookId, poems, input));
      });
      detail.querySelectorAll('.pc-book-poem-row').forEach(row => {
        row.addEventListener('click', async (e) => {
          if (e.target.closest('.pc-position-input')) return;
          const id = Number(row.dataset.poemId);
          const alreadyOpen = state.currentPoem && state.currentPoem.id === id &&
            state.editorAnchor && state.editorAnchor.view === 'books' && state.editorAnchor.bookId === bookId;
          if (alreadyOpen) { closeEditor(); return; }
          await openEditorById(id, { view: 'books', bookId });
        });
      });
      mountEditorIntoSlot();
    }
  }

  // Searches all poems (any book, or unsorted) so an existing poem can be moved into
  // this one — excludes poems already in this book from the results.
  async function renderAddPoemResults(bookId) {
    const panel = document.getElementById('book-add-poem-results');
    if (!panel) return;
    const query = (state.bookAddPoem && state.bookAddPoem.query || '').trim();
    if (!query) { panel.innerHTML = '<div class="pc-empty" style="padding:0.75rem;">Type to search poems…</div>'; return; }
    panel.innerHTML = '<div class="pc-loading">Searching…</div>';
    const res = await api('GET', 'poems', { q: query });
    if (!document.getElementById('book-add-poem-results')) return;
    if (!state.bookAddPoem || state.bookAddPoem.bookId !== bookId) return;
    // The API's `q` matches title OR content — filter down to title matches only, since
    // this search is "find a poem by name", not a full-text search.
    const queryLower = query.toLowerCase();
    const results = (res.ok ? (res.data.poems || []) : [])
      .filter(p => p.bookId !== bookId && p.title.toLowerCase().includes(queryLower));
    panel.innerHTML = results.length ? results.map(p => `
      <div class="pc-add-poem-result" data-poem-id="${p.id}">
        <span class="title">${escapeHtml(p.title)}</span>
        <span class="pc-badge">${p.bookTitle ? escapeHtml(p.bookTitle) : 'Unsorted'}</span>
        <button class="button secondary add-poem-btn" type="button">Add</button>
      </div>
    `).join('') : '<div class="pc-empty" style="padding:0.75rem;">No matching poems.</div>';
    panel.querySelectorAll('.add-poem-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.closest('[data-poem-id]').dataset.poemId);
        const r = await api('PUT', 'poem', { id, bookId });
        if (!r.ok) { showToast('Failed to add poem to book', true); return; }
        showToast('Poem added to book');
        state.bookAddPoem = null;
        // loadBooks() re-renders the whole book list (resetting this book's detail panel
        // to its "Loading…" placeholder) — it must finish before we repopulate it, or the
        // two renders race and can leave the placeholder stuck.
        await loadBooks();
        loadBookDetail(bookId);
      });
    });
  }

  // Sets a poem's position to `value`, pushing whatever else occupies that slot (and any
  // contiguous run right after it) up by one — so inserting at an existing number never
  // silently overwrites it.
  async function handlePositionEdit(bookId, poems, input) {
    const id = Number(input.dataset.poemId);
    const poem = poems.find(p => p.id === id);
    if (!poem) return;
    // The field reads like "2." — pull out just the digits, ignoring the trailing dot
    // (or its absence, since it's re-added below regardless of what the user typed).
    const digits = input.value.replace(/[^0-9]/g, '');
    const value = digits ? parseInt(digits, 10) : NaN;
    if (!Number.isFinite(value) || value < 1) {
      showToast('Position must be a positive number', true);
      input.value = `${poem.sortOrder}.`;
      return;
    }
    if (value === poem.sortOrder) { input.value = `${value}.`; return; }

    const others = poems.filter(p => p.id !== id);
    const occupied = new Map(others.map(p => [p.sortOrder, p]));
    const changes = new Map();
    let k = value;
    while (occupied.has(k)) {
      changes.set(occupied.get(k).id, k + 1);
      k += 1;
    }
    changes.set(id, value);

    await Promise.all(Array.from(changes.entries()).map(([pid, newOrder]) => api('PUT', 'poem', { id: pid, sortOrder: newOrder })));
    showToast('Order updated');
    loadBookDetail(bookId);
  }

  async function confirmBookReorder(bookId) {
    const reorder = state.bookReorder;
    if (!reorder || reorder.bookId !== bookId) return;
    const originalById = new Map(reorder.original.map(p => [p.id, p.sortOrder]));
    const updates = reorder.staged.filter(p => originalById.get(p.id) !== p.sortOrder);
    if (updates.length) {
      await Promise.all(updates.map(u => api('PUT', 'poem', { id: u.id, sortOrder: u.sortOrder })));
      showToast('Order updated');
    }
    state.bookReorder = null;
    loadBookDetail(bookId);
  }

  // Renumbers every poem in the book to a contiguous 1..N sequence (in current order),
  // closing whatever gaps exist (e.g. from a poem set to "99" or ones deleted in between).
  async function tightenBookOrder(bookId, poems) {
    const sorted = poems.slice().sort((a, b) => a.sortOrder - b.sortOrder);
    const updates = [];
    sorted.forEach((p, i) => { if (p.sortOrder !== i + 1) updates.push({ id: p.id, sortOrder: i + 1 }); });
    if (!updates.length) return;
    await Promise.all(updates.map(u => api('PUT', 'poem', { id: u.id, sortOrder: u.sortOrder })));
    showToast('Order tightened');
    loadBookDetail(bookId);
  }

  el.btnNewBook.addEventListener('click', async () => {
    const title = el.newBookTitle.value.trim();
    if (!title) return;
    const res = await api('POST', 'books', { title });
    if (res.ok) { el.newBookTitle.value = ''; showToast('Book created'); loadBooks(); }
    else showToast((res.data && res.data.error) || 'Failed to create book', true);
  });

  // ── Poems list ───────────────────────────────────────────────────────
  let searchDebounce = null;
  el.poemSearch.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(loadPoems, 250);
  });
  el.poemBookFilter.addEventListener('change', loadPoems);
  el.btnNewPoem.addEventListener('click', () => openEditor(null, { view: 'poems' }));
  el.btnExportPoems.addEventListener('click', () => {
    if (state.currentPoem && state.currentPoem.id) {
      exportPoemPDF(currentEditorPoem());
      return;
    }
    if (!state.poems.length) return showToast('No poems to export', true);
    let title = 'All Poems';
    const bookVal = el.poemBookFilter.value;
    if (bookVal === '0') title = 'Unsorted Poems';
    else if (bookVal !== '') {
      const book = state.books.find(b => String(b.id) === bookVal);
      if (book) title = book.title;
    }
    exportBookPDF({ title, description: null }, state.poems);
  });

  async function loadPoems() {
    await ensureTagsLoaded();
    const params = { q: el.poemSearch.value.trim() || undefined };
    if (el.poemBookFilter.value !== '') params.bookId = el.poemBookFilter.value;
    const res = await api('GET', 'poems', params);
    if (!res.ok) { showToast('Failed to load poems', true); return; }
    state.poems = res.data.poems || [];
    renderPoemList();
  }

  function poemSnippet(content) {
    const lines = (content || '').split(/\r?\n/)
      .map(l => l.trim())
      .filter(t => t !== '' && !/^\[[^\]]*\]$/.test(t));
    const snippet = lines.slice(0, 4).join(' // ');
    const alreadyEllipsized = /(…|\.\.\.)$/.test(snippet);
    return lines.length > 4 && !alreadyEllipsized ? snippet + '…' : snippet;
  }

  function renderPoemList() {
    if (!state.poems.length) {
      el.poemList.innerHTML = '<div class="pc-empty">No poems found.</div>';
      return;
    }
    el.poemList.innerHTML = state.poems.map(p => {
      const isOpen = state.currentPoem && state.currentPoem.id === p.id &&
        state.editorAnchor && state.editorAnchor.view === 'poems';
      return `
      <div class="pc-poem-wrap" data-poem-wrap="${p.id}">
        <div class="pc-card ${isOpen ? 'active' : ''}" data-poem-id="${p.id}">
          <div class="pc-card-title"><span class="title-text">${escapeHtml(p.title)}</span><span class="pc-card-date">${escapeHtml(fmtWrittenDate(p.writtenDate))}</span></div>
          <div class="pc-card-meta">
            <span>${escapeHtml(p.author)}</span>
            ${p.bookTitle ? `<span class="pc-badge">${escapeHtml(p.bookTitle)}</span>` : '<span class="pc-badge">Unsorted</span>'}
            ${p.isPublished ? '<span class="pc-badge published">Published</span>' : ''}
            ${p.originalPoemId ? '<span class="pc-badge variant">Variant</span>' : ''}
            ${(p.tags || []).map(t => `<span class="pc-tag-chip" style="color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}22;">${escapeHtml(t.name)}<span class="tag-chip-count">(${tagPoemCount(t.id)})</span></span>`).join('')}
            ${p.languageName ? `<span class="pc-tag-chip">${escapeHtml(p.languageName)}</span>` : ''}
          </div>
          <div class="pc-card-meta" style="color:#777;font-style:italic;">${escapeHtml(poemSnippet(p.content))}</div>
        </div>
        ${isOpen ? `<div class="pc-inline-editor-slot" data-editor-for="${p.id}"></div>` : ''}
      </div>
    `; }).join('');

    el.poemList.querySelectorAll('.pc-card').forEach(card => {
      card.addEventListener('click', () => {
        const id = Number(card.dataset.poemId);
        const isOpen = state.currentPoem && state.currentPoem.id === id &&
          state.editorAnchor && state.editorAnchor.view === 'poems';
        if (isOpen) { closeEditor(); return; }
        openEditorById(id, { view: 'poems' });
      });
    });

    updateExportButtonState();
    mountEditorIntoSlot();
  }

  function updateExportButtonState() {
    const hasOpen = !!(state.currentPoem && state.currentPoem.id);
    el.btnExportPoems.querySelector('.pc-export-label').textContent = hasOpen ? 'Export poem as PDF' : 'Export all as PDF';
  }

  // ── Editor ───────────────────────────────────────────────────────────
  // The editor expands in place — inside the Poems list or inside a book's poem row,
  // whichever it was opened from (`anchor`) — rather than in one fixed container, so
  // opening/closing a poem never jumps the page around.
  async function openEditorById(id, anchor) {
    const res = await api('GET', 'poem', { id });
    if (!res.ok) { showToast('Failed to load poem', true); return; }
    await openEditor(res.data.poem, anchor);
  }

  async function openEditor(poem, anchor) {
    await ensureTagsLoaded();
    await ensureLanguagesLoaded();
    const prevAnchor = state.editorAnchor;
    state.currentPoem = poem;
    state.editorAnchor = anchor;
    state.selectedTagIds = new Set((poem && poem.tags || []).map(t => t.id));
    const lineCount = ((poem && poem.content) || '').split(/\r?\n/).filter(l => l.trim() !== '').length;
    state.collapsed.content = lineCount > 10;

    // If the editor was open elsewhere, that host's DOM still holds its old slot —
    // re-render it so we don't end up with two mounted editors (duplicate element ids).
    if (prevAnchor && (prevAnchor.view !== anchor.view || (anchor.view === 'books' && prevAnchor.bookId !== anchor.bookId))) {
      if (prevAnchor.view === 'poems') renderPoemList();
      else if (prevAnchor.view === 'books') await loadBookDetail(prevAnchor.bookId);
    }

    if (anchor.view === 'poems') renderPoemList();
    else if (anchor.view === 'books') await loadBookDetail(anchor.bookId);
  }

  function closeEditor() {
    const anchor = state.editorAnchor;
    state.currentPoem = null;
    state.editorAnchor = null;
    if (anchor && anchor.view === 'books') loadBookDetail(anchor.bookId);
    else renderPoemList();
  }

  function mountEditorIntoSlot() {
    if (!state.currentPoem) return;
    const slot = document.querySelector(`[data-editor-for="${state.currentPoem.id}"]`);
    if (!slot) return;
    renderEditor(slot);
  }

  function renderEditor(container) {
    const p = state.currentPoem || {
      id: null, title: '', author: 'Eero Laine', content: '', bookId: null, languageId: null,
      writtenDate: todayStr(), geniusUrl: null, isPublished: false, originalPoemId: null, originalTitle: null, historyCount: 0,
    };
    const defaultLanguageId = p.languageId || (state.languages.find(l => l.code === 'fi') || {}).id || null;

    container.innerHTML = `
      <div class="pc-editor">
        ${p.originalPoemId ? `<div class="pc-based-on">Based on: <a href="#" id="jump-to-original">${escapeHtml(p.originalTitle || ('#' + p.originalPoemId))}</a></div>` : ''}

        <div class="pc-collapsible ${state.collapsed.preview ? 'pc-collapsed' : ''}" data-collapsible="preview">
          <button type="button" class="pc-collapsible-toggle" aria-expanded="${state.collapsed.preview ? 'false' : 'true'}">
            <span class="pc-collapsible-arrow">▾</span> Preview
          </button>
          <div class="pc-collapsible-body">
            <div class="pc-preview-wrap">
              <div class="poem-preview-title" id="preview-title"></div>
              <div class="poem-preview-author" id="preview-author"></div>
              <div class="poem-preview" id="preview-box"></div>
            </div>
          </div>
        </div>

        <div class="pc-editor-grid two">
          <div class="pc-editor-row"><label>Title</label><input type="text" id="ed-title" value="${escapeHtml(p.title)}" /></div>
          <div class="pc-editor-row"><label>Writer</label><input type="text" id="ed-author" value="${escapeHtml(p.author)}" /></div>
        </div>
        <div class="pc-editor-grid two">
          <div class="pc-editor-row">
            <label>Book <button type="button" class="pc-inline-link" id="ed-manage-books">Manage books →</button></label>
            <select id="ed-book">
              <option value="">Unsorted</option>
              ${state.books.map(b => `<option value="${b.id}" ${p.bookId === b.id ? 'selected' : ''}>${escapeHtml(b.title)}</option>`).join('')}
              <option value="__new__">+ New Book…</option>
            </select>
          </div>
          <div class="pc-editor-row">
            <label>Written on</label>
            <div class="pc-date-select-group">${buildDateSelectsHtml(p.writtenDate || todayStr())}</div>
          </div>
        </div>

        <div class="pc-editor-grid two">
          <div class="pc-editor-row">
            <label>Language</label>
            <select id="ed-language">
              ${state.languages.map(l => `<option value="${l.id}" ${defaultLanguageId === l.id ? 'selected' : ''}>${escapeHtml(l.name)}</option>`).join('')}
            </select>
          </div>
          <div class="pc-editor-row">
            <label>Genius Lyrics URL</label>
            <div class="pc-inline-field">
              <input type="url" id="ed-genius-url" value="${escapeHtml(p.geniusUrl || '')}" placeholder="https://genius.com/…" />
              ${p.geniusUrl ? `<a href="${escapeHtml(p.geniusUrl)}" target="_blank" rel="noopener" class="button secondary pc-genius-link">View ↗</a>` : ''}
            </div>
          </div>
        </div>

        <div class="pc-editor-row">
          <label>Tags</label>
          <div class="pc-tag-picker" id="ed-tags"></div>
        </div>

        <div class="pc-collapsible ${state.collapsed.content ? 'pc-collapsed' : ''}" data-collapsible="content">
          <button type="button" class="pc-collapsible-toggle" aria-expanded="${state.collapsed.content ? 'false' : 'true'}">
            <span class="pc-collapsible-arrow">▾</span> Poem
          </button>
          <div class="pc-collapsible-body">
            <textarea id="ed-content" class="pc-content-textarea">${escapeHtml(p.content)}</textarea>
          </div>
        </div>

        <div class="pc-actions">
          <button class="button" id="btn-save-poem" type="button">Save</button>
          ${p.id ? `<button class="button ${p.isPublished ? 'danger' : 'secondary'}" id="btn-publish-poem" type="button">${p.isPublished ? 'Unpublish' : 'Publish'}</button>` : ''}
          ${p.id ? `<button class="button secondary" id="btn-new-version" type="button">New Version</button>` : ''}
          ${p.id ? `<button class="button secondary" id="btn-export-poem" type="button">Export PDF</button>` : ''}
          ${p.id ? `<button class="button secondary" id="btn-history" type="button">History (${p.historyCount || 0})</button>` : ''}
          ${p.id ? `<button class="button danger" id="btn-delete-poem" type="button">Delete</button>` : ''}
          <button class="button secondary" id="btn-close-editor" type="button">Close</button>
        </div>

        <div id="history-panel" style="display:none;"></div>
      </div>
    `;

    const previewBox = document.getElementById('preview-box');
    const titleInput = document.getElementById('ed-title');
    const authorInput = document.getElementById('ed-author');
    const contentInput = document.getElementById('ed-content');

    const updatePreview = () => {
      document.getElementById('preview-title').textContent = titleInput.value || 'Untitled';
      document.getElementById('preview-author').textContent = authorInput.value || 'Eero Laine';
      renderPoemLines(previewBox, contentInput.value);
      const previewCollapsible = previewBox.closest('.pc-collapsible');
      if (!previewCollapsible || !previewCollapsible.classList.contains('pc-collapsed')) {
        fitPoemText(previewBox, { min: 14, max: 40 });
      }
    };
    updatePreview();

    const autoGrow = () => {
      if (state.collapsed.content) return;
      const scrollY = window.scrollY;
      contentInput.style.height = 'auto';
      contentInput.style.height = contentInput.scrollHeight + 'px';
      window.scrollTo(0, scrollY);
    };
    autoGrow();

    let previewDebounce = null;
    contentInput.addEventListener('input', () => {
      autoGrow();
      clearTimeout(previewDebounce); previewDebounce = setTimeout(updatePreview, 80);
    });
    titleInput.addEventListener('input', updatePreview);
    authorInput.addEventListener('input', updatePreview);
    window.addEventListener('resize', updatePreview);

    container.querySelectorAll('.pc-collapsible-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const wrap = btn.closest('.pc-collapsible');
        const collapsed = wrap.classList.toggle('pc-collapsed');
        btn.setAttribute('aria-expanded', String(!collapsed));
        const key = wrap.dataset.collapsible;
        if (key) state.collapsed[key] = collapsed;
        if (!collapsed && key === 'preview') updatePreview();
        if (!collapsed && key === 'content') autoGrow();
      });
    });

    renderTagPicker();

    const bookSelect = document.getElementById('ed-book');
    bookSelect.addEventListener('change', () => handleBookSelectChange(bookSelect));
    document.getElementById('ed-manage-books').addEventListener('click', () => switchTab('books'));

    document.getElementById('btn-close-editor').addEventListener('click', () => closeEditor());
    document.getElementById('btn-save-poem').addEventListener('click', savePoem);
    if (p.id) {
      document.getElementById('btn-publish-poem').addEventListener('click', () => togglePublishPoem(p));
      document.getElementById('btn-new-version').addEventListener('click', () => createVariant(p));
      document.getElementById('btn-export-poem').addEventListener('click', () => exportPoemPDF(currentEditorPoem()));
      document.getElementById('btn-history').addEventListener('click', () => toggleHistory(p.id));
      document.getElementById('btn-delete-poem').addEventListener('click', () => deletePoem(p));
    }
    if (p.originalPoemId) {
      document.getElementById('jump-to-original').addEventListener('click', (e) => {
        e.preventDefault();
        switchTab('poems');
        openEditorById(p.originalPoemId, { view: 'poems' });
      });
    }
  }

  function renderTagPicker() {
    const wrap = document.getElementById('ed-tags');
    if (!wrap) return;
    const scrollY = window.scrollY;
    wrap.innerHTML = state.tags.map(t => `
      <span class="pc-tag-chip tag-toggle ${state.selectedTagIds.has(t.id) ? 'selected' : ''}" data-id="${t.id}" style="color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}${state.selectedTagIds.has(t.id) ? '33' : '15'};">
        <span class="dot" style="background:${escapeHtml(t.color || '#00ffcc')};"></span>${escapeHtml(t.name)}<span class="tag-chip-count">(${tagPoemCount(t.id)})</span>
      </span>
    `).join('') + `<span class="pc-tag-chip add-new" id="tag-add-new-btn" type="button">+ New Tag</span>`;
    window.scrollTo(0, scrollY);

    wrap.querySelectorAll('.tag-toggle').forEach(chip => {
      chip.addEventListener('click', () => {
        const id = Number(chip.dataset.id);
        if (state.selectedTagIds.has(id)) state.selectedTagIds.delete(id); else state.selectedTagIds.add(id);
        renderTagPicker();
      });
    });

    const addBtn = document.getElementById('tag-add-new-btn');
    if (addBtn) addBtn.addEventListener('click', () => { addBtn.remove(); renderTagQuickAdd(wrap); });
  }

  function renderTagQuickAdd(wrap) {
    const form = document.createElement('div');
    form.className = 'pc-tag-quick-add';
    form.innerHTML = `
      <input type="text" id="tag-quick-name" placeholder="Tag name…" />
      <input type="color" id="tag-quick-color" value="#00ffcc" />
      <button type="button" class="button secondary" id="tag-quick-save">Add</button>
      <button type="button" class="button secondary" id="tag-quick-cancel">Cancel</button>
    `;
    wrap.appendChild(form);
    document.getElementById('tag-quick-name').focus();

    document.getElementById('tag-quick-cancel').addEventListener('click', () => renderTagPicker());
    document.getElementById('tag-quick-save').addEventListener('click', async () => {
      const name = document.getElementById('tag-quick-name').value.trim();
      if (!name) return;
      const color = document.getElementById('tag-quick-color').value;
      const res = await api('POST', 'tags', { name, color });
      if (!res.ok || !res.data || !res.data.tag) { showToast((res.data && res.data.error) || 'Failed to create tag', true); return; }
      if (!state.tags.some(t => t.id === res.data.tag.id)) {
        state.tags.push(res.data.tag);
        state.tags.sort((a, b) => a.name.localeCompare(b.name));
      }
      state.selectedTagIds.add(res.data.tag.id);
      showToast('Tag created');
      renderTagPicker();
    });
  }

  async function handleBookSelectChange(selectEl) {
    if (selectEl.value !== '__new__') return;
    const title = prompt('New book title:');
    if (!title || !title.trim()) { selectEl.value = ''; return; }
    const res = await api('POST', 'books', { title: title.trim() });
    if (!res.ok) { showToast((res.data && res.data.error) || 'Failed to create book', true); selectEl.value = ''; return; }
    await loadBooks();
    const created = state.books.find(b => b.id === res.data.id);
    selectEl.innerHTML = `<option value="">Unsorted</option>` +
      state.books.map(b => `<option value="${b.id}">${escapeHtml(b.title)}</option>`).join('') +
      `<option value="__new__">+ New Book…</option>`;
    selectEl.value = created ? String(created.id) : '';
    showToast('Book created');
  }

  function currentEditorPoem() {
    const p = state.currentPoem || {};
    return {
      ...p,
      title: document.getElementById('ed-title').value.trim() || 'Untitled',
      author: document.getElementById('ed-author').value.trim() || 'Eero Laine',
      content: document.getElementById('ed-content').value,
      writtenDate: getWrittenDateValue(),
    };
  }

  // Strips leading/trailing blank lines and collapses runs of 2+ spaces down to one,
  // without touching the line breaks that give the poem its shape.
  function cleanPoemContent(raw) {
    const lines = String(raw || '').replace(/\r\n/g, '\n').split('\n')
      .map(l => l.replace(/[ \t]{2,}/g, ' '));
    while (lines.length && lines[0].trim() === '') lines.shift();
    while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();
    return lines.join('\n');
  }

  async function savePoem() {
    const title = document.getElementById('ed-title').value.trim();
    const author = document.getElementById('ed-author').value.trim() || 'Eero Laine';
    const content = cleanPoemContent(document.getElementById('ed-content').value);
    const bookVal = document.getElementById('ed-book').value;
    const languageVal = document.getElementById('ed-language').value;
    const writtenDate = getWrittenDateValue();
    const geniusUrl = document.getElementById('ed-genius-url').value.trim();
    if (!title) return showToast('Title required', true);
    if (bookVal === '__new__') return showToast('Finish creating the book first', true);

    const payload = {
      title, author, content, writtenDate, geniusUrl,
      bookId: bookVal === '' ? 0 : Number(bookVal),
      languageId: languageVal ? Number(languageVal) : 0,
      tagIds: Array.from(state.selectedTagIds),
    };

    const isNew = !state.currentPoem || !state.currentPoem.id;
    const res = isNew
      ? await api('POST', 'poems', payload)
      : await api('PUT', 'poem', { id: state.currentPoem.id, ...payload });

    if (!res.ok) { showToast((res.data && res.data.error) || 'Failed to save poem', true); return; }
    showToast('Poem saved');
    state.currentPoem = res.data.poem;
    const lineCount = content.split(/\r?\n/).filter(l => l.trim() !== '').length;
    state.collapsed.preview = true;
    state.collapsed.content = lineCount > 10;
    await loadPoems();
    if (state.editorAnchor && state.editorAnchor.view === 'books') await loadBookDetail(state.editorAnchor.bookId);
  }

  async function togglePublishPoem(p) {
    const res = await api('POST', 'publish', { type: 'poem', id: p.id, isPublished: !p.isPublished });
    if (!res.ok) return showToast('Failed to update publish state', true);
    showToast(p.isPublished ? 'Unpublished' : 'Published');
    await openEditorById(p.id, state.editorAnchor);
    loadPoems();
  }

  async function createVariant(p) {
    const res = await api('POST', 'variant', { poemId: p.id });
    if (!res.ok) return showToast('Failed to create new version', true);
    showToast('New version created');
    await openEditor(res.data.poem, state.editorAnchor);
    loadPoems();
  }

  async function deletePoem(p) {
    if (!confirm(`Delete "${p.title}"? Poems older than an hour are archived (soft-deleted) rather than erased.`)) return;
    const res = await api('DELETE', 'poem', { id: p.id });
    if (!res.ok) return showToast('Failed to delete poem', true);
    showToast(res.data.mode === 'soft' ? 'Poem archived' : 'Poem deleted');
    const anchor = state.editorAnchor;
    state.currentPoem = null;
    state.editorAnchor = null;
    if (anchor && anchor.view === 'books') loadBookDetail(anchor.bookId);
    loadPoems();
  }

  // Positional line diff — same approach as /find-difference's text fallback mode.
  function diffTextLines(a, b) {
    const out = [];
    const max = Math.max(a.length, b.length);
    for (let i = 0; i < max; i++) {
      if (i >= a.length) out.push({ kind: 'added', b: b[i] });
      else if (i >= b.length) out.push({ kind: 'removed', a: a[i] });
      else if (a[i] !== b[i]) out.push({ kind: 'changed', a: a[i], b: b[i] });
    }
    return out;
  }

  function renderDiffHtml(diffs) {
    if (!diffs.length) return '<div class="pc-empty">No differences from the current version.</div>';
    return `<div class="pc-diff-list">${diffs.map(d => {
      if (d.kind === 'added') return `<div class="pc-diff-row added"><span class="pc-diff-badge">+</span><span class="pc-diff-text">${escapeHtml(d.b)}</span></div>`;
      if (d.kind === 'removed') return `<div class="pc-diff-row removed"><span class="pc-diff-badge">−</span><span class="pc-diff-text">${escapeHtml(d.a)}</span></div>`;
      return `<div class="pc-diff-row changed"><span class="pc-diff-badge">±</span><span class="pc-diff-text"><span class="pc-diff-old">${escapeHtml(d.a)}</span> → <span class="pc-diff-new">${escapeHtml(d.b)}</span></span></div>`;
    }).join('')}</div>`;
  }

  async function toggleHistory(poemId) {
    const panel = document.getElementById('history-panel');
    if (panel.style.display !== 'none' && panel.dataset.loaded === 'true') {
      panel.style.display = 'none';
      return;
    }
    panel.style.display = '';
    panel.innerHTML = '<div class="pc-loading">Loading history…</div>';
    const res = await api('GET', 'history', { poemId });
    if (!res.ok) { panel.innerHTML = '<div class="pc-empty">Failed to load history.</div>'; return; }
    const history = res.data.history || [];
    panel.dataset.loaded = 'true';
    panel.innerHTML = `
      <div class="pc-section-title">Version History</div>
      ${history.length ? `<div class="pc-history-list">${history.map(h => `
        <div class="pc-history-item" data-history-id="${h.id}">
          <div class="pc-history-row">
            <div class="meta">${fmtDate(h.snapshotAt)} — "${escapeHtml(h.title)}"</div>
            <div class="pc-history-actions">
              <button class="button secondary preview-btn" type="button">Preview</button>
              <button class="button secondary changes-btn" type="button">Changes</button>
              <button class="button secondary restore-btn" type="button">Restore</button>
            </div>
          </div>
          <div class="pc-history-detail" style="display:none;"></div>
        </div>
      `).join('')}</div>` : '<div class="pc-empty">No history yet — history is only saved when you edit a poem more than an hour after its last edit.</div>'}
    `;

    history.forEach(h => {
      const item = panel.querySelector(`[data-history-id="${h.id}"]`);
      const detail = item.querySelector('.pc-history-detail');

      item.querySelector('.preview-btn').addEventListener('click', () => {
        if (detail.dataset.mode === 'preview' && detail.style.display !== 'none') {
          detail.style.display = 'none';
          detail.dataset.mode = '';
          return;
        }
        detail.dataset.mode = 'preview';
        detail.style.display = '';
        detail.innerHTML = `
          <div class="pc-preview-wrap">
            <div class="poem-preview-title">${escapeHtml(h.title)}</div>
            <div class="poem-preview-author">${escapeHtml(h.author)}</div>
            <div class="poem-preview" id="hist-preview-${h.id}"></div>
          </div>
        `;
        const box = document.getElementById(`hist-preview-${h.id}`);
        renderPoemLines(box, h.content);
        fitPoemText(box, { min: 14, max: 32 });
      });

      item.querySelector('.changes-btn').addEventListener('click', () => {
        if (detail.dataset.mode === 'changes' && detail.style.display !== 'none') {
          detail.style.display = 'none';
          detail.dataset.mode = '';
          return;
        }
        detail.dataset.mode = 'changes';
        detail.style.display = '';
        const current = state.currentPoem || {};
        const diffs = diffTextLines(
          (h.content || '').split(/\r?\n/),
          (current.content || '').split(/\r?\n/)
        );
        detail.innerHTML = renderDiffHtml(diffs);
      });

      item.querySelector('.restore-btn').addEventListener('click', async () => {
        if (!confirm('Restore this version? The current content will be saved to history first.')) return;
        const r = await api('POST', 'history', { action: 'restore', historyId: h.id });
        if (!r.ok) return showToast('Failed to restore', true);
        showToast('Version restored');
        await openEditor(r.data.poem, state.editorAnchor);
        loadPoems();
      });
    });
  }

  // ── PDF export ───────────────────────────────────────────────────────
  const PDF_STYLE = `
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Cormorant Garamond',Georgia,serif;color:#1c1c1c;background:#fff;line-height:1.6}
    @page{size:A4;margin:22mm}
    .cover{background:#0d0d0d;color:#fff;min-height:100vh;display:flex;flex-direction:column;justify-content:center;align-items:center;page-break-after:always;text-align:center;padding:2rem;}
    .cover-title{font-family:'Playfair Display',serif;font-size:44pt;font-weight:700;}
    .cover-desc{font-family:'Cormorant Garamond',serif;font-size:14pt;color:#ccc;max-width:70%;margin-top:1rem;}
    .cover-meta{font-family:Arial,sans-serif;font-size:9pt;letter-spacing:.15em;text-transform:uppercase;color:#888;margin-top:2rem;}
    .toc{page-break-after:always;}
    .toc h2{font-family:'Playfair Display',serif;font-size:20pt;border-bottom:2pt solid #1c1c1c;padding-bottom:8pt;margin-bottom:16pt;}
    .toc ol{padding-left:1.2em;font-size:12pt;}
    .toc li{margin-bottom:6pt;}
    .poem-page{page-break-before:always;padding-top:8vh;text-align:center;}
    .poem-title{font-family:'Playfair Display',serif;font-size:22pt;font-weight:700;margin-bottom:4pt;}
    .poem-author{font-family:Arial,sans-serif;font-size:10pt;color:#666;margin-bottom:6pt;}
    .poem-date{font-family:Arial,sans-serif;font-size:9pt;color:#999;margin-bottom:28pt;}
    .poem-body{font-size:22pt;}
    .poem-line{white-space:nowrap;line-height:1.5;}
  `;

  const PDF_FIT_SCRIPT = `
    function fitPoem(container){
      var min=13, max=parseFloat(getComputedStyle(container).fontSize);
      var fs=max;
      function overflows(){
        var cw=container.clientWidth;
        var lines=container.querySelectorAll('.poem-line');
        for(var i=0;i<lines.length;i++){ if(lines[i].scrollWidth>cw+0.5) return true; }
        return false;
      }
      container.style.fontSize=fs+'pt';
      while(fs>min && overflows()){ fs-=1; container.style.fontSize=fs+'pt'; }
    }
    document.querySelectorAll('.poem-body').forEach(fitPoem);
  `;

  function poemLinesHtml(content) {
    return String(content || '').replace(/\r\n/g, '\n').split('\n')
      .map(l => `<div class="poem-line">${l.trim() === '' ? '&nbsp;' : escapeHtml(l)}</div>`)
      .join('');
  }

  function openPrintWindow(html) {
    const blob = new Blob([html], { type: 'text/html; charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const win = window.open(url, '_blank');
    if (!win) {
      URL.revokeObjectURL(url);
      alert('Pop-up blocked. Please allow pop-ups for this site, then try exporting again.');
      return;
    }
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }

  function exportPoemPDF(poem) {
    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escapeHtml(poem.title)}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Cormorant+Garamond&display=swap" rel="stylesheet">
    <style>${PDF_STYLE}</style></head><body>
    <div class="poem-page" style="page-break-before:auto;">
      <div class="poem-title">${escapeHtml(poem.title)}</div>
      <div class="poem-author">${escapeHtml(poem.author)}</div>
      <div class="poem-date">${poem.writtenDate || ''}</div>
      <div class="poem-body">${poemLinesHtml(poem.content)}</div>
    </div>
    <script>${PDF_FIT_SCRIPT}
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
    </script>
    </body></html>`;
    openPrintWindow(html);
  }

  function exportBookPDF(book, poems) {
    const tocHtml = poems.map((p, i) => `<li>${i + 1}. ${escapeHtml(p.title)}</li>`).join('');
    const pagesHtml = poems.map(p => `
      <div class="poem-page">
        <div class="poem-title">${escapeHtml(p.title)}</div>
        <div class="poem-author">${escapeHtml(p.author)}</div>
        <div class="poem-date">${p.writtenDate || ''}</div>
        <div class="poem-body">${poemLinesHtml(p.content)}</div>
      </div>
    `).join('');
    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escapeHtml(book.title)}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Cormorant+Garamond&display=swap" rel="stylesheet">
    <style>${PDF_STYLE}</style></head><body>
    <div class="cover">
      <div class="cover-title">${escapeHtml(book.title)}</div>
      ${book.description ? `<div class="cover-desc">${escapeHtml(book.description)}</div>` : ''}
      <div class="cover-meta">Eero Laine — ${poems.length} poem${poems.length === 1 ? '' : 's'}</div>
    </div>
    <div class="toc"><h2>Contents</h2><ol>${tocHtml}</ol></div>
    ${pagesHtml}
    <script>${PDF_FIT_SCRIPT}
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
    </script>
    </body></html>`;
    openPrintWindow(html);
  }

  // ── Statistics ───────────────────────────────────────────────────────
  let statsAllPoemsCache = null;
  const statsSelectedWords = new Set();

  // Fetches every poem's full content once, so clicking "most used words" can find
  // matches locally without re-querying per click. Not the same list as state.poems,
  // which is scoped to whatever the Poems tab's filters currently show.
  async function ensureStatsPoemsLoaded() {
    if (statsAllPoemsCache) return statsAllPoemsCache;
    const res = await api('GET', 'poems');
    statsAllPoemsCache = res.ok ? (res.data.poems || []) : [];
    return statsAllPoemsCache;
  }

  // Mirrors stats.php's tokenization (strip [Verse]-style tags, lowercase, split on
  // non-letter/number runs) so a word click matches the same tokens it was counted from.
  function tokenizeContent(content) {
    const stripped = String(content || '').replace(/\[[^\]]*\]/g, ' ');
    return new Set(
      stripped.toLowerCase().split(/[^\p{L}\p{N}']+/u)
        .map(t => t.replace(/^'+|'+$/g, ''))
        .filter(Boolean)
    );
  }

  function poemsMatchingWords(poems, words) {
    if (!words.size) return [];
    const wordList = Array.from(words);
    return poems
      .filter(p => {
        const tokens = tokenizeContent(p.content);
        return wordList.every(w => tokens.has(w));
      })
      .sort((a, b) => (b.writtenDate || '').localeCompare(a.writtenDate || ''));
  }

  async function loadStats() {
    el.statsContent.innerHTML = '<div class="pc-loading">Loading statistics…</div>';
    const res = await api('GET', 'stats');
    if (!res.ok) { el.statsContent.innerHTML = '<div class="pc-empty">Failed to load statistics.</div>'; return; }
    state.statsData = res.data;
    statsSelectedWords.clear();
    renderStats();
  }

  function renderStats() {
    const s = state.statsData;
    if (!s) return;
    const maxMonth = Math.max(1, ...s.poemsPerMonth.map(m => m.count));

    el.statsContent.innerHTML = `
      <div class="pc-stats-grid">
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.totalPoems}</div><div class="pc-stat-label">Poems</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.uniqueWords}</div><div class="pc-stat-label">Unique Words</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.avgWordsPerPoem}</div><div class="pc-stat-label">Avg Words</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.avgVersesPerPoem}</div><div class="pc-stat-label">Avg Verses</div></div>
      </div>

      <div class="pc-section-title">Poems Per Month</div>
      ${s.poemsPerMonth.length ? s.poemsPerMonth.map(m => `
        <div class="pc-bar-row">
          <div class="pc-bar-label">${m.month}</div>
          <div class="pc-bar-track"><div class="pc-bar-fill" style="width:${(m.count / maxMonth) * 100}%"></div></div>
          <div class="pc-bar-count">${m.count}</div>
        </div>
      `).join('') : '<div class="pc-empty">No poems yet.</div>'}

      <div class="pc-section-title">Most Used Words <span class="pc-section-subtitle">(by poems it appears in, not raw occurrences — click one or more to find poems using them)</span></div>
      <div class="pc-word-list">
        ${s.topWords.length ? s.topWords.map(w => `<span class="pc-word-chip stats-word-chip ${statsSelectedWords.has(w.word) ? 'selected' : ''}" data-word="${escapeHtml(w.word)}">${escapeHtml(w.word)} <strong>${w.count}</strong></span>`).join('') : '<div class="pc-empty">No poems yet.</div>'}
      </div>

      <div id="stats-word-matches"></div>
    `;

    el.statsContent.querySelectorAll('.stats-word-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        const word = chip.dataset.word;
        if (statsSelectedWords.has(word)) statsSelectedWords.delete(word); else statsSelectedWords.add(word);
        renderStats();
      });
    });

    renderStatsWordMatches();
  }

  async function renderStatsWordMatches() {
    const panel = document.getElementById('stats-word-matches');
    if (!panel) return;
    if (!statsSelectedWords.size) { panel.innerHTML = ''; return; }
    panel.innerHTML = '<div class="pc-loading">Finding matches…</div>';
    const poems = await ensureStatsPoemsLoaded();
    if (!document.getElementById('stats-word-matches')) return; // tab changed while loading
    const matches = poemsMatchingWords(poems, statsSelectedWords);
    const n = statsSelectedWords.size;
    panel.innerHTML = `
      <div class="pc-section-title" style="margin-top:1.5rem;">${n} word${n === 1 ? '' : 's'} in (${matches.length} poem${matches.length === 1 ? '' : 's'})</div>
      ${matches.length ? matches.map(p => `
        <div class="pc-card stats-match-card" data-poem-id="${p.id}">
          <div class="pc-card-title"><span class="title-text">${escapeHtml(p.title)}</span><span class="pc-card-date">${escapeHtml(fmtWrittenDate(p.writtenDate))}</span></div>
        </div>
      `).join('') : '<div class="pc-empty">No poems contain all the selected words.</div>'}
    `;
    panel.querySelectorAll('.stats-match-card').forEach(card => {
      card.addEventListener('click', async () => {
        const id = Number(card.dataset.poemId);
        switchTab('poems');
        el.tabs.poems.classList.add('active');
        await openEditorById(id, { view: 'poems' });
      });
    });
  }

  // ── Init / auth gate ─────────────────────────────────────────────────
  (async function init() {
    if (!authToken) { el.authGate.style.display = ''; return; }
    const res = await api('GET', 'poems');
    if (res.status === 401 || res.status === 403) { el.authGate.style.display = ''; return; }
    if (!res.ok) { el.authGate.style.display = ''; return; }
    el.app.style.display = '';
    await ensureTagsLoaded();
    state.poems = res.data.poems || [];
    renderPoemList();
    await loadBooks();

    const qs = new URLSearchParams(location.search);
    const initialView = qs.get('view');
    if (initialView && el.views[initialView]) switchTab(initialView);
  })();
})();

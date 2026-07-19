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
    poemList: document.getElementById('poem-list'),
    poemEditor: document.getElementById('poem-editor'),
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
    poems: [],
    currentPoem: null,
    selectedTagIds: new Set(),
    expandedBookId: null,
    view: 'poems',
    collapsed: { preview: false, content: false },
  };

  const showToast = (msg, isError) => {
    el.toast.textContent = msg;
    el.toast.style.background = isError ? '#ff0066' : '#00aa77';
    el.toast.style.display = 'block';
    setTimeout(() => { el.toast.style.display = 'none'; }, 3200);
  };

  const fmtDate = (s) => s ? new Date(s.replace(' ', 'T')).toLocaleString() : '—';
  const todayStr = () => new Date().toISOString().slice(0, 10);

  // ── Tabs ─────────────────────────────────────────────────────────────
  function switchTab(view) {
    state.view = view;
    Object.entries(el.tabs).forEach(([k, btn]) => btn.classList.toggle('active', k === view));
    Object.entries(el.views).forEach(([k, sec]) => sec.style.display = k === view ? '' : 'none');
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
          <span class="dot" style="width:0.9rem;height:0.9rem;border-radius:50%;background:${escapeHtml(t.color || '#00ffcc')};display:inline-block;"></span>
          ${escapeHtml(t.name)}
        </div>
        <div class="pc-card-meta">
          <span>${t.poemCount} poem${t.poemCount === 1 ? '' : 's'}</span>
          <input type="color" class="tag-color-input" value="${t.color || '#00ffcc'}" data-id="${t.id}" style="width:2.2rem;height:1.6rem;padding:0;border:none;background:none;cursor:pointer;" />
          <button class="button danger tag-delete-btn" data-id="${t.id}" type="button" style="padding:0.2rem 0.6rem;font-size:0.78rem;">Delete</button>
        </div>
      </div>
    `).join('');

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
          ${b.isPublished ? '<span class="pc-badge published">Published</span>' : ''}
        </div>
        ${state.expandedBookId === b.id ? `<div class="pc-book-detail" data-detail-for="${b.id}"><div class="pc-loading">Loading…</div></div>` : ''}
      </div>
    `).join('');

    el.bookList.querySelectorAll('.pc-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('[data-detail-for]')) return;
        const id = Number(card.dataset.bookId);
        state.expandedBookId = state.expandedBookId === id ? null : id;
        renderBookList();
        if (state.expandedBookId === id) loadBookDetail(id);
      });
    });
  }

  async function loadBookDetail(bookId) {
    const book = state.books.find(b => b.id === bookId);
    const res = await api('GET', 'poems', { bookId });
    const poems = res.ok ? (res.data.poems || []) : [];
    const detail = document.querySelector(`[data-detail-for="${bookId}"]`);
    if (!detail) return;
    detail.innerHTML = `
      <div class="pc-editor-row"><label>Title</label><input type="text" class="book-title-input" value="${escapeHtml(book.title)}" /></div>
      <div class="pc-editor-row"><label>Description</label><textarea class="book-desc-input" style="min-height:70px;">${escapeHtml(book.description || '')}</textarea></div>
      <div class="pc-actions">
        <button class="button secondary book-save-btn" type="button">Save</button>
        <button class="button ${book.isPublished ? 'danger' : 'secondary'} book-publish-btn" type="button">${book.isPublished ? 'Unpublish' : 'Publish'}</button>
        <button class="button secondary book-export-btn" type="button">Export Book PDF</button>
        <button class="button danger book-delete-btn" type="button">Delete Book</button>
      </div>
      <div class="pc-section-title" style="margin-top:1rem;">Poems in this book</div>
      ${poems.length ? poems.map((p, i) => `
        <div class="pc-book-poem-row" data-poem-id="${p.id}">
          <button class="pc-reorder-btn" data-dir="up" ${i === 0 ? 'disabled' : ''} type="button">↑</button>
          <button class="pc-reorder-btn" data-dir="down" ${i === poems.length - 1 ? 'disabled' : ''} type="button">↓</button>
          <span class="title">${escapeHtml(p.title)}</span>
          <button class="button secondary open-poem-btn" type="button" style="padding:0.25rem 0.6rem;font-size:0.78rem;">Open</button>
        </div>
      `).join('') : '<div class="pc-empty">No poems in this book yet.</div>'}
    `;

    detail.querySelector('.book-save-btn').addEventListener('click', async () => {
      const title = detail.querySelector('.book-title-input').value.trim();
      const description = detail.querySelector('.book-desc-input').value;
      if (!title) return showToast('Title required', true);
      const r = await api('PUT', 'books', { id: bookId, title, description });
      if (r.ok) { showToast('Book saved'); loadBooks(); } else showToast('Failed to save book', true);
    });
    detail.querySelector('.book-publish-btn').addEventListener('click', async () => {
      const r = await api('POST', 'publish', { type: 'book', id: bookId, isPublished: !book.isPublished });
      if (r.ok) { showToast(book.isPublished ? 'Unpublished' : 'Published'); loadBooks(); } else showToast('Failed', true);
    });
    detail.querySelector('.book-delete-btn').addEventListener('click', async () => {
      if (!confirm('Delete this book? Its poems will become unsorted, not deleted.')) return;
      const r = await api('DELETE', 'books', { id: bookId });
      if (r.ok) { showToast('Book deleted'); state.expandedBookId = null; loadBooks(); } else showToast('Failed to delete book', true);
    });
    detail.querySelector('.book-export-btn').addEventListener('click', () => exportBookPDF(book, poems));

    detail.querySelectorAll('.open-poem-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.closest('[data-poem-id]').dataset.poemId);
        switchTab('poems');
        el.tabs.poems.classList.add('active');
        await openEditorById(id);
      });
    });
    detail.querySelectorAll('.pc-reorder-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const row = btn.closest('[data-poem-id]');
        const id = Number(row.dataset.poemId);
        const idx = poems.findIndex(p => p.id === id);
        const dir = btn.dataset.dir;
        const swapIdx = dir === 'up' ? idx - 1 : idx + 1;
        if (swapIdx < 0 || swapIdx >= poems.length) return;
        const a = poems[idx], b2 = poems[swapIdx];
        await api('PUT', 'poem', { id: a.id, sortOrder: b2.sortOrder });
        await api('PUT', 'poem', { id: b2.id, sortOrder: a.sortOrder });
        loadBookDetail(bookId);
      });
    });
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
  el.btnNewPoem.addEventListener('click', () => openEditor(null));

  async function loadPoems() {
    const params = { q: el.poemSearch.value.trim() || undefined };
    if (el.poemBookFilter.value !== '') params.bookId = el.poemBookFilter.value;
    const res = await api('GET', 'poems', params);
    if (!res.ok) { showToast('Failed to load poems', true); return; }
    state.poems = res.data.poems || [];
    renderPoemList();
  }

  function poemSnippet(content) {
    const line = (content || '').split(/\r?\n/).find(l => l.trim() !== '') || '';
    return line.length > 60 ? line.slice(0, 60) + '…' : line;
  }

  function renderPoemList() {
    if (!state.poems.length) {
      el.poemList.innerHTML = '<div class="pc-empty">No poems found.</div>';
      return;
    }
    el.poemList.innerHTML = state.poems.map(p => `
      <div class="pc-card ${state.currentPoem && state.currentPoem.id === p.id ? 'active' : ''}" data-poem-id="${p.id}">
        <div class="pc-card-title">${escapeHtml(p.title)}</div>
        <div class="pc-card-meta">
          <span>${escapeHtml(p.author)}</span>
          ${p.bookTitle ? `<span class="pc-badge">${escapeHtml(p.bookTitle)}</span>` : '<span class="pc-badge">Unsorted</span>'}
          ${p.isPublished ? '<span class="pc-badge published">Published</span>' : ''}
          ${p.originalPoemId ? '<span class="pc-badge variant">Variant</span>' : ''}
          ${(p.tags || []).map(t => `<span class="pc-tag-chip" style="color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}22;">${escapeHtml(t.name)}</span>`).join('')}
        </div>
        <div class="pc-card-meta" style="color:#777;font-style:italic;">${escapeHtml(poemSnippet(p.content))}</div>
      </div>
    `).join('');

    el.poemList.querySelectorAll('.pc-card').forEach(card => {
      card.addEventListener('click', () => openEditorById(Number(card.dataset.poemId)));
    });
  }

  // ── Editor ───────────────────────────────────────────────────────────
  async function openEditorById(id) {
    const res = await api('GET', 'poem', { id });
    if (!res.ok) { showToast('Failed to load poem', true); return; }
    openEditor(res.data.poem);
  }

  async function openEditor(poem) {
    await ensureTagsLoaded();
    state.currentPoem = poem;
    state.selectedTagIds = new Set((poem && poem.tags || []).map(t => t.id));
    renderEditor();
    renderPoemList();
    el.poemEditor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function renderEditor() {
    const p = state.currentPoem || {
      id: null, title: '', author: 'Eero Laine', content: '', bookId: null,
      writtenDate: todayStr(), geniusUrl: null, isPublished: false, originalPoemId: null, originalTitle: null, historyCount: 0,
    };

    el.poemEditor.innerHTML = `
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
          <div class="pc-editor-row"><label>Written on</label><input type="date" id="ed-written-date" value="${p.writtenDate || todayStr()}" /></div>
        </div>

        <div class="pc-editor-grid two">
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

    document.querySelectorAll('.pc-collapsible-toggle').forEach(btn => {
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

    document.getElementById('btn-close-editor').addEventListener('click', () => { state.currentPoem = null; el.poemEditor.innerHTML = ''; renderPoemList(); });
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
        openEditorById(p.originalPoemId);
      });
    }
  }

  function renderTagPicker() {
    const wrap = document.getElementById('ed-tags');
    if (!wrap) return;
    const scrollY = window.scrollY;
    wrap.innerHTML = state.tags.map(t => `
      <span class="pc-tag-chip tag-toggle ${state.selectedTagIds.has(t.id) ? 'selected' : ''}" data-id="${t.id}" style="color:${escapeHtml(t.color || '#00ffcc')};background:${escapeHtml(t.color || '#00ffcc')}${state.selectedTagIds.has(t.id) ? '33' : '15'};">
        <span class="dot" style="background:${escapeHtml(t.color || '#00ffcc')};"></span>${escapeHtml(t.name)}
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
      writtenDate: document.getElementById('ed-written-date').value,
    };
  }

  async function savePoem() {
    const title = document.getElementById('ed-title').value.trim();
    const author = document.getElementById('ed-author').value.trim() || 'Eero Laine';
    const content = document.getElementById('ed-content').value;
    const bookVal = document.getElementById('ed-book').value;
    const writtenDate = document.getElementById('ed-written-date').value;
    const geniusUrl = document.getElementById('ed-genius-url').value.trim();
    if (!title) return showToast('Title required', true);
    if (bookVal === '__new__') return showToast('Finish creating the book first', true);

    const payload = {
      title, author, content, writtenDate, geniusUrl,
      bookId: bookVal === '' ? 0 : Number(bookVal),
      tagIds: Array.from(state.selectedTagIds),
    };

    const isNew = !state.currentPoem || !state.currentPoem.id;
    const res = isNew
      ? await api('POST', 'poems', payload)
      : await api('PUT', 'poem', { id: state.currentPoem.id, ...payload });

    if (!res.ok) { showToast((res.data && res.data.error) || 'Failed to save poem', true); return; }
    showToast('Poem saved');
    const scrollY = window.scrollY;
    state.currentPoem = res.data.poem;
    renderEditor();
    await loadPoems();
    window.scrollTo(0, scrollY);
  }

  async function togglePublishPoem(p) {
    const res = await api('POST', 'publish', { type: 'poem', id: p.id, isPublished: !p.isPublished });
    if (!res.ok) return showToast('Failed to update publish state', true);
    showToast(p.isPublished ? 'Unpublished' : 'Published');
    await openEditorById(p.id);
    loadPoems();
  }

  async function createVariant(p) {
    const res = await api('POST', 'variant', { poemId: p.id });
    if (!res.ok) return showToast('Failed to create new version', true);
    showToast('New version created');
    await openEditor(res.data.poem);
    loadPoems();
  }

  async function deletePoem(p) {
    if (!confirm(`Delete "${p.title}"? Poems older than an hour are archived (soft-deleted) rather than erased.`)) return;
    const res = await api('DELETE', 'poem', { id: p.id });
    if (!res.ok) return showToast('Failed to delete poem', true);
    showToast(res.data.mode === 'soft' ? 'Poem archived' : 'Poem deleted');
    state.currentPoem = null;
    el.poemEditor.innerHTML = '';
    loadPoems();
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
          <div class="meta">${fmtDate(h.snapshotAt)} — "${escapeHtml(h.title)}"</div>
          <button class="button secondary restore-btn" type="button">Restore</button>
        </div>
      `).join('')}</div>` : '<div class="pc-empty">No history yet — history is only saved when you edit a poem more than an hour after its last edit.</div>'}
    `;
    panel.querySelectorAll('.restore-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const historyId = Number(btn.closest('[data-history-id]').dataset.historyId);
        if (!confirm('Restore this version? The current content will be saved to history first.')) return;
        const r = await api('POST', 'history', { action: 'restore', historyId });
        if (!r.ok) return showToast('Failed to restore', true);
        showToast('Version restored');
        await openEditor(r.data.poem);
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
  async function loadStats() {
    el.statsContent.innerHTML = '<div class="pc-loading">Loading statistics…</div>';
    const res = await api('GET', 'stats');
    if (!res.ok) { el.statsContent.innerHTML = '<div class="pc-empty">Failed to load statistics.</div>'; return; }
    const s = res.data;
    const maxMonth = Math.max(1, ...s.poemsPerMonth.map(m => m.count));
    const maxWord = Math.max(1, ...s.topWords.map(w => w.count));

    el.statsContent.innerHTML = `
      <div class="pc-stats-grid">
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.totalPoems}</div><div class="pc-stat-label">Poems</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.uniqueWords}</div><div class="pc-stat-label">Words</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.avgWordsPerPoem}</div><div class="pc-stat-label">Avg Words / Poem</div></div>
        <div class="pc-stat-tile"><div class="pc-stat-value">${s.avgVersesPerPoem}</div><div class="pc-stat-label">Avg Verses / Poem</div></div>
      </div>

      <div class="pc-section-title">Poems Per Month</div>
      ${s.poemsPerMonth.length ? s.poemsPerMonth.map(m => `
        <div class="pc-bar-row">
          <div class="pc-bar-label">${m.month}</div>
          <div class="pc-bar-track"><div class="pc-bar-fill" style="width:${(m.count / maxMonth) * 100}%"></div></div>
          <div class="pc-bar-count">${m.count}</div>
        </div>
      `).join('') : '<div class="pc-empty">No poems yet.</div>'}

      <div class="pc-section-title">Most Used Words <span class="pc-section-subtitle">(by poems it appears in, not raw occurrences)</span></div>
      <div class="pc-word-list">
        ${s.topWords.length ? s.topWords.map(w => `<span class="pc-word-chip">${escapeHtml(w.word)} <strong>${w.count}</strong></span>`).join('') : '<div class="pc-empty">No poems yet.</div>'}
      </div>
    `;
  }

  // ── Init / auth gate ─────────────────────────────────────────────────
  (async function init() {
    if (!authToken) { el.authGate.style.display = ''; return; }
    const res = await api('GET', 'poems');
    if (res.status === 401 || res.status === 403) { el.authGate.style.display = ''; return; }
    if (!res.ok) { el.authGate.style.display = ''; return; }
    el.app.style.display = '';
    state.poems = res.data.poems || [];
    renderPoemList();
    await loadBooks();
  })();
})();

(() => {
  const API = '../api/poem-center';
  const { escapeHtml, renderPoemLines, fitPoemText } = window.PoemFitText;

  const el = {
    filterToggleBtn: document.getElementById('filter-toggle-btn'),
    filterBadge: document.getElementById('filter-badge'),
    filtersSection: document.getElementById('filters-section'),
    name: document.getElementById('f-name'),
    start: document.getElementById('f-start'),
    end: document.getElementById('f-end'),
    tag: document.getElementById('f-tag'),
    filterCount: document.getElementById('filter-count'),
    clear: document.getElementById('clear-filters'),
    content: document.getElementById('content'),
  };

  let rawBooks = [];
  let rawPoems = [];
  let allTags = [];
  const poemsById = new Map();

  // ── Filter toggle ────────────────────────────────────────────────────
  el.filterToggleBtn.addEventListener('click', () => {
    const isOpen = el.filtersSection.classList.toggle('filters-open');
    el.filterToggleBtn.classList.toggle('active', isOpen);
    el.filterToggleBtn.setAttribute('aria-expanded', String(isOpen));
  });

  function collectTags() {
    const map = new Map();
    rawPoems.forEach(p => (p.tags || []).forEach(t => map.set(t.id, t)));
    rawBooks.forEach(b => (b.poems || []).forEach(p => (p.tags || []).forEach(t => map.set(t.id, t))));
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
  }

  function renderTagFilterList() {
    const current = el.tag.value;
    el.tag.innerHTML = '<option value="">Any</option>' +
      allTags.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
    if (allTags.some(t => String(t.id) === current)) el.tag.value = current;
  }

  // ── Filtering ────────────────────────────────────────────────────────
  const countActiveFilters = () => {
    let n = 0;
    if (el.name.value.trim()) n++;
    if (el.start.value) n++;
    if (el.end.value) n++;
    if (el.tag.value) n++;
    return n;
  };

  const updateFilterBadge = () => {
    const n = countActiveFilters();
    el.filterBadge.textContent = n;
    el.filterBadge.classList.toggle('visible', n > 0);
    el.filterCount.textContent = n ? `(${n} filter${n !== 1 ? 's' : ''})` : '';
    el.clear.disabled = n === 0;
  };

  function poemMatches(p) {
    const name = el.name.value.trim().toLowerCase();
    if (name && !p.title.toLowerCase().includes(name)) return false;
    if (el.start.value && (!p.writtenDate || p.writtenDate < el.start.value)) return false;
    if (el.end.value && (!p.writtenDate || p.writtenDate > el.end.value)) return false;
    if (el.tag.value && !(p.tags || []).some(t => String(t.id) === el.tag.value)) return false;
    return true;
  }

  [el.name, el.start, el.end].forEach(ctrl => {
    ctrl.addEventListener('input', applyFilters);
  });
  el.tag.addEventListener('change', applyFilters);

  el.clear.addEventListener('click', () => {
    el.name.value = '';
    el.start.value = '';
    el.end.value = '';
    el.tag.value = '';
    applyFilters();
  });

  // ── Rendering ────────────────────────────────────────────────────────
  function poemCardHtml(p) {
    poemsById.set(String(p.id), p);
    return `
      <article class="pw-poem-card" data-poem-id="${p.id}">
        <button class="pw-collapse-btn" type="button" data-poem-id="${p.id}" aria-expanded="true" aria-label="Collapse poem">▾</button>
        <button class="pw-copy-btn" type="button" data-poem-id="${p.id}" aria-label="Copy poem">
          📎<span class="pw-tooltip">Copy poem</span>
        </button>
        <div class="pw-poem-title">${escapeHtml(p.title)}</div>
        <div class="pw-poem-author">${escapeHtml(p.author)}</div>
        <div class="pw-poem-collapsible">
          ${p.writtenDate ? `<div class="pw-poem-date">${escapeHtml(p.writtenDate)}</div>` : ''}
          <div class="poem-body" data-poem-id="${p.id}"></div>
          ${(p.tags || []).length ? `<div class="pw-poem-tags">${p.tags.map(t => `<span class="tag-chip-filter" style="color:${escapeHtml(t.color || '#00ffcc')};"><span class="dot" style="background:${escapeHtml(t.color || '#00ffcc')};"></span>${escapeHtml(t.name)}</span>`).join('')}</div>` : ''}
        </div>
      </article>
    `;
  }

  function renderContent() {
    const books = rawBooks
      .map(b => ({ ...b, poems: (b.poems || []).filter(poemMatches) }))
      .filter(b => b.poems.length > 0);
    const poems = rawPoems.filter(poemMatches);

    if (!books.length && !poems.length) {
      el.content.innerHTML = '<div class="pw-empty">No poems match your filters.</div>';
      return;
    }

    el.content.innerHTML = [
      ...books.map(b => `
        <section class="pw-book">
          <div class="pw-book-title">${escapeHtml(b.title)}</div>
          ${b.description ? `<div class="pw-book-desc">${escapeHtml(b.description)}</div>` : ''}
          ${b.poems.map(poemCardHtml).join('')}
        </section>
      `),
      ...poems.map(poemCardHtml),
    ].join('');

    fitAllPoems();
  }

  function applyFilters() {
    updateFilterBadge();
    if (!rawBooks.length && !rawPoems.length) {
      el.content.innerHTML = '<div class="pw-empty">No poems published yet — check back soon.</div>';
      return;
    }
    renderContent();
  }

  function fitAllPoems() {
    document.querySelectorAll('.poem-body').forEach(box => {
      const p = poemsById.get(box.dataset.poemId);
      if (!p) return;
      renderPoemLines(box, p.content);
      fitPoemText(box, { min: 15, max: 34 });
    });
  }

  let resizeDebounce = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeDebounce);
    resizeDebounce = setTimeout(() => {
      document.querySelectorAll('.pw-poem-card:not(.pw-collapsed) .poem-body').forEach(box => fitPoemText(box, { min: 15, max: 34 }));
    }, 100);
  });

  // ── Collapse / copy (event delegation — cards re-render on filter) ────
  el.content.addEventListener('click', (e) => {
    const collapseBtn = e.target.closest('.pw-collapse-btn');
    if (collapseBtn) { toggleCollapse(collapseBtn); return; }
    const copyBtn = e.target.closest('.pw-copy-btn');
    if (copyBtn) { copyPoem(copyBtn); return; }
  });

  function toggleCollapse(btn) {
    const card = btn.closest('.pw-poem-card');
    const collapsed = card.classList.toggle('pw-collapsed');
    btn.setAttribute('aria-expanded', String(!collapsed));
    btn.textContent = collapsed ? '▸' : '▾';
    if (!collapsed) {
      const box = card.querySelector('.poem-body');
      if (box) fitPoemText(box, { min: 15, max: 34 });
    }
  }

  async function copyPoem(btn) {
    const p = poemsById.get(String(btn.dataset.poemId));
    if (!p) return;
    const text = `${p.title}\n${p.author}\n\n${p.content}`;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      const tooltip = btn.querySelector('.pw-tooltip');
      if (tooltip) tooltip.textContent = 'Copied!';
      btn.classList.add('copied');
      setTimeout(() => {
        if (tooltip) tooltip.textContent = 'Copy poem';
        btn.classList.remove('copied');
      }, 1400);
    } catch (_) { /* clipboard unavailable — silently ignore */ }
  }

  // ── Init ─────────────────────────────────────────────────────────────
  (async function init() {
    try {
      const res = await fetch(API + '/public');
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      rawBooks = data.books || [];
      rawPoems = data.poems || [];
      allTags = collectTags();
      renderTagFilterList();
      applyFilters();
    } catch {
      el.content.innerHTML = '<div class="pw-empty">Failed to load poems. Please try refreshing.</div>';
    }
  })();
})();
(() => {
  const API = '../api/poem-center';
  const { escapeHtml, renderPoemLines, fitPoemText } = window.PoemFitText;

  const el = {
    tagFilter: document.getElementById('tag-filter'),
    content: document.getElementById('content'),
  };

  let allTags = [];
  let activeTag = '';

  function collectTags(data) {
    const map = new Map();
    (data.poems || []).forEach(p => (p.tags || []).forEach(t => map.set(t.id, t)));
    (data.books || []).forEach(b => (b.poems || []).forEach(p => (p.tags || []).forEach(t => map.set(t.id, t))));
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
  }

  function renderTagFilter() {
    if (!allTags.length) { el.tagFilter.innerHTML = ''; return; }
    el.tagFilter.innerHTML = [
      `<span class="tag-chip-filter ${activeTag === '' ? 'active' : ''}" data-tag="">All</span>`,
      ...allTags.map(t => `
        <span class="tag-chip-filter ${String(activeTag) === String(t.id) ? 'active' : ''}" data-tag="${t.id}">
          <span class="dot" style="background:${escapeHtml(t.color || '#00ffcc')};"></span>${escapeHtml(t.name)}
        </span>
      `),
    ].join('');
    el.tagFilter.querySelectorAll('.tag-chip-filter').forEach(chip => {
      chip.addEventListener('click', () => {
        activeTag = chip.dataset.tag;
        renderTagFilter();
        loadContent();
      });
    });
  }

  function poemCardHtml(p) {
    return `
      <article class="pw-poem-card">
        <div class="pw-poem-title">${escapeHtml(p.title)}</div>
        <div class="pw-poem-author">${escapeHtml(p.author)}</div>
        ${p.writtenDate ? `<div class="pw-poem-date">${escapeHtml(p.writtenDate)}</div>` : ''}
        <div class="poem-body" data-poem-id="${p.id}"></div>
        ${(p.tags || []).length ? `<div class="pw-poem-tags">${p.tags.map(t => `<span class="tag-chip-filter" style="color:${escapeHtml(t.color || '#00ffcc')};"><span class="dot" style="background:${escapeHtml(t.color || '#00ffcc')};"></span>${escapeHtml(t.name)}</span>`).join('')}</div>` : ''}
      </article>
    `;
  }

  function renderContent(data) {
    const books = data.books || [];
    const poems = data.poems || [];

    if (!books.length && !poems.length) {
      el.content.innerHTML = '<div class="pw-empty">No poems published yet — check back soon.</div>';
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

    fitAllPoems([...books.flatMap(b => b.poems), ...poems]);
  }

  function fitAllPoems(poemsById) {
    const byId = new Map(poemsById.map(p => [String(p.id), p]));
    document.querySelectorAll('.poem-body').forEach(box => {
      const p = byId.get(box.dataset.poemId);
      if (!p) return;
      renderPoemLines(box, p.content);
      fitPoemText(box, { min: 15, max: 34 });
    });
  }

  let resizeDebounce = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeDebounce);
    resizeDebounce = setTimeout(() => {
      document.querySelectorAll('.poem-body').forEach(box => fitPoemText(box, { min: 15, max: 34 }));
    }, 100);
  });

  async function loadContent() {
    el.content.innerHTML = '<div class="pw-empty">Loading…</div>';
    let url = API + '/public';
    if (activeTag) url += '?tag=' + encodeURIComponent(activeTag);
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      renderContent(data);
    } catch {
      el.content.innerHTML = '<div class="pw-empty">Failed to load poems. Please try refreshing.</div>';
    }
  }

  (async function init() {
    try {
      const res = await fetch(API + '/public');
      const data = await res.json();
      allTags = collectTags(data);
      renderTagFilter();
      renderContent(data);
    } catch {
      el.content.innerHTML = '<div class="pw-empty">Failed to load poems. Please try refreshing.</div>';
    }
  })();
})();

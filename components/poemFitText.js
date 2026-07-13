/* Shared "fit poem text" helper used by /poems-center and /poems.
   Renders poem content as centered lines that never wrap — instead the
   whole poem's font-size shrinks (uniformly, so it stays visually even)
   until every line fits the container's width. */

(function () {
  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderPoemLines(container, content) {
    const lines = String(content || '').replace(/\r\n/g, '\n').split('\n');
    container.innerHTML = lines
      .map(line => `<div class="poem-line">${line.trim() === '' ? '&nbsp;' : escapeHtml(line)}</div>`)
      .join('');
  }

  function fitPoemText(container, opts) {
    const { min = 12, max = 40 } = opts || {};
    if (!container.querySelector('.poem-line')) return max;

    let fontSize = max;
    container.style.fontSize = fontSize + 'px';

    const overflows = () => {
      const cw = container.clientWidth;
      const lines = container.querySelectorAll('.poem-line');
      for (const line of lines) {
        if (line.scrollWidth > cw + 0.5) return true;
      }
      return false;
    };

    while (fontSize > min && overflows()) {
      fontSize -= 1;
      container.style.fontSize = fontSize + 'px';
    }
    return fontSize;
  }

  function renderAndFit(container, content, opts) {
    renderPoemLines(container, content);
    return fitPoemText(container, opts);
  }

  function watchFit(container, getContent, opts) {
    const run = () => renderAndFit(container, getContent(), opts);
    run();
    let frame = null;
    const scheduled = () => {
      if (frame) cancelAnimationFrame(frame);
      frame = requestAnimationFrame(run);
    };
    const ro = new ResizeObserver(scheduled);
    ro.observe(container);
    window.addEventListener('resize', scheduled);
    return { refresh: run, stop: () => { ro.disconnect(); window.removeEventListener('resize', scheduled); } };
  }

  window.PoemFitText = { escapeHtml, renderPoemLines, fitPoemText, renderAndFit, watchFit };
})();

/* Shared top bar used across every page on the site.
   Usage: <rk-topbar title="Page Title"></rk-topbar>
   Any children are preserved and rendered as extra nav content on the right
   (e.g. logout buttons, secondary links) so per-page nav still works. */

(function () {
  class RkTopbar extends HTMLElement {
    connectedCallback() {
      if (this.dataset.rkReady) return;
      this.dataset.rkReady = 'true';

      const home = this.getAttribute('home') || '/';
      const title = this.getAttribute('title') || document.title;

      const extra = document.createElement('div');
      extra.className = 'rk-topbar-extra';
      while (this.firstChild) extra.appendChild(this.firstChild);

      const homeLink = document.createElement('a');
      homeLink.className = 'rk-topbar-home';
      homeLink.href = home;
      homeLink.textContent = 'Home';

      const titleEl = document.createElement('div');
      titleEl.className = 'rk-topbar-title';
      titleEl.textContent = title;

      this.classList.add('rk-topbar');
      this.append(homeLink, titleEl);
      if (extra.childNodes.length) this.append(extra);
    }
  }

  if (!customElements.get('rk-topbar')) {
    customElements.define('rk-topbar', RkTopbar);
  }
})();
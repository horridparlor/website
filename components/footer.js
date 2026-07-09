/* Shared site footer used across every page on the site.
   Usage: <rk-footer></rk-footer> renders "©2026 Rakuel".
   Pass custom markup as children to override the default content
   (e.g. anime-gacha's attribution line) while keeping the same styling. */

(function () {
  class RkFooter extends HTMLElement {
    connectedCallback() {
      if (this.dataset.rkReady) return;
      this.dataset.rkReady = 'true';

      this.classList.add('site-footer');
      document.body.style.paddingBottom = '0';

      if (!this.childNodes.length) {
        const p = document.createElement('p');
        p.textContent = '©2026 Rakuel';
        this.appendChild(p);
      }
    }
  }

  if (!customElements.get('rk-footer')) {
    customElements.define('rk-footer', RkFooter);
  }
})();
/* Shared "log in" modal — prompts for username/password against
   POST /api/user/authenticate and stores the resulting authToken/userId/username
   in localStorage on success (same keys used by deck-builder/match-queue).
   Usage: <rk-login-modal></rk-login-modal>  (place once per page)
     const loggedIn = await el.open({ message: '...' }); // true = logged in, false = cancelled
   Unlike deck-builder/match-queue's page-local login modals (which just re-render
   the page after login), this one resolves a promise so a caller can resume
   whatever action prompted the login — e.g. open an edit modal for the card that
   was selected when the shortcut was pressed — without a page reload.
   Assumes it's embedded on a page at /is-back/<name>/ — the API path is relative
   two levels up, matching every other is-back/* page. */

(function () {
  const AUTH_URL = '../../api/user/authenticate';
  const DEFAULT_MESSAGE = 'Log in to continue.';

  class RkLoginModal extends HTMLElement {
    connectedCallback() {
      if (this.dataset.rkReady) return;
      this.dataset.rkReady = 'true';

      this.innerHTML = `
        <div class="rk-lm-backdrop hidden">
          <div class="rk-lm-box">
            <h3 class="rk-lm-title">Login Required</h3>
            <p class="rk-lm-message"></p>
            <div class="rk-lm-field">
              <input type="text" class="rk-lm-username" placeholder="Username" autocomplete="username" />
            </div>
            <div class="rk-lm-field">
              <input type="password" class="rk-lm-password" placeholder="Password" autocomplete="current-password" />
            </div>
            <div class="rk-lm-error"></div>
            <div class="rk-lm-actions">
              <button type="button" class="rk-lm-btn rk-lm-btn-ghost rk-lm-cancel">Cancel</button>
              <button type="button" class="rk-lm-btn rk-lm-btn-green rk-lm-login">Log In</button>
            </div>
          </div>
        </div>
      `;

      this._backdrop  = this.querySelector('.rk-lm-backdrop');
      this._message   = this.querySelector('.rk-lm-message');
      this._username  = this.querySelector('.rk-lm-username');
      this._password  = this.querySelector('.rk-lm-password');
      this._errorEl   = this.querySelector('.rk-lm-error');
      this._loginBtn  = this.querySelector('.rk-lm-login');
      this._cancelBtn = this.querySelector('.rk-lm-cancel');

      this._resolve = null;

      this._loginBtn.addEventListener('click', () => this._submit());
      this._cancelBtn.addEventListener('click', () => this._finish(false));
      this.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); this._submit(); }
      });

      this._onKeydown = (e) => {
        if (e.key === 'Escape' && this.isOpen()) this._finish(false);
      };
      document.addEventListener('keydown', this._onKeydown);
    }

    disconnectedCallback() {
      document.removeEventListener('keydown', this._onKeydown);
    }

    isOpen() {
      return !!this._backdrop && !this._backdrop.classList.contains('hidden');
    }

    // Returns a Promise<boolean> — true once login succeeds, false on Cancel/Escape.
    // If a previous open() is still pending it's resolved false first, so only the
    // latest caller's promise ever wins.
    open({ message } = {}) {
      if (this._resolve) this._finish(false);
      this._message.textContent  = message || DEFAULT_MESSAGE;
      this._errorEl.style.display = 'none';
      this._errorEl.textContent   = '';
      this._username.value = '';
      this._password.value = '';
      this._backdrop.classList.remove('hidden');
      this._username.focus();
      return new Promise((resolve) => { this._resolve = resolve; });
    }

    _finish(success) {
      this._backdrop.classList.add('hidden');
      const resolve = this._resolve;
      this._resolve = null;
      if (resolve) resolve(success);
    }

    async _submit() {
      const username = this._username.value.trim();
      const password = this._password.value;
      if (!username || !password) {
        this._errorEl.textContent = 'Username and password are required.';
        this._errorEl.style.display = 'block';
        return;
      }
      this._loginBtn.disabled = true;
      try {
        const res  = await fetch(AUTH_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username, password }),
        });
        const data = await res.json();
        if (!res.ok || data.error) {
          this._errorEl.textContent = data.error || 'Login failed.';
          this._errorEl.style.display = 'block';
          return;
        }
        localStorage.setItem('authToken', data.authToken);
        localStorage.setItem('userId', String(data.userId));
        localStorage.setItem('username', data.firstname || username);
        this._finish(true);
      } catch (e) {
        this._errorEl.textContent = 'Network error: ' + e.message;
        this._errorEl.style.display = 'block';
      } finally {
        this._loginBtn.disabled = false;
      }
    }
  }

  if (!customElements.get('rk-login-modal')) {
    customElements.define('rk-login-modal', RkLoginModal);
  }
})();

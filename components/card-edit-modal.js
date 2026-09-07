/* Shared "edit a card" modal — metadata fields (name/power/type/keywords) plus
   Card Art / Card Image upload panes, with the same `?v=<updatedAt>` cache-busting
   convention used for grid thumbnails across is-back/*.
   Usage: <rk-card-edit-modal></rk-card-edit-modal>  (place once per page)
     el.open(card, initialVersion) → opens the modal populated from `card`, which must carry:
       id, name, power, cardTypeId, keywordId, keyword2Id, keyword3Id, altArts,
       cardArtUpdatedAt, cardImageUpdatedAt (i.e. a row from GET /api/is-back/cards)
       `initialVersion` (optional, 1-based, default 1) preselects the Art Version
       dropdown — e.g. a gallery's currently middle-click-cycled version — so the
       previews shown, and any image uploaded, target that version instead of the base art.
   Fires 'rk-card-saved' (bubbles) on the element after a successful metadata save:
     detail: { card, artUpdated, imageUpdated, error }
   `card` is the fresh row from the server, with cardArtUpdatedAt/cardImageUpdatedAt
   locally bumped for whichever of artUpdated/imageUpdated is true (the upload
   endpoint doesn't return the updated row). `error` covers a partial failure
   (e.g. a rename or upload problem) even though the save itself succeeded.
   Fires 'rk-modal-closed' (bubbles) on the element whenever the modal closes for
   any reason (Save, Cancel, or Escape): detail: { card } — the card that was open.
   Assumes it's embedded on a page at /is-back/<name>/ — image and API paths are
   relative two levels up, matching every other is-back/* page. */

(function () {
  const API_BASE     = '../../api/is-back';
  const CARDS_URL    = API_BASE + '/cards';
  const KEYWORDS_URL = API_BASE + '/keywords';

  const removeBB = (s) => (s || '').replace(/\[[^\]]*\]/g, '');
  const cardImgName = (id, name) => {
    const cleaned = removeBB(name);
    const n = cleaned.startsWith('The ') ? cleaned.slice(4) : cleaned.startsWith('The_') ? cleaned.slice(3) : cleaned;
    return `${id} - ${n}`;
  };
  const cardArtPath   = (id, name, av, updatedAt) => `../card-art/${encodeURIComponent(cardImgName(id, name) + ((av && av > 1) ? ` (${av})` : ''))}.png` + (updatedAt ? `?v=${updatedAt}` : '');
  const cardImagePath = (id, name, av, updatedAt) => `../card-images/${encodeURIComponent(cardImgName(id, name) + ((av && av > 1) ? ` (${av})` : ''))}.png` + (updatedAt ? `?v=${updatedAt}` : '');

  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  class RkCardEditModal extends HTMLElement {
    connectedCallback() {
      if (this.dataset.rkReady) return;
      this.dataset.rkReady = 'true';

      this.innerHTML = `
        <div class="rk-cem-backdrop hidden">
          <div class="rk-cem-box">
            <h3 class="rk-cem-title"></h3>
            <div class="rk-cem-error"></div>

            <div class="rk-cem-art-section">
              <div class="rk-cem-art-pane">
                <h4>Card Art (card-art/)</h4>
                <div class="rk-cem-art-preview"><span class="rk-cem-no-img">No image</span></div>
                <input type="file" class="rk-cem-art-file" accept="image/png" />
              </div>
              <div class="rk-cem-art-pane">
                <h4>Card Image (card-images/)</h4>
                <div class="rk-cem-image-preview"><span class="rk-cem-no-img">No image</span></div>
                <input type="file" class="rk-cem-image-file" accept="image/png" />
              </div>
            </div>

            <div class="rk-cem-field rk-cem-version-field" style="display:none;">
              <label>Art Version</label>
              <select class="rk-cem-version"></select>
            </div>

            <div class="rk-cem-field">
              <label>Name</label>
              <input type="text" class="rk-cem-name" placeholder="e.g. Rakuel" maxlength="255" />
              <div class="rk-cem-hint">Renaming also renames this card's files in card-art/ and card-images/ to match.</div>
            </div>

            <div class="rk-cem-field-row">
              <div class="rk-cem-field">
                <label>Power</label>
                <input type="number" class="rk-cem-power" min="0" step="1000" />
              </div>
              <div class="rk-cem-field">
                <label>Type</label>
                <select class="rk-cem-type">
                  <option value="1">Rock</option>
                  <option value="2">Paper</option>
                  <option value="3">Scissors</option>
                  <option value="4">Gun</option>
                </select>
              </div>
            </div>

            <div class="rk-cem-field-row-3">
              <div class="rk-cem-field">
                <label>Keyword 1</label>
                <select class="rk-cem-keyword1"></select>
              </div>
              <div class="rk-cem-field">
                <label>Keyword 2</label>
                <select class="rk-cem-keyword2"></select>
              </div>
              <div class="rk-cem-field">
                <label>Keyword 3</label>
                <select class="rk-cem-keyword3"></select>
              </div>
            </div>

            <div class="rk-cem-actions">
              <button type="button" class="rk-cem-btn rk-cem-btn-ghost rk-cem-cancel">Cancel</button>
              <button type="button" class="rk-cem-btn rk-cem-btn-green rk-cem-save">Save</button>
            </div>
          </div>
        </div>
        <div class="rk-cem-toast"></div>
      `;

      this._backdrop     = this.querySelector('.rk-cem-backdrop');
      this._title        = this.querySelector('.rk-cem-title');
      this._errorEl      = this.querySelector('.rk-cem-error');
      this._artPreview   = this.querySelector('.rk-cem-art-preview');
      this._imagePreview = this.querySelector('.rk-cem-image-preview');
      this._artFile      = this.querySelector('.rk-cem-art-file');
      this._imageFile    = this.querySelector('.rk-cem-image-file');
      this._versionField = this.querySelector('.rk-cem-version-field');
      this._versionSel   = this.querySelector('.rk-cem-version');
      this._nameInput    = this.querySelector('.rk-cem-name');
      this._powerInput   = this.querySelector('.rk-cem-power');
      this._typeSel       = this.querySelector('.rk-cem-type');
      this._kw1           = this.querySelector('.rk-cem-keyword1');
      this._kw2           = this.querySelector('.rk-cem-keyword2');
      this._kw3           = this.querySelector('.rk-cem-keyword3');
      this._saveBtn       = this.querySelector('.rk-cem-save');
      this._cancelBtn     = this.querySelector('.rk-cem-cancel');
      this._toastEl       = this.querySelector('.rk-cem-toast');

      this._card            = null;
      this._keywords         = null; // lazily fetched + cached across opens: { all, visible }
      this._artObjectUrl     = null;
      this._imageObjectUrl   = null;
      this._toastTimer       = null;

      this._cancelBtn.addEventListener('click', () => this.close());
      this._versionSel.addEventListener('change', () => {
        if (!this._card) return;
        // A chosen-but-unsaved file was tagged for the version it was picked under —
        // switching versions without a matching re-pick would silently upload it to the
        // wrong slot, so drop it and fall back to what's on disk for the new version.
        this._clearLocalPreviews();
        this._refreshPreviews();
      });
      this._artFile.addEventListener('change', () => {
        const file = this._artFile.files[0];
        if (this._artObjectUrl) URL.revokeObjectURL(this._artObjectUrl);
        this._artObjectUrl = file ? this._previewLocalFile(this._artPreview, file) : null;
      });
      this._imageFile.addEventListener('change', () => {
        const file = this._imageFile.files[0];
        if (this._imageObjectUrl) URL.revokeObjectURL(this._imageObjectUrl);
        this._imageObjectUrl = file ? this._previewLocalFile(this._imagePreview, file) : null;
      });
      this._saveBtn.addEventListener('click', () => this._save());

      this._onKeydown = (e) => {
        if (e.key === 'Escape' && this.isOpen()) this.close();
      };
      document.addEventListener('keydown', this._onKeydown);
    }

    disconnectedCallback() {
      document.removeEventListener('keydown', this._onKeydown);
    }

    isOpen() {
      return !!this._backdrop && !this._backdrop.classList.contains('hidden');
    }

    _authHeaders(json = true) {
      const authToken = localStorage.getItem('authToken');
      return {
        ...(json ? { 'Content-Type': 'application/json' } : {}),
        ...(authToken ? { 'Authorization': 'Bearer ' + authToken } : {}),
      };
    }

    async _ensureKeywords() {
      if (this._keywords) return this._keywords;
      try {
        const res  = await fetch(KEYWORDS_URL);
        const data = await res.json();
        const all  = data.keywords || [];
        // Hidden (lowercase-named) keywords are excluded from selection, but if a card
        // already has one assigned, it stays listed for that slot so saving doesn't
        // silently clear it.
        this._keywords = { all, visible: all.filter(k => /^[A-Z]/.test(k.displayName || '')) };
      } catch (e) {
        this._keywords = { all: [], visible: [] };
      }
      return this._keywords;
    }

    _keywordOptionsHtml(currentId) {
      const { all, visible } = this._keywords;
      const current = currentId ? all.find(k => k.id === Number(currentId)) : null;
      const list = (current && !visible.some(k => k.id === current.id)) ? [...visible, current] : visible;
      return `<option value="">None</option>` + list.map(k => `<option value="${k.id}">${esc(k.displayName)}</option>`).join('');
    }

    _clearLocalPreviews() {
      if (this._artObjectUrl)   { URL.revokeObjectURL(this._artObjectUrl);   this._artObjectUrl   = null; }
      if (this._imageObjectUrl) { URL.revokeObjectURL(this._imageObjectUrl); this._imageObjectUrl = null; }
      this._artFile.value   = '';
      this._imageFile.value = '';
    }

    _refreshPreviews() {
      const av = Number(this._versionSel.value || 1);
      const bust = Date.now();
      const bustSep = (path) => path.includes('?') ? '&' : '?';
      const artPath   = cardArtPath(this._card.id, this._card.name, av, this._card.cardArtUpdatedAt);
      const imagePath = cardImagePath(this._card.id, this._card.name, av, this._card.cardImageUpdatedAt);
      this._artPreview.innerHTML   = `<img src="${artPath}${bustSep(artPath)}t=${bust}" alt=""
                                onerror="this.parentElement.innerHTML='<span class=\\'rk-cem-no-img\\'>No image</span>';" />`;
      this._imagePreview.innerHTML = `<img src="${imagePath}${bustSep(imagePath)}t=${bust}" alt=""
                                onerror="this.parentElement.innerHTML='<span class=\\'rk-cem-no-img\\'>No image</span>';" />`;
    }

    _previewLocalFile(paneEl, file) {
      const url = URL.createObjectURL(file);
      paneEl.innerHTML = `<img src="${url}" alt="" />`;
      return url;
    }

    async open(card, initialVersion) {
      if (!card) return;
      this._card = card;
      await this._ensureKeywords();

      this._title.textContent = `Edit Card #${card.id}`;
      this._errorEl.style.display = 'none';
      this._nameInput.value  = card.name;
      this._powerInput.value = card.power;
      this._typeSel.value    = String(card.cardTypeId);

      this._kw1.innerHTML = this._keywordOptionsHtml(card.keywordId);
      this._kw2.innerHTML = this._keywordOptionsHtml(card.keyword2Id);
      this._kw3.innerHTML = this._keywordOptionsHtml(card.keyword3Id);
      this._kw1.value = card.keywordId  ? String(card.keywordId)  : '';
      this._kw2.value = card.keyword2Id ? String(card.keyword2Id) : '';
      this._kw3.value = card.keyword3Id ? String(card.keyword3Id) : '';

      const maxVersion = (card.altArts || 0) + 1;
      this._versionSel.innerHTML = Array.from({ length: maxVersion }, (_, i) => i + 1)
        .map(v => `<option value="${v}">${v}</option>`).join('');
      this._versionField.style.display = maxVersion > 1 ? 'block' : 'none';
      // Opens on whichever art version the host page had on screen (e.g. the gallery's
      // middle-click-cycled version) rather than always defaulting back to the base art.
      this._versionSel.value = String(Math.min(Math.max(initialVersion || 1, 1), maxVersion));

      this._clearLocalPreviews();
      this._refreshPreviews();
      this._backdrop.classList.remove('hidden');
    }

    close() {
      const card = this._card;
      this._backdrop.classList.add('hidden');
      this._clearLocalPreviews();
      this._card = null;
      // Lets a host page clear whatever selection/focus state led here (e.g. card-gallery's
      // single-card selection) whether the modal was saved, cancelled, or Escaped out of.
      if (card) this.dispatchEvent(new CustomEvent('rk-modal-closed', { bubbles: true, detail: { card } }));
    }

    _showToast(msg, type) {
      this._toastEl.textContent = msg;
      this._toastEl.className   = 'rk-cem-toast' + (type ? ' ' + type : '');
      this._toastEl.style.display = 'block';
      clearTimeout(this._toastTimer);
      this._toastTimer = setTimeout(() => { this._toastEl.style.display = 'none'; }, type === 'err' ? 8000 : 3500);
    }

    // Uploads one file for the currently-editing card. Returns an error message string
    // on failure, or null on success — always run after the metadata save, since the
    // upload writes under the card's *current* name/id and a name change must have
    // already been applied server-side.
    async _uploadImage(kind, file) {
      const form = new FormData();
      form.append('kind', kind);
      form.append('id', this._card.id);
      form.append('artVersion', this._versionSel.value || '1');
      form.append('file', file);
      try {
        const res  = await fetch(CARDS_URL, { method: 'POST', headers: this._authHeaders(false), body: form });
        const data = await res.json();
        if (!res.ok) return data.error || `Failed to upload ${kind}.`;
        return null;
      } catch (e) {
        return `Network error uploading ${kind}: ${e.message}`;
      }
    }

    async _save() {
      const errEl = this._errorEl;
      errEl.style.display = 'none';

      const id    = this._card.id;
      const name  = this._nameInput.value.trim();
      const power = parseInt(this._powerInput.value, 10);
      if (!name) { errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; return; }
      if (isNaN(power) || power < 0) { errEl.textContent = 'Power is required.'; errEl.style.display = 'block'; return; }

      const body = {
        action:     'update',
        id,
        name,
        power,
        cardTypeId: parseInt(this._typeSel.value, 10),
        keywordId:  this._kw1.value || null,
        keyword2Id: this._kw2.value || null,
        keyword3Id: this._kw3.value || null,
      };
      const artFile   = this._artFile.files[0]   || null;
      const imageFile = this._imageFile.files[0] || null;

      this._saveBtn.disabled = true;
      try {
        const res  = await fetch(CARDS_URL, { method: 'POST', headers: this._authHeaders(), body: JSON.stringify(body) });
        const data = await res.json();
        if (!res.ok) { errEl.textContent = data.error || 'Save failed.'; errEl.style.display = 'block'; return; }

        let card = data.card;
        const uploadErrors = [];
        // The upload endpoint only returns {uploaded:true}, not the updated card, so bump
        // the *Updated At timestamps locally on success — otherwise the grid thumbnail's
        // ?v= cache-buster stays stale and the browser keeps showing the old cached image.
        let artUpdated = false, imageUpdated = false;
        if (artFile) {
          const err = await this._uploadImage('art', artFile);
          if (err) uploadErrors.push(err);
          else { card = { ...card, cardArtUpdatedAt: Date.now() }; artUpdated = true; }
        }
        if (imageFile) {
          const err = await this._uploadImage('image', imageFile);
          if (err) uploadErrors.push(err);
          else { card = { ...card, cardImageUpdatedAt: Date.now() }; imageUpdated = true; }
        }

        this.close();
        // The card itself can save successfully even if renaming its image files on disk, or
        // uploading a new one, failed (e.g. a file-permission problem) — surface that instead
        // of a plain success toast.
        const messages = [data.error, ...uploadErrors].filter(Boolean);
        if (messages.length) this._showToast(messages.join(' '), 'err');
        else this._showToast('Card updated!', 'ok');

        this.dispatchEvent(new CustomEvent('rk-card-saved', {
          bubbles: true,
          detail: { card, artUpdated, imageUpdated, error: messages.join(' ') || null },
        }));
      } catch (e) {
        errEl.textContent = 'Network error: ' + e.message;
        errEl.style.display = 'block';
      } finally {
        this._saveBtn.disabled = false;
      }
    }
  }

  if (!customElements.get('rk-card-edit-modal')) {
    customElements.define('rk-card-edit-modal', RkCardEditModal);
  }
})();

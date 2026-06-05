class UniversallySwitcher extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._open = false;
    this._onDocClick = this._onDocClick.bind(this);
    this._onKeyDown = this._onKeyDown.bind(this);
  }

  connectedCallback() {
    const raw = this.getAttribute('data-config');
    if (!raw) return;

    try {
      this._config = JSON.parse(raw);
    } catch {
      return;
    }

    this.setAttribute('translate', 'no');
    this._render();
    this._bind();
  }

  disconnectedCallback() {
    document.removeEventListener('click', this._onDocClick, true);
    document.removeEventListener('keydown', this._onKeyDown);
  }

  _render() {
    const { languages, showNames, showFlags, flagStyle, fixed, position } = this._config;
    if (!languages || !languages.length) return;

    const current = languages.find(l => l.isCurrent);
    if (!current) return;

    const others = languages.filter(l => !l.isCurrent && !l.isDisabled);

    const flagRadius = flagStyle === 'square' ? '0' : '50%';

    let hostStyles = '';
    if (fixed) {
      const posMap = {
        bottom_right: 'bottom:20px;right:20px',
        bottom_left: 'bottom:20px;left:20px',
        top_right: 'top:20px;right:20px',
        top_left: 'top:20px;left:20px',
      };
      const pos = posMap[position] || posMap.bottom_right;
      hostStyles = `position:fixed;${pos};z-index:999999;`;
    }

    const flagHtml = (lang) => {
      if (!showFlags || !lang.flagUrl) return '';
      return `<img class="flag" src="${lang.flagUrl}" alt="" />`;
    };

    const nameHtml = (lang) => {
      if (!showNames) return '';
      return `<span class="name">${lang.originalName || lang.name || ''}</span>`;
    };

    const codeHtml = (lang) => {
      const code = (lang.region || lang.variant || '').split('-')[0];
      return `<span class="code">${code.toUpperCase()}</span>`;
    };

    const labelHtml = (lang) => {
      if (showFlags || showNames) return `${flagHtml(lang)}${nameHtml(lang)}`;
      return codeHtml(lang);
    };

    const itemsHtml = others.map(lang => {
      const hreflang = lang.region || lang.variant || '';
      // data-lang carries the urlPrefix for target languages; an empty value
      // marks the source language so the click handler can clear the cookie.
      const dataLang = lang.isSource ? '' : (lang.urlPrefix || '');
      // The source link carries an explicit opt-out marker so the server clears
      // the stored preference even when the click handler doesn't run (new tab,
      // prefetch, cookie path/domain mismatch). PHP strips the marker and
      // redirects to the clean URL.
      const href = lang.isSource
        ? `${lang.url}${lang.url.includes('?') ? '&' : '?'}universally_switch=source`
        : lang.url;
      return `<li><a href="${href}" hreflang="${hreflang}" lang="${hreflang}" data-lang="${dataLang}">${labelHtml(lang)}</a></li>`;
    }).join('');

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: inline-block;
          font-family: inherit;
          font-size: var(--universally-font-size, 14px);
          line-height: 1.4;
          ${hostStyles}
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        .trigger {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: var(--universally-trigger-padding, 8px 12px);
          background: var(--universally-trigger-bg, #fff);
          border: 1px solid var(--universally-trigger-border, #ddd);
          border-radius: var(--universally-trigger-radius, 6px);
          cursor: pointer;
          font: inherit;
          color: var(--universally-trigger-text, #1a1a1a);
          transition: border-color 0.15s;
          user-select: none;
        }
        .trigger:hover { border-color: var(--universally-trigger-border-hover, #999); }
        .trigger:focus-visible {
          outline: 2px solid var(--universally-focus, #0073aa);
          outline-offset: 2px;
        }

        .arrow {
          display: inline-block;
          width: 0;
          height: 0;
          border-left: 4px solid transparent;
          border-right: 4px solid transparent;
          border-top: 5px solid currentColor;
          transition: transform 0.2s;
        }
        :host([open]) .arrow { transform: rotate(180deg); }

        .dropdown {
          display: none;
          position: absolute;
          left: 0;
          margin: 4px 0;
          padding: 4px;
          list-style: none;
          background: var(--universally-dropdown-bg, #fff);
          border: 1px solid var(--universally-dropdown-border, #ddd);
          border-radius: var(--universally-dropdown-radius, 6px);
          box-shadow: var(--universally-dropdown-shadow, 0 4px 12px rgba(0,0,0,0.1));
          min-width: 100%;
          max-height: 200px;
          overflow-y: auto;
          z-index: 10;
        }
        :host([open]) .dropdown { display: block; }
        .dropdown.above { bottom: 100%; top: auto; }
        .dropdown.below { top: 100%; bottom: auto; }

        .dropdown li { white-space: nowrap; }
        .dropdown a {
          display: flex;
          align-items: center;
          gap: 8px;
          padding: 8px 10px;
          color: var(--universally-dropdown-text, #1a1a1a);
          text-decoration: none;
          border-radius: 4px;
          transition: background 0.15s;
          font: inherit;
        }
        .dropdown a:hover { background: var(--universally-dropdown-hover-bg, #f5f5f5); }
        .dropdown a:focus-visible {
          outline: 2px solid var(--universally-focus, #0073aa);
          outline-offset: -2px;
        }

        .flag {
          width: 20px;${flagStyle === 'square' ? '' : '\n          height: 20px;'}
          object-fit: cover;
          border-radius: ${flagRadius};
          flex-shrink: 0;
        }
        .name { color: inherit; }
        .code { color: inherit; font-weight: 500; letter-spacing: 0.5px; }

        .wrap { position: relative; display: inline-block; }
      </style>
      <div class="wrap">
        <button class="trigger" type="button" aria-expanded="false" aria-haspopup="listbox">
          ${labelHtml(current)}${showFlags && !showNames ? '' : '<span class="arrow" aria-hidden="true"></span>'}
        </button>
        <ul class="dropdown below" role="listbox">${itemsHtml}</ul>
      </div>
    `;
  }

  _bind() {
    this._trigger = this.shadowRoot.querySelector('.trigger');
    this._dropdown = this.shadowRoot.querySelector('.dropdown');
    if (!this._trigger || !this._dropdown) return;

    this._trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      this._toggle();
    });

    this._dropdown.querySelectorAll('a[data-lang]').forEach((a) => {
      a.addEventListener('click', () => {
        this._persistLanguageChoice(a.getAttribute('data-lang') || '');
      });
    });

    document.addEventListener('click', this._onDocClick, true);
    document.addEventListener('keydown', this._onKeyDown);
  }

  _persistLanguageChoice(urlPrefix) {
    // Empty urlPrefix means the source language: clear the cookie for instant
    // local effect. The authoritative clear happens server-side via the
    // ?universally_switch=source marker on the source link, which uses the
    // exact cookie path/domain attributes the cookie was set with.
    const maxAge = urlPrefix ? 60 * 60 * 24 * 30 : 0;
    const value = urlPrefix ? encodeURIComponent(urlPrefix) : '';
    const secure = location.protocol === 'https:' ? ';secure' : '';
    document.cookie = `universally_lang=${value};path=/;max-age=${maxAge};samesite=Lax${secure}`;
  }

  _toggle() {
    this._open = !this._open;
    if (this._open) {
      this.setAttribute('open', '');
      this._trigger.setAttribute('aria-expanded', 'true');
      this._positionDropdown();
    } else {
      this._close();
    }
  }

  _close() {
    this._open = false;
    this.removeAttribute('open');
    this._trigger.setAttribute('aria-expanded', 'false');
  }

  _positionDropdown() {
    const dd = this._dropdown;
    const pad = 20;

    // Reset to default position for measurement
    dd.classList.remove('above');
    dd.classList.add('below');
    dd.style.left = '0';

    const triggerRect = this._trigger.getBoundingClientRect();
    const ddRect = dd.getBoundingClientRect();
    const vh = window.innerHeight;
    const vw = window.innerWidth;

    // Vertical: prefer below, flip above if not enough space
    const spaceBelow = vh - triggerRect.bottom;
    const spaceAbove = triggerRect.top;
    if (ddRect.height + pad > spaceBelow && spaceAbove > spaceBelow) {
      dd.classList.remove('below');
      dd.classList.add('above');
    }

    // Horizontal: shift if overflowing viewport edges
    if (ddRect.right > vw - pad) {
      dd.style.left = `${-(ddRect.right - vw + pad)}px`;
    } else if (ddRect.left < pad) {
      dd.style.left = `${pad - ddRect.left}px`;
    }
  }

  _onDocClick(e) {
    if (this._open && !e.composedPath().includes(this)) {
      this._close();
    }
  }

  _onKeyDown(e) {
    if (e.key === 'Escape' && this._open) {
      this._close();
      this._trigger.focus();
    }
  }
}

if (!customElements.get('universally-switcher')) {
  customElements.define('universally-switcher', UniversallySwitcher);
}

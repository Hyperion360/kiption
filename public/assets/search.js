'use strict';
(function () {
  window.Kip.init(function () {
    document.querySelectorAll('form[data-js-module~="search"]').forEach(function (form) {
      var input = form.querySelector('[name="q"]');
      var results = document.querySelector('[data-search-results]');
      var list = form.querySelector('[data-search-suggestions]');
      var status = form.querySelector('[data-search-status]');
      if (!input || !results || !list || !status || !window.fetch || !window.AbortController) return;
      var timer, controller, serial = 0, composing = false, active = -1, options = [];
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-controls', list.id);
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('autocomplete', 'off');
      list.setAttribute('role', 'listbox');
      list.setAttribute('aria-label', form.getAttribute('data-search-suggestions-label'));
      function close() {
        active = -1;
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
      }
      function cancel() {
        clearTimeout(timer);
        serial += 1;
        if (controller) controller.abort();
        results.removeAttribute('aria-busy');
        status.removeAttribute('data-error');
        status.textContent = '';
      }
      function query() {
        var params = new URLSearchParams(new FormData(form));
        params.delete('page');
        params.delete('live');
        return params.toString();
      }
      function select(n) {
        if (!options.length) return;
        active = (n + options.length) % options.length;
        options.forEach(function (el, i) { el.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-activedescendant', options[active].id);
      }
      var SAFE = ['/story/view/', '/user/view/', '/search'];
      function safeUrl(raw) {
        var url;
        try { url = new URL(raw, location.origin); } catch (_) { return null; }
        if (url.origin !== location.origin) return null;
        if (!SAFE.some(function (p) { return url.pathname.indexOf(p) === 0; })) return null;
        return url;
      }
      function go(el) {
        var url = safeUrl(el.getAttribute('data-url'));
        if (url) location.assign(url.href);
      }
      function text(tag, cls, value) {
        var el = document.createElement(tag);
        if (cls) el.setAttribute('class', cls);
        el.textContent = value;
        return el;
      }
      // Bold the part of a label the typed words match (plain text nodes only).
      function marked(label, typed) {
        var wrap = document.createElement('span');
        wrap.setAttribute('class', 'sg-text');
        var needle = typed.trim().toLowerCase(), at = needle ? label.toLowerCase().indexOf(needle) : -1;
        if (at < 0) { var first = needle.split(/\s+/)[0]; at = first ? label.toLowerCase().indexOf(first) : -1; needle = first; }
        if (at < 0) { wrap.appendChild(text('span', '', label)); return wrap; }
        if (at > 0) wrap.appendChild(text('span', '', label.slice(0, at)));
        wrap.appendChild(text('b', 'sg-hit', label.slice(at, at + needle.length)));
        if (at + needle.length < label.length) wrap.appendChild(text('span', '', label.slice(at + needle.length)));
        return wrap;
      }
      function suggestions(rows) {
        close();
        list.replaceChildren();
        options = [];
        var typed = input.value, seen = {}, n = 0;
        rows.slice(0, 12).forEach(function (row) {
          if (!row || typeof row.label !== 'string' || typeof row.url !== 'string') return;
          var url = safeUrl(row.url);
          if (!url) return;
          var group = ['stories', 'authors', 'tags'].indexOf(row.group) >= 0 ? row.group : 'stories';
          if (!seen[group]) {
            seen[group] = true;
            var head = text('li', 'sg-head', form.getAttribute('data-group-' + group) || '');
            head.setAttribute('role', 'presentation');
            head.setAttribute('aria-hidden', 'true');
            list.appendChild(head);
          }
          var el = document.createElement('li');
          el.id = list.id + '-' + (n++);
          el.setAttribute('role', 'option');
          el.setAttribute('aria-selected', 'false');
          el.setAttribute('data-url', url.pathname + url.search);
          el.setAttribute('class', 'sg-row');
          el.appendChild(marked(row.label, typed));
          if (typeof row.sub === 'string' && row.sub !== '') el.appendChild(text('span', 'sg-sub', row.sub));
          el.addEventListener('mousedown', function (e) { e.preventDefault(); });
          el.addEventListener('click', function () { go(el); });
          list.appendChild(el);
          options.push(el);
        });
        if (options.length) {
          var foot = document.createElement('li');
          foot.setAttribute('class', 'sg-foot');
          foot.setAttribute('role', 'presentation');
          foot.setAttribute('aria-hidden', 'true');
          foot.appendChild(text('span', '', (form.getAttribute('data-kbd-enter') || '').replace('{q}', typed.trim())));
          foot.appendChild(text('span', '', form.getAttribute('data-kbd-choose') || ''));
          foot.appendChild(text('span', '', form.getAttribute('data-kbd-close') || ''));
          list.appendChild(foot);
        }
        if (options.length && document.activeElement === input) {
          list.hidden = false;
          input.setAttribute('aria-expanded', 'true');
        }
      }
      // Recent searches stay on this device (localStorage); the server never sees them.
      var recentBox = form.querySelector('[data-recent]'), RECENT_KEY = 'kip.recent-searches';
      function recentRead() {
        try {
          var v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
          return Array.isArray(v) ? v.filter(function (x) { return typeof x === 'string' && x !== ''; }).slice(0, 6) : [];
        } catch (_) { return []; }
      }
      function recentWrite(items) { try { localStorage.setItem(RECENT_KEY, JSON.stringify(items.slice(0, 6))); } catch (_) {} }
      function recentRemember(q) {
        q = q.trim();
        if (!q) return;
        recentWrite([q].concat(recentRead().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); })));
      }
      function recentRender() {
        if (!recentBox) return;
        var listEl = recentBox.querySelector('[data-recent-list]'), items = recentRead();
        if (!listEl) return;
        listEl.replaceChildren();
        items.forEach(function (q) {
          var li = document.createElement('li');
          li.setAttribute('class', 'chip');
          var a = text('a', '', q);
          a.setAttribute('href', '/search?q=' + encodeURIComponent(q));
          var x = text('button', 'recent-x', recentBox.getAttribute('data-glyph-x') || 'x');
          x.setAttribute('type', 'button');
          x.setAttribute('aria-label', (recentBox.getAttribute('data-remove') || '').replace('{q}', q));
          x.addEventListener('click', function () {
            recentWrite(recentRead().filter(function (i) { return i !== q; }));
            recentRender();
          });
          li.appendChild(a);
          li.appendChild(x);
          listEl.appendChild(li);
        });
        recentBox.hidden = !(items.length && input.value.trim() === '');
      }
      recentRemember(input.value);
      recentRender();
      function updateCounts(counts) {
        var language = form.querySelector('select[name="language"]');
        if (language) Object.keys(counts).forEach(function (key) {
          if (key.indexOf('language:') !== 0) return;
          var value = key.slice(9);
          if (Array.from(language.options).some(function (o) { return o.value === value; })) return;
          var option = document.createElement('option');
          option.value = value;
          option.setAttribute('data-search-count', key);
          option.setAttribute('data-count-label', value);
          language.appendChild(option);
        });
        form.querySelectorAll('[data-search-count]').forEach(function (el) {
          var n = counts[el.getAttribute('data-search-count')];
          if (!Number.isInteger(n) || n < 0) n = 0;
          var label = el.getAttribute('data-count-label');
          el.textContent = label === null ? String(n) : label + ' (' + n + ')';
        });
        if (form.dispatchEvent && window.CustomEvent) form.dispatchEvent(new window.CustomEvent('kip:counts'));
      }
      function updateApply(total) {
        var apply = form.querySelector('[data-show-many]');
        if (!Number.isInteger(total) || total < 0) return;
        var shown = form.querySelector('[data-rail-total]');
        if (shown) {
          shown.hidden = false;
          shown.textContent = total === 1 ? shown.getAttribute('data-one') : shown.getAttribute('data-many').replace('{count}', String(total));
        }
        if (!apply) return;
        apply.textContent = total === 1 ? apply.getAttribute('data-show-one') : apply.getAttribute('data-show-many').replace('{count}', String(total));
      }
      function schedule(e) {
        // The unnamed tag-picker search only filters local controls.
        if (e && e.target && !e.target.name) return;
        if (recentBox) recentRender();
        cancel();
        close();
        options = [];
        list.replaceChildren();
        if (composing) return;
        var length = Array.from(input.value.trim()).length;
        if (length === 1) { status.textContent = ''; return; }
        var wanted = query(), id = serial;
        timer = setTimeout(function () {
          controller = new AbortController();
          results.setAttribute('aria-busy', 'true');
          status.textContent = form.getAttribute('data-search-loading');
          fetch(form.action + '?' + wanted + '&live=1', {
            credentials: 'same-origin', signal: controller.signal,
            headers: {'Accept': 'application/json'}
          }).then(function (r) {
            if (!r.ok || !(r.headers.get('Content-Type') || '').includes('application/json')) throw new Error('response');
            return r.json();
          }).then(function (data) {
            if (id !== serial || wanted !== query() || composing) return;
            if (typeof data.html !== 'string' || !Array.isArray(data.suggestions) ||
                typeof data.message !== 'string' || !data.counts || typeof data.counts !== 'object') throw new Error('shape');
            // This fragment is rendered and escaped by our same-origin PHP view.
            results.innerHTML = data.html;
            suggestions(data.suggestions);
            updateCounts(data.counts);
            updateApply(data.total);
            status.textContent = data.message;
          }).catch(function (error) {
            if (id !== serial || error.name === 'AbortError') return;
            close();
            status.setAttribute('data-error', '');
            status.textContent = form.getAttribute('data-search-error');
          }).finally(function () {
            if (id === serial) results.removeAttribute('aria-busy');
          });
        }, 250);
      }
      input.addEventListener('compositionstart', function () { composing = true; cancel(); close(); });
      input.addEventListener('compositionend', function () { composing = false; schedule(); });
      form.addEventListener('input', schedule);
      form.addEventListener('change', schedule);
      form.addEventListener('submit', function () { recentRemember(input.value); cancel(); });
      input.addEventListener('keydown', function (e) {
        if (composing || e.isComposing) return;
        if (e.key === 'Escape') {
          if (!list.hidden) e.preventDefault();
          cancel(); close(); return;
        }
        if (e.key === 'ArrowDown' && options.length) { e.preventDefault(); select(active + 1); }
        else if (e.key === 'ArrowUp' && options.length) { e.preventDefault(); select(active < 0 ? options.length - 1 : active - 1); }
        else if (e.key === 'Enter' && active >= 0 && !list.hidden) { e.preventDefault(); go(options[active]); }
      });
      input.addEventListener('blur', close);
      var clear = form.querySelector('[data-search-clear]');
      if (clear) clear.addEventListener('click', function (e) {
        e.preventDefault();
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
      });
      window.addEventListener('pagehide', cancel);
    });
  });
})();

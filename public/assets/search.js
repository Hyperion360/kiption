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
      function go(el) {
        var url = new URL(el.getAttribute('data-url'), location.origin);
        if (url.origin === location.origin && url.pathname.indexOf('/story/view/') === 0) location.assign(url.href);
      }
      function suggestions(rows) {
        close();
        list.replaceChildren();
        options = [];
        rows.slice(0, 5).forEach(function (row, i) {
          if (!row || typeof row.label !== 'string' || typeof row.url !== 'string') return;
          var url;
          try { url = new URL(row.url, location.origin); } catch (_) { return; }
          if (url.origin !== location.origin || url.pathname.indexOf('/story/view/') !== 0) return;
          var el = document.createElement('li');
          el.id = list.id + '-' + i;
          el.setAttribute('role', 'option');
          el.setAttribute('aria-selected', 'false');
          el.setAttribute('data-url', url.pathname + url.search);
          el.textContent = row.label;
          el.addEventListener('mousedown', function (e) { e.preventDefault(); });
          el.addEventListener('click', function () { go(el); });
          list.appendChild(el);
          options.push(el);
        });
        if (options.length && document.activeElement === input) {
          list.hidden = false;
          input.setAttribute('aria-expanded', 'true');
        }
      }
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
        if (!apply || !Number.isInteger(total) || total < 0) return;
        apply.textContent = total === 1 ? apply.getAttribute('data-show-one') : apply.getAttribute('data-show-many').replace('{count}', String(total));
      }
      function schedule(e) {
        // The unnamed tag-picker search only filters local controls.
        if (e && e.target && !e.target.name) return;
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
      form.addEventListener('submit', cancel);
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
      window.addEventListener('pagehide', cancel);
    });
  });
})();

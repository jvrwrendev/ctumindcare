/* ==========================================================================
   CTU MindCare — Design System JS
   --------------------------------------------------------------------------
   Dark mode, Lucide icons, Motion One animations (with CSS fallback),
   enhanced tables (search / filter / pagination), form validation styles,
   flash auto-dismiss and misc helpers.
   ========================================================================== */
(function () {
  'use strict';

  var STORAGE_KEY = 'mc-theme';
  var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');

  /* ── Theme (dark mode) ─────────────────────────────────────────────── */
  function applyTheme(theme, persist) {
    document.documentElement.setAttribute('data-theme', theme);
    if (persist !== false) {
      try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
    }
    updateThemeButtons();
  }

  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'light';
  }

  function updateThemeButtons() {
    var dark = currentTheme() === 'dark';
    document.querySelectorAll('.mc-theme-toggle').forEach(function (btn) {
      btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
    });
  }

  function initTheme() {
    var stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    if (stored === 'dark' || stored === 'light') {
      applyTheme(stored, false);
    } else if (prefersDark && prefersDark.matches) {
      applyTheme('dark', false);
    } else {
      applyTheme('light', false);
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.mc-theme-toggle') : null;
    if (!btn) return;
    e.preventDefault();
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
  });

  /* ── Lucide icons ──────────────────────────────────────────────────── */
  function initIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function' && window.lucide.icons) {
      try { window.lucide.createIcons({ icons: window.lucide.icons }); } catch (e) {}
    }
  }
  document.addEventListener('DOMContentLoaded', initIcons);

  /* ── Reveal animations (Motion One first, CSS fallback) ────────────── */
  function initReveals() {
    var items = document.querySelectorAll('.mc-reveal:not(.mc-in)');
    if (!items.length) return;

    if (!('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('mc-in'); });
      return;
    }

    if (window.Motion) {
      var visible = [];
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var el = entry.target;
            var delay = parseInt(el.getAttribute('data-mc-delay') || '0', 10);
            visible.push({ el: el, delay: delay * 60 });
            el.classList.add('mc-in');
            io.unobserve(el);
          }
        });
      }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });
      items.forEach(function (el) { io.observe(el); });

      window.addEventListener('mc-flush-reveals', function () {
        visible.forEach(function (v) {
          if (window.Motion && window.Motion.animate) {
            window.Motion.animate(v.el, { opacity: [0, 1], y: [14, 0] }, { duration: 0.5, delay: v.delay / 1000, easing: 'ease-out' });
          }
        });
        visible = [];
      });
      window.addEventListener('load', function () { setTimeout(function () { window.dispatchEvent(new Event('mc-flush-reveals')); }, 60); });
      return;
    }

    /* CSS fallback (no Motion One) */
    var io2 = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('mc-in');
          io2.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08 });
    items.forEach(function (el) { io2.observe(el); });
  }

  /* ── Flash auto-dismiss ────────────────────────────────────────────── */
  function initFlash() {
    document.querySelectorAll('.flash[data-auto-dismiss]').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity .4s ease, transform .4s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-6px)';
        setTimeout(function () { el.remove(); }, 400);
      }, 4200);
    });
  }

  /* ── Enhanced tables: search + filter + pagination ─────────────────── */
  /* Markup:  <div class="card" data-mc-table>                    */
  /*            <div class="mc-toolbar">                           */
  /*              search input + filter pills                     */
  /*            </div>                                             */
  /*            <div class="table-wrap"><table>…</table></div>     */
  /*          </div>                                               */
  function initTables() {
    document.querySelectorAll('[data-mc-table]').forEach(function (root) {
      if (root.dataset.mcBound) return;
      root.dataset.mcBound = '1';

      var table = root.querySelector('table');
      if (!table) return;
      var toolbar = root.querySelector('.mc-toolbar');

      var perPage = parseInt(root.getAttribute('data-mc-page-size') || '10', 10);
      var page = 0;
      var query = '';
      var activeFilter = '';

      var searchInput = root.querySelector('[data-mc-search]');
      var filterPills = root.querySelectorAll('[data-mc-filter]');
      var searchableCols = table.querySelectorAll('thead th[data-mc-searchable]');
      var filterCols = table.querySelectorAll('thead th[data-mc-filterable]');

      function rows() {
        return Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (tr) {
          var text = '';
          if (searchableCols.length) {
            searchableCols.forEach(function (th) {
              var i = Array.prototype.indexOf.call(th.parentNode.children, th);
              var td = tr.children[i];
              if (td) text += ' ' + td.textContent;
            });
          } else {
            text = tr.textContent;
          }
          if (query && text.toLowerCase().indexOf(query.toLowerCase()) === -1) return false;
          if (activeFilter) {
            var match = false;
            filterCols.forEach(function (th) {
              var i = Array.prototype.indexOf.call(th.parentNode.children, th);
              var td = tr.children[i];
              if (td && td.textContent.trim().toLowerCase() === activeFilter.toLowerCase()) match = true;
            });
            if (!match) return false;
          }
          return true;
        });
      }

      function render() {
        var all = rows();
        var totalPages = Math.max(1, Math.ceil(all.length / perPage));
        if (page >= totalPages) page = totalPages - 1;
        var start = page * perPage;

        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr, idx) {
          var visible = all.indexOf(tr);
          var show = visible !== -1 && idx >= start && idx < start + perPage;
          tr.style.display = show ? '' : 'none';
        });

        var counts = root.querySelectorAll('[data-mc-count]');
        counts.forEach(function (el) {
          el.textContent = all.length;
        });

        var meta = root.querySelector('[data-mc-pageinfo]');
        if (meta) {
          meta.textContent = all.length === 0
            ? 'No results'
            : 'Showing ' + (start + 1) + '\u2013' + Math.min(start + perPage, all.length) + ' of ' + all.length;
        }

        var nav = root.querySelector('.mc-pagination');
        if (nav) {
          nav.innerHTML = '';
          if (totalPages > 1) {
            var prev = document.createElement('a');
            prev.className = 'page-link' + (page === 0 ? ' disabled' : '');
            prev.innerHTML = '\u2039';
            prev.href = '#';
            prev.addEventListener('click', function (e) { e.preventDefault(); if (page > 0) { page--; render(); } });
            nav.appendChild(prev);
            for (var p = 0; p < totalPages; p++) {
              if (totalPages > 9 && p > 1 && p < totalPages - 2 && Math.abs(p - page) > 1) {
                if (Math.abs(p - 1 - page) > 1) {
                  var dots = document.createElement('span');
                  dots.className = 'page-link disabled';
                  dots.textContent = '\u2026';
                  nav.appendChild(dots);
                }
                p = Math.max(p, totalPages - 3);
                continue;
              }
              var link = document.createElement('a');
              link.className = 'page-link' + (p === page ? ' active' : '');
              link.textContent = p + 1;
              link.href = '#';
              (function (pg) {
                link.addEventListener('click', function (e) { e.preventDefault(); page = pg; render(); });
              })(p);
              nav.appendChild(link);
            }
            var next = document.createElement('a');
            next.className = 'page-link' + (page === totalPages - 1 ? ' disabled' : '');
            next.innerHTML = '\u203a';
            next.href = '#';
            next.addEventListener('click', function (e) { e.preventDefault(); if (page < totalPages - 1) { page++; render(); } });
            nav.appendChild(next);
          }
        }
      }

      if (searchInput) {
        searchInput.addEventListener('input', function () { query = this.value; page = 0; render(); });
      }
      filterPills.forEach(function (pill) {
        pill.addEventListener('click', function (e) {
          e.preventDefault();
          var value = pill.getAttribute('data-mc-filter');
          activeFilter = activeFilter === value ? '' : value;
          filterPills.forEach(function (p) { p.classList.toggle('active', p === pill && !!activeFilter); });
          page = 0;
          render();
        });
      });

      /* Pagination container */
      if (!root.querySelector('.mc-pagination') && table.querySelectorAll('tbody tr').length > perPage) {
        var nav = document.createElement('div');
        nav.className = 'mc-pagination pagination';
        root.appendChild(nav);
      }

      render();
    });
  }

  /* ── Form validation styling ───────────────────────────────────────── */
  function initForms() {
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
      var fields = form.querySelectorAll('input, select, textarea');
      fields.forEach(function (field) {
        field.addEventListener('blur', function () { validateField(field); });
        field.addEventListener('input', function () { validateField(field, true); });
      });
      form.addEventListener('submit', function (e) {
        var firstInvalid = null;
        fields.forEach(function (field) {
          var valid = validateField(field);
          if (!valid && !firstInvalid) firstInvalid = field;
        });
        if (firstInvalid) {
          e.preventDefault();
          firstInvalid.focus();
          showFieldError(firstInvalid, 'Please fill in this field correctly.');
        }
      });
    });

    function validateField(field, silent) {
      var valid = field.checkValidity();
      var err = field.closest('.form-group') ? field.closest('.form-group').querySelector('.field-error') : null;
      if (!field.value && !field.required) { clearError(field, err); return true; }
      if (valid) {
        clearError(field, err);
      } else if (!silent) {
        showFieldError(field, field.validationMessage || 'Please fill in this field correctly.');
      } else {
        field.classList.remove('input-error', 'is-invalid');
        if (err) err.remove();
      }
      return valid;
    }

    function showFieldError(field, msg) {
      field.classList.add('input-error', 'is-invalid');
      var group = field.closest('.form-group');
      if (!group) return;
      var err = group.querySelector('.field-error');
      if (!err) {
        err = document.createElement('span');
        err.className = 'field-error';
        err.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>';
        group.appendChild(err);
      }
      var text = document.createElement('span');
      text.textContent = msg;
      err.innerHTML = err.innerHTML;
      err.appendChild(text);
    }

    function clearError(field, err) {
      field.classList.remove('input-error', 'is-invalid');
      if (err) err.remove();
    }
  }

  /* ── Chart palette helper ──────────────────────────────────────────── */
  window.mcCssVar = function (name) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return v ? v.trim() : '';
  };
  window.mcChartColors = function () {
    return {
      blue: window.mcCssVar('--chart-blue'),
      teal: window.mcCssVar('--chart-teal'),
      amber: window.mcCssVar('--chart-amber'),
      red: window.mcCssVar('--chart-red'),
      purple: window.mcCssVar('--chart-purple'),
      gray: window.mcCssVar('--chart-gray'),
      grid: window.mcCssVar('--border'),
      ticks: window.mcCssVar('--text-3')
    };
  };

  /* ── Init ──────────────────────────────────────────────────────────── */
  function boot() {
    initTheme();
    initIcons();
    initReveals();
    initFlash();
    initTables();
    initForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/* ==========================================================================
   CTU MindCare — Shared Sidebar behaviour
   Self-initialising drawer: hamburger toggle, overlay, close on nav click,
   Escape, and viewport resize. Safe no-op when elements are absent.
   ========================================================================== */
(function () {
  'use strict';

  function init() {
    var btn   = document.querySelector('.mc-hamburger');
    var sb    = document.getElementById('mcSidebar');
    var ov    = document.getElementById('mcOverlay');
    if (!btn || !sb) return;

    var openClass  = 'mc-open';
    var overlayOn  = ov ? 'active' : null;

    function isMobile() { return window.innerWidth <= 900; }

    function openDrawer() {
      sb.classList.add(openClass);
      if (ov) ov.classList.add(overlayOn);
      btn.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
      sb.classList.remove(openClass);
      if (ov) ov.classList.remove(overlayOn);
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      sb.classList.contains(openClass) ? closeDrawer() : openDrawer();
    });

    if (ov) {
      ov.addEventListener('click', closeDrawer);
    }

    Array.prototype.forEach.call(sb.querySelectorAll('a'), function (link) {
      link.addEventListener('click', function () {
        if (isMobile()) closeDrawer();
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sb.classList.contains(openClass)) closeDrawer();
    });

    window.addEventListener('resize', function () {
      if (!isMobile()) closeDrawer();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/* ============================================================
   CTU MindCare — AI Assistant widget JS (works on any page)
   Supports: greeting, chat history, quick-reply chips, actions
   ============================================================ */
(function () {
  'use strict';

  var root    = document.querySelector('.cb-root');
  if (!root) return;

  var API_URL = root.getAttribute('data-api-url');
  var USERNAME = root.getAttribute('data-user-name') || 'there';
  var fab     = document.getElementById('cbFab');
  var closeBtn = document.getElementById('cbClose');
  var list    = document.getElementById('cbMessages');
  var form    = document.getElementById('cbForm');
  var input   = document.getElementById('cbInput');
  var sendBtn = document.getElementById('cbSend');
  var badge   = document.getElementById('cbBadge');
  var loaded  = false;
  var isFull  = root.classList.contains('cb-full');
  var STORE   = 'cb_open';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function timeStr() {
    var d = new Date();
    var h = d.getHours(), m = d.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
  }

  function bubble(sender, text) {
    var wrap = document.createElement('div');
    wrap.className = 'cb-msg ' + (sender === 'user' ? 'cb-msg-user' : 'cb-msg-bot');

    var body = document.createElement('div');
    body.textContent = text;
    wrap.appendChild(body);

    var t = document.createElement('div');
    t.className = 'cb-time';
    t.textContent = timeStr();
    wrap.appendChild(t);

    list.appendChild(wrap);
    list.scrollTop = list.scrollHeight;
    return wrap;
  }

  function chipsRow(items) {
    if (!items || !items.length) return;
    var row = document.createElement('div');
    row.className = 'cb-chips';
    items.forEach(function (c) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'cb-chip';
      chip.textContent = c.label;
      chip.addEventListener('click', function () {
        var v = c.value || c.label;
        if (c.url) { window.open(c.url, '_self'); return; }
        chip.disabled = true;
        sendText(v);
      });
      row.appendChild(chip);
    });
    list.appendChild(row);
    list.scrollTop = list.scrollHeight;
  }

  function actionsRow(items) {
    if (!items || !items.length) return;
    var row = document.createElement('div');
    row.className = 'cb-actions';
    items.forEach(function (a) {
      var btn = document.createElement('a');
      btn.className = 'cb-action-btn';
      btn.href = a.url;
      btn.target = '_self';
      btn.textContent = a.label;
      row.appendChild(btn);
    });
    list.appendChild(row);
    list.scrollTop = list.scrollHeight;
  }

  function typing(on) {
    if (on) {
      var t = document.createElement('div');
      t.className = 'cb-typing';
      t.id = 'cbTyping';
      t.innerHTML = '<span></span><span></span><span></span>';
      list.appendChild(t);
      list.scrollTop = list.scrollHeight;
    } else {
      var el = document.getElementById('cbTyping');
      if (el) el.remove();
    }
  }

  function loadHistory() {
    fetch(API_URL + '?history=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var msgs = data.messages || [];
        if (!msgs.length && data.greeting) {
          bubble('bot', data.greeting);
          chipsRow([
            { label: '📅 Book a session', value: 'book an appointment' },
            { label: '😟 I\u2019m feeling anxious', value: 'I am feeling very anxious right now' },
            { label: '😴 Trouble sleeping', value: 'I have trouble sleeping at night' },
            { label: '💬 Talk to a counselor', value: 'I want to talk to a human counselor' }
          ]);
        } else {
          msgs.forEach(function (m) {
            bubble(m.sender === 'user' ? 'user' : 'bot', m.message);
          });
        }
        if (badge) badge.style.display = 'none';
      })
      .catch(function () {});
  }

  function sendText(text) {
    var value = (text !== undefined && text !== null) ? String(text) : input.value.trim();
    if (!value) return;

    input.value = '';
    sendBtn.disabled = true;
    bubble('user', value);
    typing(true);

    fetch(API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: value })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        typing(false);
        bubble('bot', data.reply || 'Sorry, something went wrong. Please try again.');
        if (data.chips) chipsRow(data.chips);
        if (data.actions) actionsRow(data.actions);
      })
      .catch(function () {
        typing(false);
        bubble('bot', 'I\u2019m having trouble connecting. Please try again in a moment.');
      })
      .then(function () {
        sendBtn.disabled = false;
        input.focus();
      });
  }

  function openPanel() {
    root.classList.add('cb-open');
    if (!isFull) sessionStorage.setItem(STORE, '1');
    if (!loaded) { loaded = true; loadHistory(); }
    input.focus();
  }

  function closePanel() {
    root.classList.remove('cb-open');
    if (!isFull) sessionStorage.removeItem(STORE);
  }

  if (isFull) {
    openPanel();
  } else if (fab) {
    fab.addEventListener('click', function () {
      if (root.classList.contains('cb-open')) { closePanel(); }
      else { openPanel(); }
    });
    if (sessionStorage.getItem(STORE) === '1') openPanel();
  } else {
    openPanel();
  }

  if (closeBtn) closeBtn.addEventListener('click', closePanel);

  if (form) {
    form.addEventListener('submit', function (e) { e.preventDefault(); sendText(); });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !isFull && root.classList.contains('cb-open')) {
      closePanel();
    }
  });
})();

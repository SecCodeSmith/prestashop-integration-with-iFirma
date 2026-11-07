/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

/* iFirma module - document issue notifications in the back-office bell. */
(function () {
  'use strict';

  var cfg = window.ifirmaAdmin;
  var $ = window.jQuery;
  if (!cfg || !cfg.canView || !$ || !cfg.ajaxUrl) {
    return;
  }
  var words = cfg.words || {};

  /* Core posts the active tab type to its notifications/ack link; for our
     tab that answers 500, so the request is dropped before it is sent. */
  if (typeof window.jQuery === 'function' && typeof $(document).ajaxSend === 'function') {
    $(document).ajaxSend(function (event, xhr, settings) {
      var link = window.adminNotificationPushLink;
      if (!link || !settings || typeof settings.url !== 'string') {
        return;
      }
      var strip = function (u) {
        return String(u).replace(/^[a-z][a-z0-9+.-]*:\/\/[^\/]*/i, '');
      };
      if (settings.url !== String(link) && strip(settings.url) !== strip(link)) {
        return;
      }
      var data = settings.data;
      if (data && typeof data !== 'string') {
        try {
          data = $.param(data);
        } catch (e) {
          data = '';
        }
      }
      if (/(^|&)type=ifirma(&|$)/.test(String(data || ''))) {
        xhr.abort();
      }
    });
  }

  var BELLS = [
    { root: '#notif', tabs: '#notif ul.nav', content: '#notif .tab-content', total: '#notifications-total', hideTarget: null },
    { root: '#notification', tabs: '#notification ul.nav', content: '#notification .tab-content', total: '#total_notif_value', hideTarget: '#total_notif_number_wrapper' }
  ];

  var unseen = 0;
  var items = [];
  var bell = null;
  var fallback = false;
  var timer = null;
  var coreTotal = 0;
  var writing = false;
  var tick = 0;
  var seenTick = 0;
  var removedHide = false;
  var observer = null;
  var observed = null;
  var seenPending = false;

  function q(sel) {
    return document.querySelector(sel);
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) {
      node.className = cls;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  function endpoint(action) {
    return cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&action=' + action;
  }

  function call(action) {
    return $.ajax({ url: endpoint(action), type: 'POST', dataType: 'json', data: {} });
  }

  function safeUrl(url) {
    url = String(url || '');
    return /^\s*(javascript|data|vbscript):/i.test(url) ? '#' : url;
  }

  function detectBell() {
    var i;
    for (i = 0; i < BELLS.length; i++) {
      if (q(BELLS[i].root) && q(BELLS[i].tabs) && q(BELLS[i].content)) {
        return BELLS[i];
      }
    }
    return null;
  }

  /* ---------------------------------------------------------------- build */

  function buildPaneInner(pane) {
    pane.appendChild(el('p', 'no-notification', words.no_issues));
    pane.appendChild(el('div', 'notification-elements'));
    var footer = el('div', 'ifirma-notif-footer');
    var link = el('a', null, words.see_all);
    link.setAttribute('href', safeUrl(cfg.listUrl));
    footer.appendChild(link);
    pane.appendChild(footer);
  }

  function insertIntoBell() {
    var tabsList = q(bell.tabs);
    var content = q(bell.content);
    if (!tabsList || !content) {
      return;
    }
    if (!document.getElementById('ifirma-tab')) {
      var li = el('li', 'nav-item');
      var a = el('a', 'nav-link');
      a.id = 'ifirma-tab';
      a.setAttribute('data-toggle', 'tab');
      a.setAttribute('data-type', 'ifirma');
      a.setAttribute('href', '#ifirma-notifications');
      a.setAttribute('role', 'tab');
      a.appendChild(document.createTextNode(String(words.tab_title || '') + ' '));
      a.appendChild(el('span', 'ifirma-notif-count'));
      li.appendChild(a);
      tabsList.appendChild(li);
    }
    if (!document.getElementById('ifirma-notifications')) {
      var pane = el('div', 'tab-pane empty');
      pane.id = 'ifirma-notifications';
      pane.setAttribute('role', 'tabpanel');
      buildPaneInner(pane);
      content.appendChild(pane);
    }
  }

  function insertFallback() {
    if (document.getElementById('ifirma-notif')) {
      return true;
    }
    var host = q('.header-right') || q('#header_infos');
    if (!host) {
      return false;
    }
    var wrap = el('div', 'component');
    wrap.id = 'ifirma-notif';
    var btn = el('button', 'btn btn-link');
    btn.setAttribute('type', 'button');
    btn.id = 'ifirma-notif-button';
    btn.setAttribute('title', String(words.tab_title || ''));
    btn.appendChild(el('i', 'material-icons', 'receipt_long'));
    btn.appendChild(el('span', 'ifirma-notif-count ifirma-notif-badge'));
    var panel = el('div', 'ifirma-notif-panel');
    var pane = el('div', 'empty');
    pane.id = 'ifirma-notifications';
    buildPaneInner(pane);
    panel.appendChild(pane);
    wrap.appendChild(btn);
    wrap.appendChild(panel);
    host.appendChild(wrap);
    return true;
  }

  function ensureInserted() {
    if (bell) {
      insertIntoBell();
      return true;
    }
    return insertFallback();
  }

  /* --------------------------------------------------------------- render */

  function renderList() {
    var pane = document.getElementById('ifirma-notifications');
    if (!pane) {
      return;
    }
    var list = pane.querySelector('.notification-elements');
    if (list) {
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      items.forEach(function (it) {
        var a = el('a', 'notif');
        a.setAttribute('href', safeUrl(it.url));
        var title = el('p', 'text');
        title.appendChild(el('strong', null, it.reference));
        title.appendChild(document.createTextNode(' - ' + String(it.kind_label || it.kind || '')));
        a.appendChild(title);
        var meta = [];
        if (it.number) {
          meta.push(String(it.number));
        }
        if (it.time) {
          meta.push(String(it.time));
        }
        a.appendChild(el('small', 'text-muted', meta.join(' | ')));
        list.appendChild(a);
      });
    }
    if (items.length === 0) {
      pane.classList.add('empty');
    } else {
      pane.classList.remove('empty');
    }
  }

  function renderBadge() {
    var spans = document.querySelectorAll('.ifirma-notif-count');
    var i;
    for (i = 0; i < spans.length; i++) {
      spans[i].textContent = unseen > 0 ? String(unseen) : '';
    }
  }

  function hideTargetEl() {
    if (!bell) {
      return null;
    }
    return bell.hideTarget ? q(bell.hideTarget) : q(bell.total);
  }

  function renderTotal() {
    if (!bell) {
      return;
    }
    var totalEl = q(bell.total);
    if (!totalEl) {
      return;
    }
    /* a write of the core still waiting in the observer queue is the new core total: take it first */
    if (observer && observer.takeRecords().length > 0) {
      readCoreTotal();
    }
    var sum = coreTotal + unseen;
    var text = String(sum);
    if (totalEl.textContent !== text) {
      /* our own write must not come back as a core write: raise the flag and drop its records at once */
      writing = true;
      totalEl.textContent = text;
      if (observer) {
        observer.takeRecords();
      }
      writing = false;
    }
    var ht = hideTargetEl();
    if (ht) {
      if (sum > 0) {
        if (ht.classList.contains('hide')) {
          ht.classList.remove('hide');
          removedHide = true;
        }
      } else if (removedHide) {
        ht.classList.add('hide');
        removedHide = false;
      }
    }
  }

  function renderAll() {
    renderList();
    renderBadge();
    renderTotal();
  }

  /* ------------------------------------------------------------- observer */

  function readCoreTotal() {
    var totalEl = bell ? q(bell.total) : null;
    if (!totalEl) {
      return;
    }
    coreTotal = parseInt(String(totalEl.textContent).replace(/[^0-9]/g, ''), 10) || 0;
  }

  function observeTotal() {
    if (!bell || typeof window.MutationObserver !== 'function') {
      return;
    }
    var totalEl = q(bell.total);
    if (!totalEl || observed === totalEl) {
      return;
    }
    if (observer) {
      observer.disconnect();
    }
    observed = totalEl;
    observer = new window.MutationObserver(function () {
      if (writing) {
        return;
      }
      readCoreTotal();
      renderTotal();
    });
    observer.observe(totalEl, { childList: true, characterData: true, subtree: true });
  }

  /* ----------------------------------------------------------------- feed */

  function applyFeed(data) {
    if (!data || typeof data !== 'object') {
      return;
    }
    items = Array.isArray(data.items) ? data.items : [];
    unseen = parseInt(data.unseen, 10) || 0;
    ensureInserted();
    observeTotal();
    renderAll();
  }

  function schedule() {
    if (timer) {
      window.clearTimeout(timer);
      timer = null;
    }
    if (document.hidden) {
      return;
    }
    timer = window.setTimeout(poll, parseInt(cfg.pollInterval, 10) || 120000);
  }

  function poll() {
    if (timer) {
      window.clearTimeout(timer);
      timer = null;
    }
    var started = ++tick;
    call('IssueFeed').done(function (res) {
      /* an answer to a request that started before the last successful seen POST still carries the old count */
      if (started < seenTick) {
        return;
      }
      if (res && res.success && res.data) {
        applyFeed(res.data);
      }
    }).always(schedule);
  }

  function markSeen() {
    if (seenPending || unseen <= 0) {
      return;
    }
    seenPending = true;
    call('IssueFeedSeen').always(function () {
      seenPending = false;
    }).done(function (res) {
      if (res && res.success) {
        seenTick = ++tick;
        unseen = 0;
        renderBadge();
        renderTotal();
        /* ask again: the feed now answers with the mark in place */
        poll();
      }
    });
  }

  /* ----------------------------------------------------------------- init */

  function init() {
    bell = detectBell();
    fallback = !bell;
    if (!ensureInserted()) {
      return;
    }
    readCoreTotal();
    observeTotal();

    $(document).on('shown.bs.tab', '#ifirma-tab', markSeen);
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      while (t && t !== document) {
        if (t.id === 'ifirma-tab') {
          markSeen();
          return;
        }
        if (fallback && t.id === 'ifirma-notif-button') {
          var wrap = document.getElementById('ifirma-notif');
          var opening = !wrap.classList.contains('open');
          wrap.classList.toggle('open');
          if (opening) {
            markSeen();
          }
          return;
        }
        t = t.parentNode;
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        if (timer) {
          window.clearTimeout(timer);
          timer = null;
        }
      } else {
        poll();
      }
    });

    poll();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());

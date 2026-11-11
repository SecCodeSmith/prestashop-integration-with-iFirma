/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

/* iFirma module - issue from the Orders list and batched bulk runs. */
(function () {
  'use strict';

  var cfg = window.ifirmaAdmin;
  var $ = window.jQuery;
  if (!cfg || !$ || !cfg.ajaxUrl) {
    return;
  }
  var words = cfg.words || {};
  var outcomeWords = words.outcomes || {};
  var busy = false;
  var reloadDone = false;

  function endpoint(action) {
    return cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&action=' + action;
  }

  function fmt(text, a, b) {
    return String(text || '')
      .replace('%1$d', String(a))
      .replace('%2$d', String(b))
      .replace('%d', String(a));
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

  /* POST an action; resolves with parsed JSON, rejects with {message, unknown}. unknown is true when the
     answer is not one of the module's JSON envelopes (transport failure, HTML page): the request may have
     been processed. */
  function post(action, data) {
    var d = $.Deferred();
    $.ajax({ url: endpoint(action), type: 'POST', dataType: 'text', data: data }).done(function (txt) {
      var parsed = null;
      try {
        parsed = JSON.parse(txt);
      } catch (e) {
        parsed = null;
      }
      if (parsed && typeof parsed === 'object') {
        d.resolve(parsed);
      } else {
        d.reject({ message: words.request_failed, unknown: true });
      }
    }).fail(function (xhr) {
      var msg = words.request_failed;
      var known = false;
      try {
        var p = JSON.parse(xhr && xhr.responseText);
        known = !!p && typeof p === 'object';
        if (p && p.message) {
          msg = p.message;
        }
      } catch (e) {
        msg = words.request_failed;
      }
      d.reject({ message: msg, unknown: !known });
    });
    return d.promise();
  }

  /* ------------------------------------------------------- orders: single */

  function showGridAlert(success, message) {
    var old = document.getElementById('ifirma-grid-alert');
    if (old && old.parentNode) {
      old.parentNode.removeChild(old);
    }
    var form = document.getElementById('order_filter_form');
    if (!form || !form.parentNode) {
      return;
    }
    var box = el('div', 'alert alert-dismissible ' + (success ? 'alert-success' : 'alert-danger'));
    box.id = 'ifirma-grid-alert';
    box.setAttribute('role', 'alert');
    var close = el('button', 'close', '×');
    close.setAttribute('type', 'button');
    close.setAttribute('data-dismiss', 'alert');
    close.addEventListener('click', function () {
      if (box.parentNode) {
        box.parentNode.removeChild(box);
      }
    });
    box.appendChild(close);
    box.appendChild(el('span', null, message));
    form.parentNode.insertBefore(box, form);
  }

  function issueFromList(anchor) {
    if (busy) {
      return;
    }
    if (!window.confirm(String(words.confirm_issue || ''))) {
      return;
    }
    var idOrder = parseInt(anchor.getAttribute('data-id-order'), 10) || 0;
    var cellNode = anchor.closest('td') || anchor.parentNode;
    var saved = cellNode.innerHTML;
    busy = true;
    cellNode.textContent = String(words.issuing || '');
    post('IssueFromList', { id_order: idOrder }).done(function (res) {
      busy = false;
      if (res.data && typeof res.data.cell === 'string' && res.data.cell !== '') {
        cellNode.innerHTML = res.data.cell; // trusted module output
      } else {
        cellNode.innerHTML = saved;
      }
      showGridAlert(!!res.success, res.message || '');
    }).fail(function (err) {
      busy = false;
      cellNode.innerHTML = saved;
      showGridAlert(false, err.message || words.request_failed);
    });
  }

  /* --------------------------------------------------------- batch runner */

  var modal = null;

  function ensureModal() {
    if (modal) {
      return modal;
    }
    var m = el('div', 'modal fade');
    m.id = 'ifirma-bulk-modal';
    m.setAttribute('tabindex', '-1');
    m.setAttribute('role', 'dialog');
    var dialog = el('div', 'modal-dialog modal-lg');
    dialog.setAttribute('role', 'document');
    var content = el('div', 'modal-content');

    var head = el('div', 'modal-header');
    head.appendChild(el('h4', 'modal-title', words.summary_title));

    var body = el('div', 'modal-body');
    body.appendChild(el('p', 'ifirma-bulk-progress'));
    var wrap = el('div', 'table-responsive');
    var table = el('table', 'table ifirma-bulk-table');
    var thead = el('thead');
    var tr = el('tr');
    [words.col_order, words.col_outcome, words.col_message].forEach(function (w) {
      tr.appendChild(el('th', null, w));
    });
    thead.appendChild(tr);
    table.appendChild(thead);
    table.appendChild(el('tbody'));
    wrap.appendChild(table);
    body.appendChild(wrap);
    body.appendChild(el('p', 'ifirma-bulk-totals'));

    var foot = el('div', 'modal-footer');
    var btn = el('button', 'btn btn-secondary btn-default ifirma-bulk-close', words.close);
    btn.setAttribute('type', 'button');
    btn.addEventListener('click', function () {
      if (busy) {
        return;
      }
      hideModal();
    });
    foot.appendChild(btn);

    content.appendChild(head);
    content.appendChild(body);
    content.appendChild(foot);
    dialog.appendChild(content);
    m.appendChild(dialog);
    document.body.appendChild(m);

    $(m).on('hidden.bs.modal', reloadOnce);
    modal = m;
    return m;
  }

  function reloadOnce() {
    if (reloadDone || busy) {
      return;
    }
    reloadDone = true;
    window.location.reload();
  }

  function showModal() {
    var m = ensureModal();
    if ($.fn && typeof $.fn.modal === 'function') {
      $(m).modal({ backdrop: 'static', keyboard: false, show: true });
    } else {
      m.style.display = 'block';
      m.classList.add('in');
      m.classList.add('show');
    }
  }

  function hideModal() {
    var m = ensureModal();
    if ($.fn && typeof $.fn.modal === 'function') {
      $(m).modal('hide');
    } else {
      m.style.display = 'none';
      m.classList.remove('in');
      m.classList.remove('show');
      reloadOnce();
    }
  }

  function setBusyUi(on) {
    var m = ensureModal();
    m.querySelector('.ifirma-bulk-close').disabled = !!on;
  }

  function appendRow(r) {
    var tbody = ensureModal().querySelector('tbody');
    var tr = el('tr');
    var label = r.id_order && parseInt(r.id_order, 10) > 0 ? r.id_order : r.id;
    tr.appendChild(el('td', null, label));
    tr.appendChild(el('td', null, outcomeWords[r.outcome] || r.outcome));
    tr.appendChild(el('td', null, r.message || ''));
    tbody.appendChild(tr);
  }

  function renderTotals(results) {
    var counts = {};
    var order = [];
    results.forEach(function (r) {
      if (!counts.hasOwnProperty(r.outcome)) {
        counts[r.outcome] = 0;
        order.push(r.outcome);
      }
      counts[r.outcome]++;
    });
    ensureModal().querySelector('.ifirma-bulk-totals').textContent = order.map(function (o) {
      return (outcomeWords[o] || o) + ': ' + counts[o];
    }).join(', ');
  }

  function setProgress(text) {
    ensureModal().querySelector('.ifirma-bulk-progress').textContent = text;
  }

  function totalsOf(results) {
    var t = {};
    results.forEach(function (r) {
      t[r.outcome] = (t[r.outcome] || 0) + 1;
    });
    return t;
  }

  function applyCells(kind, results) {
    if (kind !== 'issue') {
      return;
    }
    var form = document.getElementById('order_filter_form');
    if (!form) {
      return;
    }
    results.forEach(function (r) {
      if (typeof r.cell !== 'string' || r.cell === '') {
        return;
      }
      var boxes = form.querySelectorAll('.js-bulk-action-checkbox');
      var i;
      for (i = 0; i < boxes.length; i++) {
        if (String(boxes[i].value) === String(r.id)) {
          var row = boxes[i].closest('tr');
          var cell = row && (row.querySelector('td.column-ifirma_document') || (row.querySelector('a.js-ifirma-issue') || {}).parentNode);
          if (cell) {
            cell.innerHTML = r.cell; // trusted module output
          }
          return;
        }
      }
    });
  }

  function runBatches(kind, ids) {
    var size = (cfg.batchSizes && parseInt(cfg.batchSizes[kind], 10)) || 1;
    var queue = ids.slice();
    var total = ids.length;
    var results = [];

    function notAttempted(list, text) {
      list.forEach(function (id) {
        var r = { id: id, id_order: kind === 'issue' ? id : 0, outcome: 'not_attempted', message: text };
        results.push(r);
        appendRow(r);
      });
    }

    /* The batch in flight got no usable answer: it may have been processed, so its ids are "unknown" (the
       list has to be reloaded to see), the ids still queued were never sent. */
    function lostAnswer(batch, err) {
      var word = outcomeWords.unknown || outcomeWords.failed || 'unknown';
      batch.forEach(function (id) {
        var r = { id: id, id_order: kind === 'issue' ? id : 0, outcome: 'unknown', message: err.message || words.request_failed };
        results.push(r);
        appendRow(r);
      });
      setProgress(word);
      notAttempted(queue, word);
      queue = [];
      finish();
    }

    function finish() {
      busy = false;
      setBusyUi(false);
      renderTotals(results);
      applyCells(kind, results);
    }

    function step() {
      if (queue.length === 0) {
        setProgress(words.summary_title);
        finish();
        return;
      }
      var batch = queue.splice(0, size);
      var isFinal = queue.length === 0;
      var data = { kind: kind, ids: batch, final: isFinal ? 1 : 0 };
      if (results.length > 0) {
        var prev = totalsOf(results);
        Object.keys(prev).forEach(function (k) {
          data['totals[' + k + ']'] = prev[k];
        });
      }
      post('BulkRun', data).done(function (res) {
        var d = res && res.data;
        if (!res.success || !d || !Array.isArray(d.results)) {
          setProgress(res.message || words.request_failed);
          notAttempted(batch.concat(queue), res.message || words.request_failed);
          queue = [];
          finish();
          return;
        }
        d.results.forEach(function (r) {
          results.push(r);
          appendRow(r);
        });
        if (Array.isArray(d.remaining) && d.remaining.length) {
          queue = d.remaining.concat(queue);
        }
        setProgress(fmt(words.running, results.length, total));
        if (d.stopped) {
          var text = words['stopped_' + d.stopped] || d.stopped;
          setProgress(text);
          notAttempted(queue, text);
          queue = [];
          finish();
          return;
        }
        step();
      }).fail(function (err) {
        if (err.unknown) {
          lostAnswer(batch, err);
          return;
        }
        var text = err.message || words.request_failed;
        setProgress(text);
        notAttempted(batch.concat(queue), text);
        queue = [];
        finish();
      });
    }

    reloadDone = false;
    var m = ensureModal();
    m.querySelector('tbody').textContent = '';
    m.querySelector('.ifirma-bulk-totals').textContent = '';
    setProgress(fmt(words.running, 0, total));
    busy = true;
    setBusyUi(true);
    showModal();
    step();
  }

  function startRun(kind, ids) {
    if (busy) {
      return;
    }
    if (!ids.length) {
      window.alert(String(words.nothing_selected || ''));
      return;
    }
    if (!window.confirm(fmt(words['confirm_bulk_' + kind], ids.length, ids.length))) {
      return;
    }
    runBatches(kind, ids);
  }

  /* --------------------------------------------------------------- wiring */

  function ordersIds() {
    var form = document.getElementById('order_filter_form');
    var out = [];
    if (!form) {
      return out;
    }
    var boxes = form.querySelectorAll('.js-bulk-action-checkbox:checked');
    var i;
    for (i = 0; i < boxes.length; i++) {
      var v = parseInt(boxes[i].value, 10);
      if (v > 0) {
        out.push(v);
      }
    }
    return out;
  }

  function listIds() {
    var out = [];
    var boxes = document.querySelectorAll('input[name="ifirma_receiptBox[]"]:checked');
    var i;
    for (i = 0; i < boxes.length; i++) {
      var v = parseInt(boxes[i].value, 10);
      if (v > 0) {
        out.push(v);
      }
    }
    return out;
  }

  function initOrders() {
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || !t.closest) {
        return;
      }
      var anchor = t.closest('a.js-ifirma-issue');
      if (anchor) {
        ev.stopPropagation();
        ev.preventDefault();
        issueFromList(anchor);
        return;
      }
      var bulk = t.closest('.js-ifirma-bulk-issue');
      if (bulk) {
        ev.stopPropagation();
        ev.preventDefault();
        startRun('issue', ordersIds());
      }
    }, true);

    /* Core binds mousedown/mouseup on td.clickable to open the order page. */
    ['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'auxclick'].forEach(function (type) {
      var isUp = type === 'mouseup' || type === 'pointerup' || type === 'auxclick';
      document.addEventListener(type, function (ev) {
        var t = ev.target;
        if (!t || !t.closest) {
          return;
        }
        if (t.closest('a.js-ifirma-issue') || t.closest('.js-ifirma-bulk-issue')) {
          ev.stopPropagation();
          if (isUp) {
            ev.preventDefault();
          }
        }
      }, true);
    });
  }

  function initList() {
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      var btn = t && t.closest ? t.closest('.js-ifirma-row[data-kind][data-id]') : null;
      if (!btn) {
        return;
      }
      ev.preventDefault();
      ev.stopPropagation();
      var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
      startRun(btn.getAttribute('data-kind'), id > 0 ? [id] : []);
    });
    var map = { submitBulkretryIssuance: 'retry', submitBulkresend: 'resend', submitBulkrefresh: 'refresh', submitBulkmarkSent: 'mark_sent' };
    var nodes = document.querySelectorAll('[onclick]');
    var i;
    Object.keys(map).forEach(function (key) {
      for (i = 0; i < nodes.length; i++) {
        var oc = nodes[i].getAttribute('onclick') || '';
        if (oc.indexOf(key) !== -1) {
          bindNative(nodes[i], map[key]);
        }
      }
    });
  }

  function bindNative(node, kind) {
    node.removeAttribute('onclick');
    node.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      startRun(kind, listIds());
    });
  }

  function init() {
    if (document.getElementById('order_filter_form')) {
      initOrders();
    } else if (document.querySelector('input[name="ifirma_receiptBox[]"]')) {
      initList();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());

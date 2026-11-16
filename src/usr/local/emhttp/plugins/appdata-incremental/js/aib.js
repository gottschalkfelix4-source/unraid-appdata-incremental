/* Appdata Incremental – WebGUI (jQuery + SweetAlert v1 from the Unraid layout) */
(function ($) {
  'use strict';
  if (window.AIB) return;
  var API = '/plugins/appdata-incremental/include/api.php';
  var A = window.AIB = {};

  // ------------------------------------------------------------------ helpers
  A.esc = function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  A.human = function (b) {
    if (b === null || b === undefined || b === '') return '–';
    b = Number(b); var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(2) : b) + ' ' + u[i];
  };
  A.date = function (s) {
    if (!s) return '–';
    var d = new Date(s); if (isNaN(d)) return A.esc(s);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  };
  A.dur = function (s) {
    if (s === null || s === undefined) return '–';
    s = Math.round(s); var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    return (h ? h + ' h ' : '') + (h || m ? m + ' min ' : '') + x + ' s';
  };
  A.token = function () { return typeof csrf_token !== 'undefined' ? csrf_token : ($('input[name=csrf_token]').first().val() || ''); };
  A.api = function (action, data) {
    data = $.extend({}, data || {}, { action: action, csrf_token: A.token() });
    $.each(data, function (k, v) { if (v !== null && typeof v === 'object') data[k] = JSON.stringify(v); });
    return $.ajax({ url: API, method: 'POST', data: data, dataType: 'json', timeout: 600000 }).then(function (r) {
      return r && r.ok ? r.data : $.Deferred().reject(r && r.error ? r.error : 'Unbekannter Fehler');
    }, function (x) {
      return $.Deferred().reject(x.responseJSON && x.responseJSON.error ? x.responseJSON.error : 'Server-Fehler (HTTP ' + x.status + ')');
    });
  };
  A.msg = function (el, text, kind) {
    var $e = $(el);
    if (!text) { $e.removeClass('ok err info').hide().text(''); return; }
    $e.removeClass('ok err info').addClass(kind || 'info').text(text).show();
  };
  A.confirm = function (title, text, yes, cb, danger) {
    if (typeof swal === 'function') {
      swal({ title: title, text: text, type: danger ? 'warning' : 'info', showCancelButton: true, confirmButtonText: yes, cancelButtonText: 'Abbrechen', closeOnConfirm: true, html: false },
        function (ok) { if (ok) cb(); });
    } else if (window.confirm(title + '\n\n' + text)) cb();
  };
  A.busy = function (btn, on) { $(btn).prop('disabled', on); };
  A.download = function (name, text, type) {
    var b = new Blob([text], { type: type || 'text/plain' }), a = document.createElement('a');
    a.href = URL.createObjectURL(b); a.download = name; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  };
  A.copy = function (text, el) {
    var done = function () { if (el) { var o = $(el).val(); $(el).val('Kopiert ✓'); setTimeout(function () { $(el).val(o); }, 1500); } };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done);
    else { var t = $('<textarea>').val(text).appendTo('body').select(); document.execCommand('copy'); t.remove(); done(); }
  };
  A.gotoTab = function (title) {
    $('div.tabs .tab label, .tabs label, a[role=tab]').filter(function () { return $.trim($(this).text()) === title; }).first().click();
    window.scrollTo(0, 0);
  };
  A.lines = function (txt) { return $.map(String(txt || '').split('\n'), function (l) { l = $.trim(l); return l ? l : null; }); };

  A.containers = null; // cache
  A.loadContainers = function (force) {
    if (A.containers && !force) return $.Deferred().resolve(A.containers);
    return A.api('containers').then(function (d) { A.containers = d; return d; });
  };

  // ------------------------------------------------------------------ status
  var Status = {
    file: '', offset: 0, timer: null, wasRunning: false,
    init: function () {
      var $s = $('#aib-status'); if (!$s.length) return;
      $('#aib-run-all').on('click', function () {
        A.confirm('Backup starten', 'Jetzt alle ausgewählten Container sichern?', 'Starten', function () { Status.start('backup', []); });
      });
      $('#aib-run-dry').on('click', function () { Status.start('dryrun', []); });
      $('#aib-cancel').on('click', function () {
        A.confirm('Vorgang abbrechen', 'Der laufende Vorgang wird beendet. Gestoppte Container werden wieder gestartet und Snapshots gelöscht.', 'Abbrechen', function () {
          A.api('cancel').then(function (d) { A.msg('#aib-status-msg', d.message, 'info'); Status.poll(); }, function (e) { A.msg('#aib-status-msg', e, 'err'); });
        }, true);
      });
      $('#aib-log-clear').on('click', function () { $('#aib-livelog').text(''); });
      Status.poll();
    },
    start: function (kind, cts) {
      A.msg('#aib-status-msg', '');
      return A.api('run', { kind: kind, containers: cts }).then(function () {
        A.msg('#aib-status-msg', kind === 'dryrun' ? 'Testlauf gestartet – es wird nichts gesichert und kein Container gestoppt.' : 'Gestartet.', 'ok');
        Status.file = ''; Status.offset = 0; $('#aib-livelog').text(''); Status.poll();
      }, function (e) { A.msg('#aib-status-msg', e, 'err'); });
    },
    poll: function () {
      clearTimeout(Status.timer);
      A.api('state').then(function (s) {
        Status.render(s);
        if (s.running || Status.wasRunning || !Status.file) Status.tail();
        if (Status.wasRunning && !s.running) { if (A.History) A.History.load(false); if (A.Tools) A.Tools.logs(); }
        Status.wasRunning = s.running;
        Status.timer = setTimeout(Status.poll, s.running ? 2000 : 15000);
      }, function () { Status.timer = setTimeout(Status.poll, 15000); });
    },
    tail: function () {
      A.api('log_tail', { file: Status.file, offset: Status.offset }).then(function (d) {
        var $l = $('#aib-livelog');
        if (d.file !== Status.file) { $l.text(''); Status.file = d.file; }
        Status.offset = d.offset;
        if (d.data) {
          var atEnd = $l[0].scrollTop + $l[0].clientHeight >= $l[0].scrollHeight - 30;
          $l.append(document.createTextNode(d.data));
          if (atEnd) $l[0].scrollTop = $l[0].scrollHeight;
        }
        $('#aib-logname').text(d.file || '');
      });
    },
    render: function (s) {
      var phases = { start: 'Start', stop: 'Container werden gestoppt', snapshot: 'Snapshot', backup: 'Sicherung läuft', forget: 'Aufbewahrung', prune: 'Aufräumen', check: 'Prüfung', restore: 'Wiederherstellung', idle: 'Bereit' };
      var $b = $('#aib-state');
      if (s.running) {
        var t = (s.kind || 'Vorgang') + ' läuft seit ' + A.date(s.started) + ' – ' + (phases[s.phase] || s.phase || '') + (s.current ? ' (' + s.current + ')' : '');
        $b.html('<span class="aib-badge orange">LÄUFT</span> ' + A.esc(t));
        var p = s.progress;
        if (p && s.phase === 'backup') {
          $('#aib-progress').show().children().css('width', Math.min(100, p.percent || 0) + '%');
          $('#aib-progress-text').text(p.container + ': ' + (p.percent || 0) + ' % – ' + A.human(p.bytes_done) + ' von ' + A.human(p.total_bytes) + ', ' + (p.files_done || 0) + ' von ' + (p.total_files || 0) + ' Dateien');
        } else { $('#aib-progress').hide(); $('#aib-progress-text').text(''); }
        $('#aib-cancel').show(); $('#aib-run-all,#aib-run-dry').prop('disabled', true);
      } else {
        $b.html('<span class="aib-badge green">BEREIT</span> ' + (s.schedule_active ? 'Zeitplan aktiv (' + A.esc(s.schedule) + ')' : 'Zeitplan aus'));
        $('#aib-progress').hide(); $('#aib-progress-text').text('');
        $('#aib-cancel').hide(); $('#aib-run-all,#aib-run-dry').prop('disabled', !s.configured);
      }
      $('#aib-setup-hint').toggle(!s.configured);
      var l = s.last, $t = $('#aib-last');
      if (!l) { $t.html('<p class="aib-muted">Noch kein Lauf.</p>'); return; }
      var cls = { ok: 'green', warning: 'orange', error: 'red' }[l.result] || '';
      var txt = { ok: 'erfolgreich', warning: 'mit Warnungen', error: 'fehlgeschlagen' }[l.result] || l.result;
      var h = '<p><span class="aib-badge ' + cls + '">' + A.esc(txt) + '</span> ' + A.esc(l.kind) + ' am ' + A.date(l.finished) +
        ' · Dauer ' + A.dur(l.duration) + ' · ' + l.errors + ' Fehler, ' + l.warnings + ' Warnungen</p>';
      var rows = '';
      $.each(l.containers || {}, function (n, r) {
        var rc = { ok: 'green', warning: 'orange', error: 'red', cancelled: 'red' }[r.status] || '';
        var rt = { ok: 'OK', warning: 'Warnung', error: 'Fehler', cancelled: 'abgebrochen', pending: 'abgebrochen' }[r.status] || r.status;
        rows += '<tr><td>' + A.esc(n) + '</td><td><span class="aib-badge ' + rc + '">' + A.esc(rt) + '</span></td><td>' + A.human(r.data_added) +
          '</td><td>' + (r.files_new === null || r.files_new === undefined ? '–' : r.files_new + ' / ' + r.files_changed) + '</td><td>' + A.human(r.total_bytes) +
          '</td><td>' + (r.downtime ? A.dur(r.downtime) : '–') + '</td><td>' + A.dur(r.duration) + '</td></tr>';
      });
      if (rows) h += '<table class="tablesorter shift aib-table"><thead><tr><th>Container</th><th>Ergebnis</th><th>Neu hochgeladen</th><th>Neue / geänderte Dateien</th><th>Gelesen</th><th>Ausfallzeit</th><th>Dauer</th></tr></thead><tbody>' + rows + '</tbody></table>';
      $t.html(h);
    }
  };
  A.Status = Status;

  // ------------------------------------------------------------------ containers
  var Cts = {
    data: null, open: {},
    init: function () {
      if (!$('#aib-containers').length) return;
      $('#aib-ct-save').on('click', Cts.save);
      $('#aib-ct-reload').on('click', function () { Cts.load(true); });
      $('#aib-ct-all').on('click', function () { $('#aib-ct-body input.aib-en').prop('checked', true).trigger('change'); });
      $('#aib-ct-none').on('click', function () { $('#aib-ct-body input.aib-en').prop('checked', false).trigger('change'); });
      $('#aib-ct-filter').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('#aib-ct-body tr.aib-main').each(function () {
          var n = $(this).data('name').toLowerCase(); var show = !q || n.indexOf(q) >= 0;
          $(this).toggle(show); if (!show) $(this).next('tr.aib-detail').hide();
        });
      });
      $('#aib-ct-body').on('change', 'input.aib-en', function () { $(this).closest('tr').toggleClass('aib-off', !this.checked); Cts.dirty(true); })
        .on('change input', 'input,textarea,select', function () { Cts.dirty(true); })
        .on('click', '.aib-edit', function () { Cts.toggle($(this).closest('tr').data('name')); })
        .on('click', '.aib-now', function () {
          var n = $(this).closest('tr').data('name');
          A.confirm('Jetzt sichern', 'Container „' + n + '“ jetzt sichern?', 'Starten', function () {
            A.api('run', { kind: 'backup', containers: [n] }).then(function () { A.msg('#aib-ct-msg', 'Sicherung von ' + n + ' gestartet – Fortschritt im Tab „Status“.', 'ok'); if (A.Status) A.Status.poll(); },
              function (e) { A.msg('#aib-ct-msg', e, 'err'); });
          });
        });
      Cts.load(false);
    },
    dirty: function (on) { $('#aib-ct-save').val(on ? 'Speichern *' : 'Speichern'); },
    load: function (force) {
      A.msg('#aib-ct-msg', '');
      $('#aib-ct-body').html('<tr><td colspan="8"><i class="fa fa-spinner fa-spin"></i> Lade Container …</td></tr>');
      A.loadContainers(force).then(function (d) { Cts.data = d; Cts.render(); Cts.dirty(false); }, function (e) { $('#aib-ct-body').html(''); A.msg('#aib-ct-msg', e, 'err'); });
    },
    find: function (n) { var r = null; $.each(Cts.data.containers, function (_, c) { if (c.name === n) r = c; }); return r; },
    render: function () {
      var h = '';
      $.each(Cts.data.containers, function (_, c) {
        var icon = c.icon ? '<img class="aib-icon" src="' + A.esc(c.icon) + '" onerror="this.outerHTML=\'<span class=&quot;aib-icon-ph fa fa-cube&quot;></span>\'">' : '<span class="aib-icon-ph fa fa-cube"></span>';
        var badges = '';
        if (c.source !== 'config') badges += '<span class="aib-badge orange" title="Noch nicht gespeichert – Voreinstellung">neu</span>';
        if (c.source === 'auto-disabled' || c.source === 'preset') badges += '<span class="aib-badge" title="' + A.esc(c.note) + '">Regel</span>';
        var paths = c.paths.length ? $.map(c.paths, A.esc).join('<br>') : '<i>keine Appdata – nur Konfiguration</i>';
        var ex = c.excludes.length ? $.map(c.excludes, function (e) { return '<span class="aib-chip">' + A.esc(e) + '</span>'; }).join('') : '<span class="aib-muted">–</span>';
        h += '<tr class="aib-main' + (c.enabled ? '' : ' aib-off') + '" data-name="' + A.esc(c.name) + '">' +
          '<td class="aib-name aib-keep">' + icon + '<span class="aib-dot' + (c.running ? ' on' : '') + '" title="' + (c.running ? 'läuft' : 'gestoppt') + '"></span><b>' + A.esc(c.name) + '</b> ' + badges +
          '<div class="aib-paths">' + paths + '</div></td>' +
          '<td class="aib-keep"><input type="checkbox" class="aib-en"' + (c.enabled ? ' checked' : '') + ' title="sichern"></td>' +
          '<td><input type="checkbox" class="aib-stop"' + (c.stop ? ' checked' : '') + (c.paths.length ? '' : ' disabled') + ' title="vor dem Snapshot stoppen"></td>' +
          '<td><input type="text" class="aib-small aib-group" maxlength="40" value="' + A.esc(c.group) + '" placeholder="–"></td>' +
          '<td><input type="number" class="aib-num aib-order" min="0" max="9999" value="' + c.order + '"></td>' +
          '<td class="aib-exsum">' + ex + '</td>' +
          '<td style="white-space:nowrap"><input type="button" class="aib-edit" value="Ausschlüsse …"> <input type="button" class="aib-now" value="Jetzt sichern"' + (c.enabled ? '' : ' disabled') + '></td></tr>';
        h += '<tr class="aib-detail" data-name="' + A.esc(c.name) + '" style="display:none"><td colspan="7"></td></tr>';
      });
      $('#aib-ct-body').html(h || '<tr><td colspan="7">Keine Container gefunden.</td></tr>');
      $.each(Cts.open, function (n) { Cts.toggle(n, true); });
    },
    detailRow: function (n) { return $('#aib-ct-body tr.aib-detail').filter(function () { return $(this).data('name') === n; }); },
    mainRow: function (n) { return $('#aib-ct-body tr.aib-main').filter(function () { return $(this).data('name') === n; }); },
    toggle: function (n, forceOpen) {
      if (!Cts.data) return;
      var $d = Cts.detailRow(n), c = Cts.find(n);
      if (!c || !$d.length) return;
      if ($d.is(':visible') && !forceOpen) { $d.hide(); delete Cts.open[n]; return; }
      Cts.open[n] = true;
      if (!$d.data('built')) {
        var tpl = '<option value="">Vorlage anwenden …</option>';
        $.each(Cts.data.templates, function (k, t) { tpl += '<option value="' + A.esc(k) + '">' + A.esc(t.label) + '</option>'; });
        $d.children('td').html(
          '<div class="aib-row"><b>Ausschlüsse</b><span class="aib-muted">relativ zum Appdata-Ordner (gilt auf allen Pools) · <code>*.log</code> überall · <code>/mnt/…</code> absolut</span></div>' +
          '<div class="aib-exlist"></div>' +
          '<div class="aib-row"><input type="text" class="aib-exadd" placeholder="z. B. cache oder logs/old" style="width:260px"><input type="button" class="aib-exaddbtn" value="Hinzufügen">' +
          '<select class="aib-tpl">' + tpl + '</select></div>' +
          '<div class="aib-row"><b>Ordner durchsuchen:</b> <span class="aib-crumb aib-mono"></span></div><div class="aib-browser"></div>' +
          '<div class="aib-row"><b>Zusätzliche Pfade</b> <span class="aib-muted">(absolut, einer pro Zeile – z. B. Daten außerhalb von appdata)</span></div>' +
          '<textarea class="aib-extra aib-mono" rows="2" style="width:100%;max-width:700px"></textarea>' +
          '<div class="aib-row"><b>Notiz</b> <input type="text" class="aib-note" maxlength="200" style="width:420px"></div>');
        $d.data('built', true).data('excludes', c.excludes.slice());
        $d.find('.aib-extra').val(c.extra_paths.join('\n'));
        $d.find('.aib-note').val(c.note);
        $d.on('click', '.aib-exaddbtn', function () { var v = $.trim($d.find('.aib-exadd').val()); if (v) { Cts.addEx(n, [v]); $d.find('.aib-exadd').val(''); } })
          .on('keydown', '.aib-exadd', function (e) { if (e.key === 'Enter') { e.preventDefault(); $d.find('.aib-exaddbtn').click(); } })
          .on('change', '.aib-tpl', function () { var k = $(this).val(); if (k) { Cts.addEx(n, Cts.data.templates[k].excludes); $(this).val(''); } })
          .on('click', '.aib-exdel', function () { var ex = $d.data('excludes'); ex.splice($(this).data('i'), 1); Cts.renderEx(n); })
          .on('click', '.aib-bdir', function () { Cts.browse(n, $(this).data('rel')); })
          .on('click', '.aib-bex', function () { Cts.addEx(n, [$(this).data('rel')]); });
        Cts.renderEx(n);
        Cts.browse(n, '');
      }
      $d.show();
    },
    addEx: function (n, list) {
      var $d = Cts.detailRow(n), ex = $d.data('excludes');
      $.each(list, function (_, v) { if (ex.indexOf(v) < 0) ex.push(v); });
      Cts.renderEx(n); Cts.dirty(true);
    },
    renderEx: function (n) {
      var $d = Cts.detailRow(n), ex = $d.data('excludes');
      $d.find('.aib-exlist').html(ex.length ? $.map(ex, function (e, i) { return '<span class="aib-chip">' + A.esc(e) + '<a class="aib-exdel" data-i="' + i + '" title="entfernen">✕</a></span>'; }).join('') : '<span class="aib-muted">keine</span>');
      Cts.mainRow(n).find('.aib-exsum').html(ex.length ? $.map(ex, function (e) { return '<span class="aib-chip">' + A.esc(e) + '</span>'; }).join('') : '<span class="aib-muted">–</span>');
    },
    browse: function (n, rel) {
      var $d = Cts.detailRow(n), $b = $d.find('.aib-browser');
      $b.html('<i class="fa fa-spinner fa-spin"></i>');
      A.api('browse', { container: n, rel: rel }).then(function (r) {
        var h = '';
        if (r.rel) { var up = r.rel.split('/').slice(0, -1).join('/'); h += '<div><a class="aib-bdir" data-rel="' + A.esc(up) + '"><i class="fa fa-level-up"></i> ..</a></div>'; }
        $.each(r.entries, function (_, e) {
          var p = (r.rel ? r.rel + '/' : '') + e.name;
          h += '<div>' + (e.dir ? '<a class="aib-bdir" data-rel="' + A.esc(p) + '"><i class="fa fa-folder-o"></i> ' + A.esc(e.name) + '</a>' : '<i class="fa fa-file-o"></i> ' + A.esc(e.name)) +
            ' &nbsp;<a class="aib-bex" data-rel="' + A.esc(p) + '" title="ausschließen"><i class="fa fa-ban"></i> ausschließen</a></div>';
        });
        $b.html(h || '<span class="aib-muted">leer</span>');
        $d.find('.aib-crumb').text('/' + r.rel);
      }, function (e) { $b.html('<span class="aib-muted">' + A.esc(e) + '</span>'); });
    },
    save: function () {
      var out = {};
      $('#aib-ct-body tr.aib-main').each(function () {
        var $r = $(this), n = $r.data('name'), c = Cts.find(n), $d = Cts.detailRow(n);
        out[n] = {
          enabled: $r.find('.aib-en').prop('checked'), stop: $r.find('.aib-stop').prop('checked'),
          group: $.trim($r.find('.aib-group').val()), order: parseInt($r.find('.aib-order').val() || '100', 10),
          excludes: $d.data('built') ? $d.data('excludes') : c.excludes,
          extra_paths: $d.data('built') ? A.lines($d.find('.aib-extra').val()) : c.extra_paths,
          note: $d.data('built') ? $d.find('.aib-note').val() : c.note
        };
      });
      A.busy('#aib-ct-save', true);
      A.api('save_containers', { containers: out }).then(function (r) {
        A.msg('#aib-ct-msg', r.saved + ' Container gespeichert.', 'ok'); Cts.load(true);
      }, function (e) { A.msg('#aib-ct-msg', e, 'err'); }).always(function () { A.busy('#aib-ct-save', false); });
    }
  };

  // ------------------------------------------------------------------ target
  var Tgt = {
    s: null,
    init: function () {
      if (!$('#aib-target').length) return;
      $('#aib-t-type').on('change', Tgt.showType);
      $('#aib-t-save').on('click', Tgt.save);
      $('#aib-k-gen').on('click', function () {
        var again = Tgt.s && Tgt.s.key.exists;
        var go = function () { A.api('key_generate', { force: again ? 1 : 0 }).then(function () { A.msg('#aib-k-msg', 'Schlüssel erzeugt. Jetzt auf dem Server hinterlegen.', 'ok'); Tgt.load(); }, function (e) { A.msg('#aib-k-msg', e, 'err'); }); };
        if (again) A.confirm('Neuen Schlüssel erzeugen?', 'Der bisherige Schlüssel wird ersetzt und muss danach neu auf dem Server hinterlegt werden.', 'Ersetzen', go, true); else go();
      });
      $('#aib-k-copy').on('click', function () { A.copy($('#aib-k-pub').val(), this); });
      $('#aib-k-install').on('click', function () {
        var pw = $('#aib-k-pw').val();
        if (!pw) { A.msg('#aib-k-msg', 'Bitte das Passwort des Zielkontos eingeben.', 'err'); return; }
        A.busy(this, true); A.msg('#aib-k-msg', 'Verbinde …', 'info');
        A.api('key_install', { password: pw, mode: $('#aib-k-mode').val() }).then(function (r) { A.msg('#aib-k-msg', 'Schlüssel hinterlegt. ' + r.message, 'ok'); },
          function (e) { A.msg('#aib-k-msg', e, 'err'); }).always(function () { $('#aib-k-pw').val(''); A.busy('#aib-k-install', false); });
      });
      $('#aib-p-gen').on('click', function () {
        var again = Tgt.s && Tgt.s.password.set;
        var go = function () {
          A.api('password_generate', { force: again ? 1 : 0 }).then(function (r) { Tgt.showOnce(r.password); Tgt.load(); }, function (e) { A.msg('#aib-p-msg', e, 'err'); });
        };
        if (again) A.confirm('Passwort ersetzen?', 'ACHTUNG: Ein bereits angelegtes Repository ist mit dem neuen Passwort NICHT mehr lesbar. Nur ersetzen, wenn noch kein Repository existiert.', 'Trotzdem ersetzen', go, true); else go();
      });
      $('#aib-p-own').on('click', function () { $('#aib-p-ownbox').toggle(); });
      $('#aib-p-set').on('click', function () {
        var a = $('#aib-p-1').val(), b = $('#aib-p-2').val();
        if (a !== b) { A.msg('#aib-p-msg', 'Die Passwörter stimmen nicht überein.', 'err'); return; }
        var again = Tgt.s && Tgt.s.password.set;
        var go = function () {
          A.api('password_set', { password: a, force: again ? 1 : 0 }).then(function () { A.msg('#aib-p-msg', 'Passwort gespeichert. Bitte auch im Passwortmanager sichern.', 'ok'); $('#aib-p-1,#aib-p-2').val(''); $('#aib-p-ownbox').hide(); Tgt.load(); },
            function (e) { A.msg('#aib-p-msg', e, 'err'); });
        };
        if (again) A.confirm('Passwort ersetzen?', 'ACHTUNG: Ein bereits angelegtes Repository ist mit einem anderen Passwort NICHT mehr lesbar.', 'Trotzdem ersetzen', go, true); else go();
      });
      $('#aib-once-copy').on('click', function () { A.copy($('#aib-once-pw').text(), this); });
      $('#aib-once-dl').on('click', function () {
        A.download('appdata-incremental-repo-passwort.txt', 'Appdata Incremental – Repository-Passwort\n\n' + $('#aib-once-pw').text() +
          '\n\nRepository: ' + (Tgt.s ? Tgt.s.repository_effective : '') + '\nIm Passwortmanager speichern und diese Datei danach löschen.\nOhne dieses Passwort sind die Backups nicht lesbar.\n');
      });
      $('#aib-once-done').on('change', function () { if (this.checked) { $('#aib-once-pw').text(''); $('#aib-once').hide(); this.checked = false; } });
      $('#aib-c-test').on('click', function () {
        A.busy(this, true); A.msg('#aib-c-msg', 'Teste …', 'info');
        A.api('test_connection').then(function (r) { A.msg('#aib-c-msg', r.message, r.ok ? (r.initialized ? 'ok' : 'info') : 'err'); $('#aib-c-init').prop('disabled', !(r.ok && !r.initialized)); },
          function (e) { A.msg('#aib-c-msg', e, 'err'); }).always(function () { A.busy('#aib-c-test', false); });
      });
      $('#aib-c-init').on('click', function () {
        A.confirm('Repository initialisieren', 'Am Ziel ein neues, verschlüsseltes restic-Repository anlegen?', 'Anlegen', function () {
          A.msg('#aib-c-msg', 'Lege an …', 'info');
          A.api('init_repo').then(function (r) { A.msg('#aib-c-msg', r.message + ' Die Einrichtung ist fertig – jetzt Container prüfen und den Zeitplan setzen.', 'ok'); $('#aib-c-init').prop('disabled', true); },
            function (e) { A.msg('#aib-c-msg', e, 'err'); });
        });
      });
      Tgt.load();
    },
    showOnce: function (pw) {
      $('#aib-once-pw').text(pw); $('#aib-once').show();
      $('html,body').animate({ scrollTop: $('#aib-once').offset().top - 80 }, 200);
    },
    showType: function () {
      var t = $('#aib-t-type').val();
      $('.aib-t-sftp').toggle(t === 'sftp'); $('.aib-t-local').toggle(t === 'local'); $('.aib-t-custom').toggle(t === 'custom');
      $('#aib-keybox').toggle(t === 'sftp');
    },
    load: function () {
      A.api('settings').then(function (s) {
        Tgt.s = s; var t = s.target || {};
        $('#aib-t-type').val(t.type || (s.repository ? 'custom' : 'sftp'));
        $('#aib-t-host').val(t.host || ''); $('#aib-t-user').val(t.user || ''); $('#aib-t-port').val(t.port || 23); $('#aib-t-path').val(t.path || 'restic-appdata');
        $('#aib-t-local').val(t.local_path || ''); $('#aib-t-custom').val(t.custom || (t.type ? '' : s.repository || ''));
        $('#aib-t-eff').text(s.repository_effective || '– noch nicht eingerichtet –');
        Tgt.showType();
        var k = s.key;
        $('#aib-k-state').html(k.exists ? '<span class="aib-badge green">vorhanden</span> <span class="aib-mono aib-muted">' + A.esc(k.fingerprint) + '</span>' : '<span class="aib-badge red">fehlt</span>');
        $('#aib-k-pub').val(k.public || ''); $('#aib-k-pubrow').toggle(!!k.public); $('#aib-k-gen').val(k.exists ? 'Neuen Schlüssel erzeugen' : 'Schlüssel erzeugen');
        $('#aib-k-installrow').toggle(k.exists);
        var p = s.password;
        $('#aib-p-state').html(p.set ? '<span class="aib-badge green">gesetzt</span> <span class="aib-mono">' + '•'.repeat(Math.min(p.length, 24)) + '</span>' : '<span class="aib-badge red">fehlt</span>');
        $('#aib-p-gen').val(p.set ? 'Neues Passwort erzeugen' : 'Passwort erzeugen');
        Tgt.steps(s);
      }, function (e) { A.msg('#aib-t-msg', e, 'err'); });
    },
    steps: function (s) {
      var sftp = (s.target || {}).type === 'sftp';
      var st = [[!!s.repository_effective, 'Ziel gespeichert']];
      if (sftp) st.push([s.key.exists, 'SSH-Schlüssel erzeugt']);
      st.push([s.password.set, 'Repository-Passwort gesetzt (und im Passwortmanager gesichert)']);
      if (sftp) st.push([null, 'Schlüssel auf dem Server hinterlegt (Abschnitt 2)']);
      st.push([null, 'Verbindung getestet und Repository initialisiert (Abschnitt 4)']);
      $('#aib-steps').html($.map(st, function (x) {
        var i = x[0] === true ? 'fa-check-square-o' : (x[0] === false ? 'fa-square-o' : 'fa-hand-o-right');
        return '<li><i class="fa ' + i + '"></i> ' + A.esc(x[1]) + '</li>';
      }).join(''));
    },
    save: function () {
      var t = { type: $('#aib-t-type').val(), host: $.trim($('#aib-t-host').val()), user: $.trim($('#aib-t-user').val()), port: $('#aib-t-port').val(),
        path: $.trim($('#aib-t-path').val()), local_path: $.trim($('#aib-t-local').val()), custom: $.trim($('#aib-t-custom').val()) };
      A.api('save_target', { target: t }).then(function (r) { A.msg('#aib-t-msg', 'Gespeichert: ' + r.repository, 'ok'); Tgt.load(); }, function (e) { A.msg('#aib-t-msg', e, 'err'); });
    }
  };

  // ------------------------------------------------------------------ schedule & rules
  var Sch = {
    s: null,
    init: function () {
      if (!$('#aib-schedule').length) return;
      var h = '', m = '';
      for (var i = 0; i < 24; i++) h += '<option value="' + i + '">' + (i < 10 ? '0' : '') + i + '</option>';
      for (i = 0; i < 60; i += 5) m += '<option value="' + i + '">' + (i < 10 ? '0' : '') + i + '</option>';
      $('#aib-s-hour').html(h); $('#aib-s-minute').html(m);
      var md = ''; for (i = 1; i <= 28; i++) md += '<option value="' + i + '">' + i + '.</option>';
      $('#aib-s-monthday').html(md);
      var cd = '<option value="0">nie automatisch</option>'; for (i = 1; i <= 28; i++) cd += '<option value="' + i + '">am ' + i + '. des Monats</option>';
      $('#aib-s-checkday').html(cd);
      $('#aib-s-freq').on('change', Sch.showFreq);
      $('#aib-s-save').on('click', Sch.save);
      $('#aib-s-notifytest').on('click', function () { A.api('notify_test').then(function () { A.msg('#aib-s-msg', 'Testbenachrichtigung gesendet.', 'ok'); }, function (e) { A.msg('#aib-s-msg', e, 'err'); }); });
      $('#aib-r-add').on('click', function () { Sch.addRule({ match_path: '', excludes: [], disable: false, note: '' }); });
      $('#aib-rules').on('click', '.aib-rdel', function () { $(this).closest('tr').remove(); })
        .on('change', '.aib-rtpl', function () {
          var k = $(this).val(); if (!k || !Sch.s) return;
          var $x = $(this).closest('tr').find('.aib-rex'); var cur = A.lines($x.val().replace(/,/g, '\n'));
          $.each(Sch.s.templates[k].excludes, function (_, e) { if (cur.indexOf(e) < 0) cur.push(e); });
          $x.val(cur.join(', ')); $(this).val('');
        })
        .on('change', '.aib-rdis', function () { $(this).closest('tr').find('.aib-rex,.aib-rtpl,.aib-rstop').prop('disabled', this.checked); });
      $('#aib-i-file').on('change', function () {
        var f = this.files[0]; if (!f) return;
        var r = new FileReader(); r.onload = function () { $('#aib-i-json').val(r.result); }; r.readAsText(f);
      });
      $('#aib-i-go').on('click', function () {
        var j = $.trim($('#aib-i-json').val()); if (!j) { A.msg('#aib-i-msg', 'Erst eine Datei wählen oder JSON einfügen.', 'err'); return; }
        A.confirm('Konfiguration importieren', 'Die enthaltenen Einstellungen überschreiben die aktuellen. Schlüssel und Passwort werden nicht verändert.', 'Importieren', function () {
          A.api('import_config', { json: j }).then(function (r) {
            A.msg('#aib-i-msg', 'Importiert: ' + r.imported.join(', ') + (r.ignored.length ? ' · ignoriert: ' + r.ignored.join(', ') : '') + (r.new_containers.length ? ' · neu eingetragen: ' + r.new_containers.length + ' Container' : ''), 'ok');
            $('#aib-i-json').val(''); Sch.load(); A.containers = null; if (Cts.data) Cts.load(true); if (Tgt.s) Tgt.load();
          }, function (e) { A.msg('#aib-i-msg', e, 'err'); });
        });
      });
      $('#aib-i-export').on('click', function () { A.api('export_config').then(function (r) { A.download('appdata-incremental-config.json', r.json, 'application/json'); }); });
      Sch.load();
    },
    showFreq: function () {
      var f = $('#aib-s-freq').val();
      $('.aib-s-wd').toggle(f === 'weekly'); $('.aib-s-md').toggle(f === 'monthly');
      $('.aib-s-time').toggle(f !== 'custom'); $('.aib-s-hourpart').toggle(f !== 'hourly'); $('.aib-s-custom').toggle(f === 'custom');
    },
    addRule: function (r) {
      var tpl = '<option value="">+ Vorlage …</option>';
      $.each(Sch.s ? Sch.s.templates : {}, function (k, t) { tpl += '<option value="' + A.esc(k) + '">' + A.esc(t.label) + '</option>'; });
      var $tr = $('<tr>' +
        '<td><input type="text" class="aib-rpat aib-mono" style="width:240px" placeholder="*/appdata/beispiel"></td>' +
        '<td><input type="checkbox" class="aib-rdis"> nicht sichern</td>' +
        '<td><input type="text" class="aib-rex" style="width:260px" placeholder="cache, logs"> <select class="aib-rtpl">' + tpl + '</select></td>' +
        '<td><input type="checkbox" class="aib-rstop" checked> stoppen</td>' +
        '<td><input type="text" class="aib-rnote" style="width:200px"></td>' +
        '<td><a class="aib-rdel" style="cursor:pointer" title="Regel löschen"><i class="fa fa-trash"></i></a></td></tr>');
      $tr.find('.aib-rpat').val(r.match_path); $tr.find('.aib-rex').val((r.excludes || []).join(', ')); $tr.find('.aib-rnote').val(r.note || '');
      $tr.find('.aib-rdis').prop('checked', !!r.disable); $tr.find('.aib-rstop').prop('checked', r.stop !== false);
      $tr.find('.aib-rex,.aib-rtpl,.aib-rstop').prop('disabled', !!r.disable);
      $('#aib-rules').append($tr);
    },
    load: function () {
      A.api('settings').then(function (s) {
        Sch.s = s; var sc = s.schedule, r = s.retention, n = s.notify;
        $('#aib-s-enabled').val(sc.enabled ? '1' : '0'); $('#aib-s-freq').val(sc.frequency || 'daily');
        $('#aib-s-hour').val(sc.hour); $('#aib-s-minute').val(sc.minute - sc.minute % 5); $('#aib-s-weekday').val(sc.weekday); $('#aib-s-monthday').val(sc.monthday);
        $('#aib-s-custom').val(sc.backup); $('#aib-s-cron').text(sc.backup + (s.schedule_active ? ' (aktiv)' : ' (aus)'));
        $('#aib-s-prune').val(sc.prune_weekday); $('#aib-s-checkday').val(sc.check_day); $('#aib-s-subset').val(sc.check_subset);
        $.each(['keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly', 'keep_last'], function (_, k) { $('#aib-r-' + k).val(r[k] || 0); });
        $('#aib-r-keep_within').val(r.keep_within || '');
        $('#aib-n-err').val(n.on_error ? '1' : '0'); $('#aib-n-warn').val(n.on_warning ? '1' : '0'); $('#aib-n-ok').val(n.on_success ? '1' : '0');
        $('#aib-o-snap').val(s.snapshot.mode); $('#aib-o-fail').val(s.snapshot.on_failure); $('#aib-o-timeout').val(s.stop_timeout); $('#aib-o-comp').val(s.compression);
        $('#aib-o-newen').val(s.new_containers.enabled ? '1' : '0'); $('#aib-o-newstop').val(s.new_containers.stop ? '1' : '0');
        $('#aib-o-gex').val((s.global_excludes || []).join('\n'));
        $('#aib-rules').empty();
        $.each(s.presets || [], function (_, p) { Sch.addRule(p); });
        $.each(s.auto_disable_mount_patterns || [], function (_, p) { Sch.addRule({ match_path: p, disable: true }); });
        Sch.showFreq();
      }, function (e) { A.msg('#aib-s-msg', e, 'err'); });
    },
    save: function () {
      var rules = [];
      $('#aib-rules tr').each(function () {
        var $r = $(this);
        rules.push({ match_path: $.trim($r.find('.aib-rpat').val()), disable: $r.find('.aib-rdis').prop('checked'),
          excludes: A.lines($r.find('.aib-rex').val().replace(/,/g, '\n')), stop: $r.find('.aib-rstop').prop('checked'), note: $r.find('.aib-rnote').val() });
      });
      var set = {
        schedule: { enabled: $('#aib-s-enabled').val() === '1', frequency: $('#aib-s-freq').val(), hour: $('#aib-s-hour').val(), minute: $('#aib-s-minute').val(),
          weekday: $('#aib-s-weekday').val(), monthday: $('#aib-s-monthday').val(), custom: $('#aib-s-custom').val(),
          prune_weekday: $('#aib-s-prune').val(), check_day: $('#aib-s-checkday').val(), check_subset: $.trim($('#aib-s-subset').val()) },
        retention: { keep_daily: $('#aib-r-keep_daily').val(), keep_weekly: $('#aib-r-keep_weekly').val(), keep_monthly: $('#aib-r-keep_monthly').val(),
          keep_yearly: $('#aib-r-keep_yearly').val(), keep_last: $('#aib-r-keep_last').val(), keep_within: $.trim($('#aib-r-keep_within').val()) },
        notify: { on_error: $('#aib-n-err').val() === '1', on_warning: $('#aib-n-warn').val() === '1', on_success: $('#aib-n-ok').val() === '1' },
        snapshot: { mode: $('#aib-o-snap').val(), on_failure: $('#aib-o-fail').val() }, stop_timeout: $('#aib-o-timeout').val(), compression: $('#aib-o-comp').val(),
        new_containers: { enabled: $('#aib-o-newen').val() === '1', stop: $('#aib-o-newstop').val() === '1' },
        global_excludes: A.lines($('#aib-o-gex').val()), rules: rules
      };
      A.busy('#aib-s-save', true);
      A.api('save_settings', { settings: set }).then(function (r) { A.msg('#aib-s-msg', 'Gespeichert. ' + r.schedule, 'ok'); Sch.load(); },
        function (e) { A.msg('#aib-s-msg', e, 'err'); }).always(function () { A.busy('#aib-s-save', false); });
    }
  };

  // ------------------------------------------------------------------ history
  var Hist = {
    list: [], dirty: false,
    init: function () {
      if (!$('#aib-history').length) return;
      A.History = Hist;
      $('#aib-h-refresh').on('click', function () { Hist.load(true); });
      $('#aib-h-ct').on('change', Hist.render);
      $('#aib-h-body').on('click', '.aib-hdet', function () { Hist.details($(this).closest('tr').data('id')); })
        .on('click', '.aib-hres', function () { var $r = $(this).closest('tr'); A.Restore.preselect($r.data('ct'), $r.data('id')); A.gotoTab('Wiederherstellen'); });
      $(document).on('click', '#aib-tabs-history-load', function () { Hist.load(false); });
      Hist.load(false);
    },
    load: function (refresh) {
      A.msg('#aib-h-msg', '');
      $('#aib-h-body').html('<tr><td colspan="8"><i class="fa fa-spinner fa-spin"></i> Lade Versionen …</td></tr>');
      A.api('snapshots', { refresh: refresh ? 1 : 0 }).then(function (d) {
        Hist.list = d.snapshots; Hist.dirty = false;
        var cts = {}; $.each(Hist.list, function (_, s) { if (s.container) cts[s.container] = 1; });
        var cur = $('#aib-h-ct').val(), o = '<option value="">alle Container</option>';
        $.each(Object.keys(cts).sort(function (a, b) { return a.toLowerCase() < b.toLowerCase() ? -1 : 1; }), function (_, n) { o += '<option value="' + A.esc(n) + '">' + A.esc(n) + '</option>'; });
        $('#aib-h-ct').html(o).val(cur || '');
        Hist.render();
        if (A.Restore) A.Restore.snapsLoaded(Hist.list);
      }, function (e) { $('#aib-h-body').html(''); A.msg('#aib-h-msg', e, 'err'); });
    },
    render: function () {
      var ct = $('#aib-h-ct').val(), h = '', n = 0;
      $.each(Hist.list, function (_, s) {
        if (ct && s.container !== ct) return;
        n++;
        h += '<tr data-id="' + A.esc(s.id) + '" data-ct="' + A.esc(s.container) + '"><td>' + A.date(s.time) + '</td><td>' + A.esc(s.container || '–') + '</td><td>' + A.human(s.total_bytes) +
          '</td><td>' + A.human(s.data_added) + '</td><td>' + (s.files_new === null ? '–' : s.files_new + ' / ' + s.files_changed) + '</td><td>' + A.dur(s.duration) +
          '</td><td class="aib-mono">' + A.esc(s.short_id) + '</td><td style="white-space:nowrap"><input type="button" class="aib-hdet" value="Details"> <input type="button" class="aib-hres" value="Wiederherstellen …"></td></tr>' +
          '<tr class="aib-detail aib-hidden" data-for="' + A.esc(s.id) + '"><td colspan="8"></td></tr>';
      });
      $('#aib-h-body').html(h || '<tr><td colspan="8">Noch keine Versionen.</td></tr>');
      $('#aib-h-count').text(n + ' Versionen');
    },
    details: function (id) {
      var $d = $('#aib-h-body tr[data-for="' + id + '"]');
      if (!$d.hasClass('aib-hidden')) { $d.addClass('aib-hidden'); return; }
      var s = null, prev = null;
      $.each(Hist.list, function (i, x) { if (x.id === id) { s = x; for (var j = i + 1; j < Hist.list.length; j++) if (Hist.list[j].container === x.container) { prev = Hist.list[j]; break; } } });
      var h = '<div><b>Version</b> <span class="aib-mono">' + A.esc(s.id) + '</span></div><div><b>Pfade:</b><div class="aib-mono">' + $.map(s.paths, A.esc).join('<br>') + '</div></div>' +
        '<div class="aib-row">Dateien gesamt: ' + (s.total_files === null ? '–' : s.total_files) + ' · neu: ' + (s.files_new === null ? '–' : s.files_new) + ' · geändert: ' + (s.files_changed === null ? '–' : s.files_changed) +
        ' · unverändert: ' + (s.files_unmodified === null ? '–' : s.files_unmodified) + '</div>' +
        (prev ? '<div class="aib-diff"><i class="fa fa-spinner fa-spin"></i> Vergleiche mit Version vom ' + A.date(prev.time) + ' …</div>' : '<div class="aib-muted">Erste Version dieses Containers.</div>');
      $d.children('td').html(h); $d.removeClass('aib-hidden');
      if (prev) A.api('diff', { a: prev.id, b: s.id }).then(function (r) {
        var add = (r.stats || {}).added || {};
        var x = '<div><b>Änderungen gegenüber ' + A.date(prev.time) + ':</b> ' + (r.total ? r.modified + ' geändert, ' + r.added + ' neu, ' + r.removed + ' gelöscht · ' + A.human(add.bytes) + ' neue Daten' : 'keine Datendateien geändert (nur Container-Konfiguration)') + '</div>';
        var sym = { '+': 'neu', '-': 'gelöscht', 'M': 'geändert', 'T': 'Typ geändert', 'U': 'Metadaten' };
        if (r.changes.length) x += '<div class="aib-log" style="max-height:220px">' + $.map(r.changes, function (c) { return A.esc((sym[c.modifier] || c.modifier) + '  ' + c.path); }).join('\n') + (r.total > r.changes.length ? '\n… und ' + (r.total - r.changes.length) + ' weitere' : '') + '</div>';
        $d.find('.aib-diff').html(x);
      }, function (e) { $d.find('.aib-diff').text(e); });
    }
  };

  // ------------------------------------------------------------------ restore
  var Res = {
    snaps: [], pre: null,
    init: function () {
      if (!$('#aib-restore').length) return;
      A.Restore = Res;
      $('#aib-x-ct').on('change', Res.fillVersions);
      $('#aib-x-ver').on('change', Res.resetTree);
      $('input[name=aib-x-mode]').on('change', Res.mode);
      $('input[name=aib-x-target]').on('change', Res.mode);
      $('#aib-x-tree').on('click', '.aib-tg', function () { Res.expand($(this).closest('li')); })
        .on('change', 'input.aib-tc', function () { $(this).closest('li').find('ul input.aib-tc').prop('checked', this.checked).prop('disabled', this.checked); Res.count(); });
      $('#aib-x-go').on('click', Res.go);
      A.loadContainers(false).then(function () { if (!Hist.list.length) A.api('snapshots').then(function (d) { Res.snapsLoaded(d.snapshots); }, function (e) { A.msg('#aib-x-msg', e, 'err'); }); });
      Res.mode();
    },
    snapsLoaded: function (list) {
      Res.snaps = list;
      var cts = {}; $.each(list, function (_, s) { if (s.container) cts[s.container] = 1; });
      var cur = Res.pre ? Res.pre.ct : $('#aib-x-ct').val(), o = '<option value="">– Container wählen –</option>';
      $.each(Object.keys(cts).sort(function (a, b) { return a.toLowerCase() < b.toLowerCase() ? -1 : 1; }), function (_, n) { o += '<option value="' + A.esc(n) + '">' + A.esc(n) + '</option>'; });
      $('#aib-x-ct').html(o).val(cur || ''); Res.fillVersions();
    },
    preselect: function (ct, id) { Res.pre = { ct: ct, id: id }; if (Res.snaps.length) Res.snapsLoaded(Res.snaps); },
    fillVersions: function () {
      var ct = $('#aib-x-ct').val(), o = '';
      $.each(Res.snaps, function (_, s) { if (s.container === ct) o += '<option value="' + A.esc(s.id) + '">' + A.date(s.time) + ' – ' + A.human(s.total_bytes) + ' (' + A.esc(s.short_id) + ')</option>'; });
      $('#aib-x-ver').html(o || '<option value="">keine Versionen</option>');
      if (Res.pre && Res.pre.ct === ct) { $('#aib-x-ver').val(Res.pre.id); Res.pre = null; }
      $('#aib-x-folder').val('/mnt/user/restore/' + (ct || 'container'));
      Res.resetTree();
    },
    snap: function () { var id = $('#aib-x-ver').val(), r = null; $.each(Res.snaps, function (_, s) { if (s.id === id) r = s; }); return r; },
    mode: function () {
      var files = $('input[name=aib-x-mode]:checked').val() === 'files', folder = $('input[name=aib-x-target]:checked').val() === 'folder';
      $('#aib-x-treebox').toggle(files); $('#aib-x-folderrow').toggle(folder);
      $('#aib-x-stoprow').toggle(files && !folder); $('#aib-x-deprow').toggle(!files && !folder);
      if (files && !$('#aib-x-tree').children().length) Res.resetTree();
    },
    resetTree: function () {
      var s = Res.snap(), $t = $('#aib-x-tree');
      if (!s) { $t.html('<span class="aib-muted">Erst Container und Version wählen.</span>'); return; }
      var h = '<ul>';
      $.each(s.paths, function (_, p) { h += Res.node({ name: p, path: p, type: 'dir' }); });
      $t.html(h + '</ul>'); Res.count();
    },
    node: function (e) {
      var dir = e.type === 'dir';
      return '<li data-path="' + A.esc(e.path) + '" data-loaded="0">' + (dir ? '<span class="aib-tg"><i class="fa fa-caret-right"></i></span>' : '<span class="aib-tg"></span>') +
        '<label><input type="checkbox" class="aib-tc"> <i class="fa ' + (dir ? 'fa-folder-o' : 'fa-file-o') + '"></i> ' + A.esc(e.name) + '</label>' +
        (dir ? '' : '<span class="aib-size">' + A.human(e.size) + '</span>') + '</li>';
    },
    expand: function ($li) {
      var $tg = $li.children('.aib-tg'); if (!$tg.children().length) return;
      var $ul = $li.children('ul');
      if ($ul.length) { $ul.toggle(); $tg.find('i').toggleClass('fa-caret-right fa-caret-down'); return; }
      $tg.find('i').removeClass('fa-caret-right').addClass('fa-spinner fa-spin');
      A.api('ls', { id: Res.snap().id, path: $li.data('path') }).then(function (r) {
        var checked = $li.find('> label input').prop('checked');
        var h = '<ul>' + $.map(r.entries, Res.node).join('') + (r.entries.length ? '' : '<li class="aib-muted">leer</li>') + '</ul>';
        $li.append(h); if (checked) $li.find('ul input.aib-tc').prop('checked', true).prop('disabled', true);
        $tg.find('i').removeClass('fa-spinner fa-spin').addClass('fa-caret-down');
      }, function (e) { $tg.find('i').removeClass('fa-spinner fa-spin').addClass('fa-caret-right'); A.msg('#aib-x-msg', e, 'err'); });
    },
    selected: function () {
      var out = [];
      $('#aib-x-tree input.aib-tc:checked:not(:disabled)').each(function () { out.push($(this).closest('li').data('path')); });
      return out;
    },
    count: function () { var n = Res.selected().length; $('#aib-x-count').text(n ? n + ' ausgewählt' : 'nichts ausgewählt'); },
    go: function () {
      var s = Res.snap(); if (!s) { A.msg('#aib-x-msg', 'Container und Version wählen.', 'err'); return; }
      var ct = $('#aib-x-ct').val(), mode = $('input[name=aib-x-mode]:checked').val(), target = $('input[name=aib-x-target]:checked').val();
      var d = { container: ct, snapshot: s.id, mode: mode, target: target, folder: $.trim($('#aib-x-folder').val()),
        stop_container: $('#aib-x-stop').prop('checked') ? 1 : 0, stop_dependents: $('#aib-x-dep').prop('checked') ? 1 : 0 };
      if (mode === 'files') { d.paths = Res.selected(); if (!d.paths.length) { A.msg('#aib-x-msg', 'Im Dateibaum Dateien oder Ordner ankreuzen.', 'err'); return; } }
      var what = mode === 'files' ? d.paths.length + ' Einträge' : 'den ganzen Container';
      var where = target === 'folder' ? 'in den Ordner ' + d.folder + ' (die Live-Daten bleiben unverändert)' :
        'AN ORT UND STELLE. Der aktuelle Stand wird nicht gelöscht, sondern nach „.aib-pre-restore-<Zeit>“ verschoben.' + (mode === 'container' || d.stop_container ? ' Der Container wird dafür gestoppt und danach wieder gestartet.' : '');
      A.confirm('Wiederherstellen?', ct + ': ' + what + ' aus der Version vom ' + A.date(s.time) + ' ' + where, 'Wiederherstellen', function () {
        A.api('restore', d).then(function () {
          A.msg('#aib-x-msg', 'Wiederherstellung gestartet – Fortschritt und Log im Tab „Status“. Ergebnis kommt als Unraid-Benachrichtigung.', 'ok');
          if (A.Status) A.Status.poll();
        }, function (e) { A.msg('#aib-x-msg', e, 'err'); });
      }, target === 'inplace');
    }
  };

  // ------------------------------------------------------------------ tools & logs
  var Tools = {
    init: function () {
      if (!$('#aib-tools').length) return;
      A.Tools = Tools;
      $('#aib-d-run').on('click', function () {
        A.busy(this, true); $('#aib-d-out').html('<i class="fa fa-spinner fa-spin"></i> Prüfe …');
        A.api('doctor').then(function (r) {
          var h = '<table class="tablesorter shift aib-table"><tbody>' + $.map(r.checks, function (c) {
            return '<tr><td style="width:60px"><span class="aib-badge ' + (c.ok ? 'green">OK' : 'red">FEHLT') + '</span></td><td>' + A.esc(c.what) + '</td><td class="aib-muted">' + A.esc(c.info) + '</td></tr>';
          }).join('') + '</tbody></table><div class="aib-row"><b>Plan des nächsten Laufs</b></div><div class="aib-log">' + A.esc(r.plan) + '</div>';
          $('#aib-d-out').html(h);
        }, function (e) { $('#aib-d-out').text(e); }).always(function () { A.busy('#aib-d-run', false); });
      });
      $('#aib-st-run').on('click', function () {
        var p = $('#aib-st-path').val(); if (!p) return;
        A.busy(this, true); $('#aib-st-out').text('Erstelle Snapshot …');
        A.api('selftest', { path: p }).then(function (r) { $('#aib-st-out').text((r.ok ? '✓ ' : '✗ ') + r.log.join('\n')); }, function (e) { $('#aib-st-out').text(e); })
          .always(function () { A.busy('#aib-st-run', false); });
      });
      $('.aib-maint').on('click', function () {
        var k = $(this).data('kind'), t = $(this).val();
        A.confirm(t, { forget: 'Aufbewahrungsregeln jetzt anwenden (alte Versionen werden zum Löschen markiert).', prune: 'Nicht mehr benötigte Daten im Repository löschen. Kann bei großen Repositories lange dauern.', check: 'Repository-Integrität prüfen (mit Stichprobe der Daten).', unlock: 'Verwaiste Sperren im Repository entfernen (nur wenn kein anderer Vorgang darauf zugreift).' }[k], 'Starten', function () {
          A.api('run', { kind: k }).then(function () { A.msg('#aib-m-msg', t + ' gestartet – Log im Tab „Status“.', 'ok'); if (A.Status) A.Status.poll(); }, function (e) { A.msg('#aib-m-msg', e, 'err'); });
        });
      });
      $('#aib-l-refresh').on('click', Tools.logs);
      $('#aib-l-body').on('click', '.aib-lview', function () {
        var n = $(this).closest('tr').data('name');
        A.api('log', { name: n }).then(function (r) { $('#aib-l-name').text(r.name + (r.truncated ? ' (gekürzt)' : '')); $('#aib-l-view').text(r.data).show(); $('#aib-l-dl').show().data('name', r.name).data('text', r.data); },
          function (e) { A.msg('#aib-l-msg', e, 'err'); });
      });
      $('#aib-l-dl').on('click', function () { A.download($(this).data('name'), $(this).data('text')); });
      A.loadContainers(false).then(function (d) {
        var o = '';
        $.each(d.containers, function (_, c) { $.each(c.paths, function (_, p) { o += '<option value="' + A.esc(p) + '">' + A.esc(c.name + ' – ' + p) + '</option>'; }); });
        $('#aib-st-path').html(o || '<option value="">keine Appdata-Pfade</option>');
      });
      Tools.logs();
    },
    logs: function () {
      A.api('logs').then(function (r) {
        var kinds = { backup: 'Backup', cron: 'Geplantes Backup', restore: 'Wiederherstellung', forget: 'Aufbewahrung', prune: 'Aufräumen', check: 'Prüfung', unlock: 'Sperren lösen' };
        $('#aib-l-body').html($.map(r.logs, function (l) {
          var k = l.name.split('-')[0];
          return '<tr data-name="' + A.esc(l.name) + '"><td>' + A.date(l.mtime * 1000) + '</td><td>' + A.esc(kinds[k] || k) + '</td><td>' + A.human(l.size) + '</td><td><input type="button" class="aib-lview" value="Anzeigen"></td></tr>';
        }).join('') || '<tr><td colspan="4">Noch keine Logs.</td></tr>');
      }, function (e) { A.msg('#aib-l-msg', e, 'err'); });
    }
  };

  // ------------------------------------------------------------------ setup wizard
  var Wiz = {
    s: null, cur: null, type: null, ok: {}, cts: null,
    labels: { welcome: 'Start', target: 'Ziel', login: 'Anmeldung', password: 'Passwort', repo: 'Verbindung', containers: 'Container', schedule: 'Zeitplan', finish: 'Fertig' },
    order: function () {
      var o = ['welcome', 'target'];
      if (Wiz.type === 'storagebox' || Wiz.type === 'sftp') o.push('login');
      return o.concat(['password', 'repo', 'containers', 'schedule', 'finish']);
    },
    init: function () {
      if (!$('#aib-wizard').length) return;
      A.Wizard = Wiz;
      var h = ''; for (var i = 0; i < 24; i++) h += '<option value="' + i + '">' + (i < 10 ? '0' : '') + i + ':00</option>';
      $('#aib-wz-hour').html(h);
      $('#aib-wz-next').on('click', Wiz.next);
      $('#aib-wz-back').on('click', Wiz.back);
      $('#aib-wz-restart').on('click', function () { Wiz.go('welcome'); });
      $('.aib-wz-tab').on('click', function () { A.gotoTab($(this).data('tab')); });
      $('input[name=aib-wz-type]').on('change', function () { Wiz.setType(this.value); });
      $('#aib-wz-sb-user').on('input', Wiz.sbHost);
      // login
      $('#aib-wz-install').on('click', Wiz.install);
      $('#aib-wz-pw').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); Wiz.install(); } });
      $('#aib-wz-manual').on('click', function (e) { e.preventDefault(); $('#aib-wz-manualbox').toggle(); });
      $('#aib-wz-pubcopy').on('click', function () { A.copy($('#aib-wz-pub').val(), this); });
      $('#aib-wz-manualdone').on('click', function () { Wiz.checkLogin(); });
      // password
      $('#aib-wz-pwgen').on('click', function () {
        A.api('password_generate', { force: 0 }).then(function (r) {
          $('#aib-wz-pwval').text(r.password); $('#aib-wz-pwonce').show(); $('#aib-wz-pw-new').hide(); $('#aib-wz-pwsavedrow').show(); Wiz.msg('');
          Wiz.load(false).then(Wiz.buttons);
        }, function (e) { Wiz.msg(e, 'err'); });
      });
      $('#aib-wz-pwown').on('click', function (e) { e.preventDefault(); $('#aib-wz-pwownbox').toggle(); });
      $('#aib-wz-pwset').on('click', function () {
        var a = $('#aib-wz-pw1').val(), b = $('#aib-wz-pw2').val();
        if (a !== b) { Wiz.msg('Die beiden Passwörter stimmen nicht überein.', 'err'); return; }
        A.api('password_set', { password: a, force: 0 }).then(function () {
          $('#aib-wz-pw1,#aib-wz-pw2').val(''); $('#aib-wz-pw-new').hide(); $('#aib-wz-pwsavedrow').show(); Wiz.msg('Passwort übernommen.', 'ok');
          Wiz.load(false).then(Wiz.buttons);
        }, function (e) { Wiz.msg(e, 'err'); });
      });
      $('#aib-wz-pwcopy').on('click', function () { A.copy($('#aib-wz-pwval').text(), this); });
      $('#aib-wz-pwdl').on('click', function () {
        A.download('appdata-incremental-backup-passwort.txt', 'Appdata Incremental – Backup-Passwort\n\n' + $('#aib-wz-pwval').text() +
          '\n\nZiel: ' + (Wiz.s ? Wiz.s.repository_effective : '') + '\nIm Passwortmanager speichern und diese Datei danach löschen.\nOhne dieses Passwort sind die Backups nicht lesbar.\n');
      });
      $('#aib-wz-pwsaved').on('change', Wiz.buttons);
      // repo
      $('#aib-wz-retest').on('click', Wiz.checkRepo);
      $('#aib-wz-init').on('click', function () {
        A.busy(this, true); $('#aib-wz-repo-state').html('<i class="fa fa-spinner fa-spin"></i> Lege Backup-Speicher an …');
        A.api('init_repo').then(function () { Wiz.checkRepo(); }, function (e) { $('#aib-wz-repo-state').text(''); Wiz.msg(e, 'err'); })
          .always(function () { A.busy('#aib-wz-init', false); });
      });
      // containers
      $('#aib-wz-ctall').on('click', function (e) { e.preventDefault(); $('#aib-wz-cts input').prop('checked', true).trigger('change'); });
      $('#aib-wz-ctnone').on('click', function (e) { e.preventDefault(); $('#aib-wz-cts input').prop('checked', false).trigger('change'); });
      $('#aib-wz-cts').on('change', 'input', function () { $(this).closest('label').toggleClass('off', !this.checked); });
      $('#aib-wz-import').on('click', function (e) { e.preventDefault(); $('#aib-wz-importbox').toggle(); });
      $('#aib-wz-importgo').on('click', function () {
        var f = $('#aib-wz-importfile')[0].files[0]; if (!f) { Wiz.msg('Erst eine Datei auswählen.', 'err'); return; }
        var r = new FileReader();
        r.onload = function () {
          A.api('import_config', { json: r.result }).then(function (d) {
            Wiz.msg('Vorlage übernommen (' + d.imported.join(', ') + ').', 'ok'); $('#aib-wz-importbox').hide(); A.containers = null; Wiz.loadCts();
          }, function (e) { Wiz.msg(e, 'err'); });
        };
        r.readAsText(f);
      });
      $('#aib-wz-freq').on('change', function () { $('#aib-wz-timebox').toggle(this.value !== 'off'); });
      // finish
      $('#aib-wz-selftest').on('click', Wiz.selftest);
      $('#aib-wz-first').on('click', function () {
        A.api('run', { kind: 'backup', containers: [] }).then(function () { A.gotoTab('Status'); if (A.Status) A.Status.poll(); }, function (e) { Wiz.msg(e, 'err'); });
      });
      Wiz.load(true);
    },
    load: function (first) {
      return A.api('settings').then(function (s) {
        Wiz.s = s;
        if (first) {
          var t = s.target || {};
          if (t.type === 'sftp' && /\.your-storagebox\.de$/.test(t.host || '') && +t.port === 23) { Wiz.setType('storagebox'); $('#aib-wz-sb-user').val(t.user); Wiz.sbHost(); }
          else if (t.type === 'sftp') { Wiz.setType('sftp'); $('#aib-wz-host').val(t.host); $('#aib-wz-user').val(t.user); $('#aib-wz-port').val(t.port); }
          else if (t.type === 'local') { Wiz.setType('local'); $('#aib-wz-local').val(t.local_path); }
          var sc = s.schedule || {};
          // new setup: recommend a daily backup; afterwards show what is configured
          $('#aib-wz-freq').val(!s.setup_completed ? 'daily' : (!sc.enabled ? 'off' : (sc.frequency === 'weekly' ? 'weekly' : 'daily'))).trigger('change');
          $('#aib-wz-hour').val(sc.hour === undefined ? 2 : sc.hour);
          var r = s.retention || {};
          $('#aib-wz-keep').val(r.keep_daily >= 14 ? 'more' : (r.keep_daily && r.keep_daily <= 3 ? 'less' : 'std'));
          $('#aib-wz-notify').val(s.notify && s.notify.on_error === false ? '0' : '1');
          Wiz.go(s.setup_completed ? 'done' : 'welcome');
        }
        return s;
      }, function (e) { Wiz.msg(e, 'err'); });
    },
    setType: function (t) {
      Wiz.type = t;
      $('input[name=aib-wz-type][value=' + t + ']').prop('checked', true);
      $('.aib-card').removeClass('sel'); $('input[name=aib-wz-type]:checked').closest('.aib-card').addClass('sel');
      $('.aib-wz-sub').removeClass('show').filter('[data-for=' + t + ']').addClass('show');
      Wiz.nav();
    },
    sbHost: function () {
      var u = $.trim($('#aib-wz-sb-user').val()), m = /^(u\d+)(-sub\d+)?$/.exec(u);
      $('#aib-wz-sb-host').text(m ? m[1] + '.your-storagebox.de (Port 23)' : '–');
    },
    msg: function (t, k) { A.msg('#aib-wz-msg', t, k); },
    nav: function () {
      var o = Wiz.order(), i = o.indexOf(Wiz.cur);
      $('#aib-wz-nav').html(Wiz.cur === 'done' ? '' : $.map(o, function (s, j) {
        return '<li class="' + (j < i ? 'done' : (j === i ? 'cur' : '')) + '">' + A.esc(Wiz.labels[s]) + '</li>';
      }).join(''));
    },
    go: function (step) {
      Wiz.cur = step; Wiz.msg('');
      $('.aib-wz-step').removeClass('cur').filter('[data-step=' + step + ']').addClass('cur');
      Wiz.nav(); Wiz.buttons();
      var enter = { login: Wiz.enterLogin, password: Wiz.enterPassword, repo: Wiz.checkRepo, containers: Wiz.loadCts, finish: Wiz.enterFinish, done: Wiz.enterDone }[step];
      if (enter) enter();
      window.scrollTo(0, 0);
    },
    buttons: function () {
      var c = Wiz.cur, o = Wiz.order(), i = o.indexOf(c);
      $('#aib-wz-back').css('visibility', i > 0 ? 'visible' : 'hidden');
      $('#aib-wz-next').toggle(c !== 'finish' && c !== 'done');
      $('.aib-wz-buttons').toggle(c !== 'done');
      var can = true;
      if (c === 'login') can = !!Wiz.ok.login;
      if (c === 'password') can = Wiz.s && Wiz.s.password.set && ($('#aib-wz-pw-exists').is(':visible') || $('#aib-wz-pwsaved').prop('checked'));
      if (c === 'repo') can = !!Wiz.ok.repo;
      $('#aib-wz-next').prop('disabled', !can).val(c === 'welcome' ? "Los geht's ›" : (c === 'schedule' ? 'Speichern ›' : 'Weiter ›'));
    },
    back: function () { var o = Wiz.order(), i = o.indexOf(Wiz.cur); if (i > 0) Wiz.go(o[i - 1]); },
    next: function () {
      var c = Wiz.cur, o = Wiz.order(), go = function () { Wiz.go(Wiz.order()[Wiz.order().indexOf(c) + 1]); };
      if (c === 'target') return Wiz.saveTarget().then(go);
      if (c === 'containers') return Wiz.saveCts().then(go);
      if (c === 'schedule') return Wiz.saveSchedule().then(go);
      go();
    },
    saveTarget: function () {
      var t;
      if (!Wiz.type) { Wiz.msg('Bitte ein Ziel auswählen.', 'err'); return $.Deferred().reject(); }
      if (Wiz.type === 'storagebox') {
        var u = $.trim($('#aib-wz-sb-user').val()), m = /^(u\d+)(-sub\d+)?$/.exec(u);
        if (!m) { Wiz.msg('Der Benutzername sieht so aus: u123456 oder u123456-sub1.', 'err'); return $.Deferred().reject(); }
        t = { type: 'sftp', host: m[1] + '.your-storagebox.de', user: u, port: 23, path: 'restic-appdata' };
      } else if (Wiz.type === 'sftp') {
        t = { type: 'sftp', host: $.trim($('#aib-wz-host').val()), user: $.trim($('#aib-wz-user').val()), port: $('#aib-wz-port').val(), path: 'restic-appdata' };
      } else {
        t = { type: 'local', local_path: $.trim($('#aib-wz-local').val()) };
      }
      var prev = JSON.stringify(Wiz.s && Wiz.s.target ? [Wiz.s.target.type, Wiz.s.target.host, Wiz.s.target.user, Wiz.s.target.local_path] : []);
      return A.api('save_target', { target: t }).then(function () {
        if (prev !== JSON.stringify([t.type, t.host, t.user, t.local_path])) { Wiz.ok.login = false; Wiz.ok.repo = false; }
        return Wiz.load(false);
      }, function (e) { Wiz.msg(e, 'err'); return $.Deferred().reject(); });
    },
    enterLogin: function () {
      var show = function (k) {
        $('#aib-wz-pub').val(k.public || '');
        $('#aib-wz-key-state').text(k.exists ? 'Schlüssel für das Plugin ist vorhanden.' : '');
        if (!Wiz.ok.login) Wiz.checkLogin(true);
      };
      if (Wiz.s.key.exists) show(Wiz.s.key);
      else A.api('key_generate', { force: 0 }).then(function (k) { Wiz.s.key = k; show(k); }, function (e) { Wiz.msg(e, 'err'); });
    },
    install: function () {
      var pw = $('#aib-wz-pw').val();
      if (!pw) { Wiz.msg('Bitte das Passwort des Backup-Servers eingeben.', 'err'); return; }
      A.busy('#aib-wz-install', true); Wiz.msg('Verbinde und hinterlege den Schlüssel …', 'info');
      A.api('key_install', { password: pw, mode: Wiz.type === 'storagebox' ? 'storagebox' : 'standard' })
        .then(function () { Wiz.checkLogin(); }, function (e) { Wiz.msg(e, 'err'); })
        .always(function () { $('#aib-wz-pw').val(''); A.busy('#aib-wz-install', false); });
    },
    checkLogin: function (silent) {
      if (!silent) Wiz.msg('Prüfe Anmeldung mit Schlüssel …', 'info');
      return A.api('test_login').then(function (r) {
        Wiz.ok.login = r.ok;
        if (r.ok) Wiz.msg('✓ ' + r.message + ' Weiter mit „Weiter“.', 'ok'); else if (!silent) Wiz.msg(r.message, 'err');
        Wiz.buttons();
      }, function (e) { if (!silent) Wiz.msg(e, 'err'); });
    },
    enterPassword: function () {
      if ($('#aib-wz-pwonce').is(':visible') || $('#aib-wz-pwsavedrow').is(':visible')) { Wiz.buttons(); return; }
      var set = Wiz.s.password.set;
      $('#aib-wz-pw-exists').toggle(set); $('#aib-wz-pw-new').toggle(!set);
      $('#aib-wz-pwsaved').prop('checked', false);
      Wiz.buttons();
    },
    checkRepo: function () {
      Wiz.ok.repo = false; Wiz.buttons(); $('#aib-wz-init').hide();
      $('#aib-wz-repo-state').html('<i class="fa fa-spinner fa-spin"></i> Prüfe Verbindung …');
      A.api('test_connection').then(function (r) {
        if (r.ok && r.initialized) { Wiz.ok.repo = true; $('#aib-wz-repo-state').html('<i class="fa fa-check aib-ok"></i> Verbindung steht. ' + (r.message.indexOf('vorhanden') >= 0 ? 'Der Backup-Speicher ist bereit.' : '')); }
        else if (r.ok) { $('#aib-wz-repo-state').html('<i class="fa fa-check aib-ok"></i> Verbindung steht. Am Ziel gibt es noch keinen Backup-Speicher – jetzt anlegen:'); $('#aib-wz-init').show(); }
        else $('#aib-wz-repo-state').html('<i class="fa fa-times" style="color:#d33"></i> ' + A.esc(r.message));
        Wiz.buttons();
      }, function (e) { $('#aib-wz-repo-state').html('<i class="fa fa-times" style="color:#d33"></i> ' + A.esc(e)); });
    },
    loadCts: function () {
      $('#aib-wz-cts').html('<i class="fa fa-spinner fa-spin"></i>');
      A.loadContainers(true).then(function (d) {
        Wiz.cts = d.containers;
        $('#aib-wz-cts').html($.map(d.containers, function (c, i) {
          var icon = c.icon ? '<img src="' + A.esc(c.icon) + '" onerror="this.outerHTML=\'<span class=&quot;aib-icon-ph fa fa-cube&quot;></span>\'">' : '<span class="aib-icon-ph fa fa-cube"></span>';
          var why = !c.paths.length ? 'keine Appdata – nur Einstellungen' : (c.note || (c.excludes.length ? 'ohne: ' + c.excludes.join(', ') : ''));
          return '<label class="' + (c.enabled ? '' : 'off') + '"><input type="checkbox" data-i="' + i + '"' + (c.enabled ? ' checked' : '') + '>' + icon +
            '<span><b>' + A.esc(c.name) + '</b>' + (why ? '<small>' + A.esc(why) + '</small>' : '') + '</span></label>';
        }).join('') || '<p>Keine Container gefunden.</p>');
      }, function (e) { $('#aib-wz-cts').html(''); Wiz.msg(e, 'err'); });
    },
    saveCts: function () {
      if (!Wiz.cts) return $.Deferred().resolve();
      var out = {};
      $('#aib-wz-cts input').each(function () {
        var c = Wiz.cts[$(this).data('i')];
        out[c.name] = { enabled: this.checked, stop: c.stop, group: c.group, order: c.order, excludes: c.excludes, extra_paths: c.extra_paths, note: c.note };
      });
      return A.api('save_containers', { containers: out }).then(function () {
        A.containers = null;
        $.each(Wiz.cts, function (_, c) { c.enabled = out[c.name].enabled; c.source = 'config'; });
      }, function (e) { Wiz.msg(e, 'err'); return $.Deferred().reject(); });
    },
    saveSchedule: function () {
      var f = $('#aib-wz-freq').val(), keep = { std: [7, 4, 6], more: [14, 8, 12], less: [3, 2, 3] }[$('#aib-wz-keep').val()], n = $('#aib-wz-notify').val() === '1';
      return A.api('save_settings', { settings: {
        schedule: { enabled: f !== 'off', frequency: f === 'weekly' ? 'weekly' : 'daily', hour: $('#aib-wz-hour').val(), minute: 0, weekday: 0 },
        retention: { keep_daily: keep[0], keep_weekly: keep[1], keep_monthly: keep[2], keep_yearly: 0, keep_last: 0, keep_within: '' },
        notify: { on_error: n, on_warning: n, on_success: false }, setup_completed: true } })
        .then(function () { return Wiz.load(false); }, function (e) { Wiz.msg(e, 'err'); return $.Deferred().reject(); });
    },
    summary: function () {
      var s = Wiz.s, sc = s.schedule, r = s.retention, n = 0, all = 0;
      $.each(Wiz.cts || [], function (_, c) { all++; if (c.enabled) n++; });
      return [
        ['Ziel', s.repository_effective],
        ['Container', Wiz.cts ? n + ' von ' + all + ' werden gesichert' : 'siehe Tab „Container“'],
        ['Zeitplan', s.schedule_active ? (sc.frequency === 'weekly' ? 'wöchentlich sonntags' : 'täglich') + ' um ' + (sc.hour < 10 ? '0' : '') + sc.hour + ':00 Uhr' : 'nur manuell'],
        ['Aufbewahrung', r.keep_daily + ' Tage, ' + r.keep_weekly + ' Wochen, ' + r.keep_monthly + ' Monate']
      ];
    },
    enterFinish: function () {
      $('#aib-wz-summary').html($.map(Wiz.summary(), function (x) { return '<li><i class="fa fa-check aib-ok"></i> <b>' + A.esc(x[0]) + ':</b> ' + A.esc(x[1]) + '</li>'; }).join(''));
      if (A.Status) A.Status.poll();
    },
    enterDone: function () {
      var go = function () { $('#aib-wz-done-summary').html($.map(Wiz.summary(), function (x) { return '<b>' + A.esc(x[0]) + ':</b> ' + A.esc(x[1]); }).join('<br>')); };
      A.loadContainers(false).then(function (d) { Wiz.cts = d.containers; go(); }, go);
    },
    selftest: function () {
      var path = null;
      $.each(Wiz.cts || [], function (_, c) { if (!path && c.enabled && c.paths.length) path = c.paths[0]; });
      if (!path) { Wiz.msg('Kein ausgewählter Container mit Appdata gefunden.', 'err'); return; }
      A.busy('#aib-wz-selftest', true); $('#aib-wz-selftest-out').show().text('Teste Snapshot für ' + path + ' …');
      A.api('selftest', { path: path }).then(function (r) { $('#aib-wz-selftest-out').text((r.ok ? '✓ Snapshot funktioniert.\n\n' : '✗ Snapshot nicht möglich – die Sicherung läuft dann im gestoppten Zustand (längere Ausfallzeit).\n\n') + r.log.join('\n')); },
        function (e) { $('#aib-wz-selftest-out').text(e); }).always(function () { A.busy('#aib-wz-selftest', false); });
    }
  };

  $(function () { Wiz.init(); Status.init(); Cts.init(); Tgt.init(); Sch.init(); Hist.init(); Res.init(); Tools.init(); });
})(jQuery);

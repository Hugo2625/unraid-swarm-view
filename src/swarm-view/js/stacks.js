/* Swarm View – Swarm-Stacks-Leiste: Nodes, Stacks, Services, Aktionen, Editor */
(function ($) {
  'use strict';

  const SV = window.SwarmView;
  const { esc, isOpen, setOpen, placeBadge, aggregateBadges, replicaSum, replicaClass, statusIcon, findService, nodeCheck, placeSelect, placeService, post, failResult, showResult, confirmBox } = SV;

  const LABELS = { deploy: 'Stack Up', update: 'Update Stack', remove: 'Stack Down', restart: 'Neustart', delete: 'Löschen', save: 'Speichern' };

  /* ---------- 1) Swarm-Stacks-Leiste ---------- */
  function renderMsg() {
    const $m = $('#sv-msg');
    const s = SV.data.swarm;
    if (SV.data.errors && SV.data.errors.length) {
      $m.attr('class', 'sv-msg error').html(SV.data.errors.map(esc).join('<br>')).show();
    } else if (!s.active) {
      $m.attr('class', 'sv-msg info')
        .html('Swarm ist auf diesem Host nicht aktiv. Initialisieren mit <code>docker swarm init --advertise-addr &lt;Unraid-IP&gt;</code>.')
        .show();
    } else if (!s.manager) {
      $m.attr('class', 'sv-msg info').text('Dieser Host ist nur Worker – Stacks sind nur auf einem Manager sichtbar.').show();
    } else {
      $m.hide();
    }
  }

  /* ---------- Kopfbereich: Zusammenfassung + Node-Karten ---------- */
  const bar = (label, pct) => {
    const v = Math.max(0, Math.min(100, Math.round(+pct || 0)));
    const cls = v >= 90 ? 'sv-bar-bad' : v >= 70 ? 'sv-bar-warn' : '';
    return `<div class="sv-meter"><span class="sv-meter-label">${label}</span><span class="sv-meter-track"><span class="sv-meter-fill ${cls}" style="width:${v}%"></span></span><span class="sv-meter-val">${v} %</span></div>`;
  };

  function nodeCard(n) {
    const st = (((SV.data.stats || {}).nodes) || {})[n.hostname];
    const role = n.role === 'manager' ? (n.leader ? 'Manager' : 'Manager (Reserve)') : 'Worker';
    const down = n.state !== 'ready';
    const maint = !down && n.availability !== 'active';
    const labels = Object.entries(n.labels || {}).map(([k, v]) => `${k}=${v}`).join(', ');
    const tip = esc(`${n.addr} · ${n.arch} · Docker ${n.engine}${labels ? ' · ' + labels : ''}`);
    const state = down ? '<span class="sv-bad">nicht erreichbar</span>'
      : maint ? `<span class="sv-warn">${n.availability === 'drain' ? 'Wartung' : 'pausiert'}</span>` : '<span class="sv-ok">bereit</span>';
    const statsOn = (SV.data.stats || {}).enabled;
    const since = st && st.ts ? new Date(st.ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '';
    const load = !statsOn ? '<div class="sv-card-note sv-dim">Auslastung: CPU/RAM-Agent in den Einstellungen einschalten</div>'
      : down ? `<div class="sv-card-note sv-bad">Node nicht erreichbar${since ? ` – letzte Werte ${since} Uhr` : ''}</div>`
        : !st ? '<div class="sv-card-note sv-warn">Agent liefert noch keine Werte (startet nach einem Neustart des Nodes neu)</div>'
          : st.stale ? `<div class="sv-card-note sv-warn">keine aktuellen Werte${since ? ` – letzte ${since} Uhr` : ''}</div>`
            : bar('CPU', st.cpu) + bar('RAM', st.mem);
    return `<div class="sv-card${down ? ' sv-card-down' : ''}" title="${tip}">
      <div class="sv-card-head"><i class="fa ${n.self ? 'fa-server' : 'fa-external-link'}"></i><b>${esc(n.hostname)}</b>
        <span class="sv-dim">${esc(role)} · ${n.cpus} CPU · ${n.memGB} GB</span><span class="sv-card-state">${state}</span></div>
      ${load}</div>`;
  }

  function renderNodes() {
    if (!SV.data.nodes.length) { $('#sv-nodes').empty(); return; }
    const services = SV.data.stacks.flatMap((st) => st.services);
    const tasks = services.flatMap((sv) => sv.tasks);
    const remote = tasks.filter((t) => t.node && !t.local && /^running/i.test(t.current)).length;
    const upd = services.filter((sv) => sv.update).length;
    const nStacks = SV.data.stacks.filter((st) => st.name !== '(ohne Stack)').length;
    const parts = [`${nStacks} Stack${nStacks === 1 ? '' : 's'}`, `${services.length} Service${services.length === 1 ? '' : 's'}`,
      `${remote} Container ausgelagert`];
    if (upd) parts.push(`<span class="sv-upd">${upd} Update${upd === 1 ? '' : 's'}</span>`);
    $('#sv-nodes').html(`<div class="sv-summary">${parts.join(' · ')}</div><div class="sv-cards">${SV.data.nodes.map(nodeCard).join('')}</div>`);
  }

  /* ---------- Update / Rollback / bevorzugter Node ---------- */
  function serviceAction(act, svc) {
    const short = (findService(svc) || {}).short || svc;
    const texts = {
      'svc-update': [`„${short}“ aktualisieren?`, 'Die neueste Image-Version wird geholt und der Service neu ausgerollt.'],
      'svc-rollback': [`„${short}“ zurücksetzen?`, `Rollback auf ${(findService(svc) || {}).rollbackTo || 'die vorherige Version'} (docker service rollback).`],
    };
    confirmBox(texts[act][0], texts[act][1], () => {
      SV.busy = true;
      $('.sv-svc-act').prop('disabled', true);
      post({ action: act, service: svc })
        .done((r) => showResult(act === 'svc-update' ? `Update: ${short}` : `Rollback: ${short}`, r))
        .fail((x) => showResult(short, failResult(x)))
        .always(() => { SV.busy = false; SV.load(); setTimeout(SV.load, 4000); });
    });
  }

  /* ---------- Menüs (Unraids Kontextmenü, wie bei den Container-Icons) ---------- */
  function attachMenu(el, opts) {
    if (typeof context === 'undefined' || !el.id) return;
    context.settings({ right: false, above: 'auto' });
    context.destroy('#' + el.id);
    context.attach('#' + el.id, opts);
  }
  const item = (text, icon, fn) => ({ text, icon, action: (e) => { e.preventDefault(); fn(); } });

  function restartService(sv) {
    confirmBox(`„${sv.short}“ neu starten?`, 'Der Service wird mit --force neu ausgerollt (alle Replicas).', () => {
      post({ action: 'svc-restart', service: sv.name })
        .done((r) => showResult(`Neustart: ${sv.short}`, r))
        .fail((x) => showResult(`Neustart: ${sv.short}`, failResult(x)))
        .always(() => { SV.load(); setTimeout(SV.load, 4000); });
    });
  }

  /** ⋮ an einer Service-Zeile */
  SV.serviceMenu = function (el) {
    const sv = findService(String($(el).data('service')));
    if (!sv || SV.busy) return;
    const opts = [];
    if (sv.update) opts.push(item('Update', 'fa-flash', () => serviceAction('svc-update', sv.name)));
    if (sv.canRollback) opts.push(item(`Rollback auf ${sv.rollbackTo || 'vorherige Version'}`, 'fa-undo', () => serviceAction('svc-rollback', sv.name)));
    opts.push(item('Neustart', 'fa-refresh', () => restartService(sv)));
    if (sv.mode !== 'global' && SV.data.nodes.length > 1) {
      const on = new Set(sv.tasks.map((t) => t.node));
      for (const n of SV.data.nodes) {
        if ((on.size === 1 && on.has(n.hostname)) || !nodeCheck(sv, n).ok) continue;
        opts.push(item(`Auf ${n.hostname} verschieben (fest)`, 'fa-exchange', () => placeService(sv.name, `fixed:${n.hostname}`)));
      }
      if (sv.placeNode || sv.prefer) opts.push(item('Automatisch platzieren', 'fa-random', () => placeService(sv.name, '')));
    }
    const logTasks = sv.tasks.filter((t) => t.node && t.id);
    if (logTasks.length) {
      opts.push({ divider: true });
      for (const t of logTasks.slice(0, 6)) {
        opts.push(item(logTasks.length > 1 ? `Logs (${t.node}${t.slot ? ' #' + t.slot : ''})` : 'Logs', 'fa-navicon',
          () => SV.openLogs({ taskId: t.id, name: `${sv.name}${t.slot ? '.' + t.slot : ''}`, node: t.node })));
      }
    }
    attachMenu(el, opts);
  };

  /** Zahnrad an einer Stack-Zeile */
  SV.stackMenu = function (el) {
    const name = String($(el).data('name'));
    const e = stackList().find((x) => x.name === name);
    if (!e || SV.busy) return;
    const opts = [];
    if (e.managed) {
      opts.push(item('Bearbeiten', 'fa-wrench', () => openEditor(name)));
      opts.push(item('Beschreibung', 'fa-pencil', () => editDescription(name)));
    }
    if (e.st) opts.push(item('Neustart', 'fa-refresh', () => stackAction('restart', name)));
    if (e.managed && !e.st) {
      opts.push({ divider: true });
      opts.push(item('Dateien löschen', 'fa-trash', () => deleteStack(name)));
    }
    attachMenu(el, opts);
  };

  function editDescription(name) {
    const cur = (((SV.data.stackInfo || {})[name]) || {}).description || '';
    const save = (v) => {
      if (v === false || v === null) return;
      post({ action: 'describe', name, description: String(v).trim() })
        .done((r) => { if (!r.ok) showResult('Beschreibung', r); SV.load(); })
        .fail((x) => showResult('Beschreibung', failResult(x)));
    };
    if (typeof swal === 'function') {
      swal({
        title: `Beschreibung für „${name}“`, text: 'Erscheint unter dem Stack-Namen (leer = keine).', type: 'input',
        showCancelButton: true, closeOnConfirm: true, inputValue: cur,
        confirmButtonText: 'Speichern', cancelButtonText: 'Abbrechen',
      }, (v) => setTimeout(() => save(v), 150));
    } else {
      save(prompt(`Beschreibung für „${name}“:`, cur));
    }
  }

  function deleteStack(name) {
    confirmBox(`Stack-Dateien „${name}“ löschen?`, 'docker-stack.yml, .env und Beschreibung werden vom USB-Stick entfernt.', () => {
      post({ action: 'delete', name })
        .done((r) => { if (r.ok) { if (edName === name) closeEditor(); SV.load(); } showResult(`Löschen: ${name}`, r); })
        .fail((x) => showResult(`Löschen: ${name}`, failResult(x)));
    });
  }

  /** Deployte + von Swarm View verwaltete Stacks zusammenführen */
  function stackList() {
    const deployed = new Map(SV.data.stacks.map((s) => [s.name, s]));
    const managed = new Set(SV.data.managed || []);
    const names = [...new Set([...deployed.keys(), ...managed])].sort((a, b) =>
      (a === '(ohne Stack)') - (b === '(ohne Stack)') || a.localeCompare(b));
    return names.map((n) => ({ name: n, st: deployed.get(n) || null, managed: managed.has(n) }));
  }

  const btn = (act, name, label, enabled = true) =>
    `<input type="button" class="sv-act" data-act="${act}" data-name="${esc(name)}" value="${label}"${SV.busy || !enabled ? ' disabled' : ''}>`;

  /** Registry weglassen: "ghcr.io/music-assistant/server:latest" → "music-assistant/server:latest" */
  const shortImage = (img) => {
    let s = String(img || '');
    const parts = s.split('/');
    if (parts.length > 1 && /[.:]|^localhost$/.test(parts[0])) s = parts.slice(1).join('/');
    s = s.replace(/^library\//, '');
    return s.slice(s.lastIndexOf('/') + 1).includes(':') ? s : `${s}:latest`;
  };
  // Platzierungsregeln aus der Datei; node.hostname zeigt schon die Auswahl
  const extraConstraints = (sv) => (sv.constraints || []).filter((c) => !/^\s*node\.hostname\s*==/.test(c));

  let menuSeq = 0;
  const COLS = 5;

  function serviceRow(name, sv, open) {
    const m = String(sv.replicas || '').match(/^(\d+)\/(\d+)/);
    const rc = m ? replicaClass(+m[1], +m[2]) : '';
    const cons = extraConstraints(sv);
    const tip = esc([sv.ports && `Ports: ${sv.ports}`, cons.length && `Regeln: ${cons.join(', ')}`].filter(Boolean).join('\n'));
    const badges = (sv.update ? ' <span class="sv-badge sv-upd-badge" title="Neuere Image-Version in der Registry – Update über ⋮"><i class="fa fa-flash"></i>Update</span>' : '')
      + (sv.prefer ? ` <span class="sv-badge sv-label" title="Bevorzugter Node (Label swarm-view.prefer)"><i class="fa fa-star"></i>${esc(sv.prefer)}</span>` : '')
      + (sv.rebalancing ? ' <span class="sv-badge sv-pending"><i class="fa fa-exchange"></i>kehrt zurück …</span>' : '');
    const id = `sv-menu-${++menuSeq}`;
    return `<tr class="sv-svc${open ? ' sv-open' : ''}" data-stack="${esc(name)}">
      <td class="sv-svcname"${tip ? ` title="${tip}"` : ''}><b>${esc(sv.short)}</b>${sv.mode === 'global' ? ' <span class="sv-dim">(global)</span>' : ''}${badges}${sv.ports ? `<span class="sv-adv sv-dim sv-mono sv-ports">${esc(sv.ports)}</span>` : ''}</td>
      <td class="${rc}">${esc(sv.replicas)}</td>
      <td class="sv-where"><span class="sv-where-badges">${sv.tasks.length ? sv.tasks.map(placeBadge).join('') : '<span class="sv-dim">–</span>'}</span>${placeSelect(sv)}${cons.length ? `<div class="sv-adv sv-cons">${cons.map(esc).join(' · ')}</div>` : ''}</td>
      <td class="sv-cmds"><i class="fa fa-ellipsis-v sv-menu" id="${id}" data-service="${esc(sv.name)}" title="Aktionen" onclick="SwarmView.serviceMenu(this)"></i></td>
      <td class="sv-adv sv-image" title="${esc(sv.image)}"><span class="sv-mono">${esc(shortImage(sv.image))}</span>${sv.update ? ' <i class="fa fa-flash sv-upd" title="Update verfügbar"></i>' : ''}</td>
    </tr>`;
  }

  function renderStacks() {
    const $tb = $('#sv-stacks tbody');
    if (document.activeElement && $(document.activeElement).is('#sv-stacks .sv-place')) return;
    if ($('.dropdown-context:visible').length) return; // offenes Menü nicht unter dem Mauszeiger wegrendern
    const swarmOk = SV.data.swarm.active && SV.data.swarm.manager;
    $('#sv-add, #sv-cleanup').prop('disabled', !swarmOk || SV.busy);
    const hl = SV.data.swarm.historyLimit;
    $('#sv-history').html(hl != null && hl > 1
      ? `Swarm behält bis zu ${hl} Container pro Service – <a href="/Settings/SwarmViewSettings">in den Einstellungen auf 1 stellen</a>` : '');
    if (!swarmOk) {
      $tb.html(`<tr class="sv-empty"><td colspan="${COLS}">Keine Swarm-Stacks verfügbar (Swarm nicht aktiv oder kein Manager)</td></tr>`);
      return;
    }
    const list = stackList();
    if (!list.length) {
      $tb.html(`<tr class="sv-empty"><td colspan="${COLS}">Noch keine Swarm-Stacks – unten mit „Add New Swarm Stack“ anlegen</td></tr>`);
      return;
    }
    menuSeq = 0;
    let rows = '';
    for (const { name, st, managed } of list) {
      const services = st ? st.services : [];
      const tasks = services.flatMap((sv) => sv.tasks);
      const { run, want } = replicaSum(services);
      const open = !!st && isOpen(name);
      const pseudo = name === '(ohne Stack)';
      const info = (SV.data.stackInfo || {})[name] || {};

      const icon = pseudo ? '<i class="fa fa-cubes sv-stackicon"></i>'
        : `<i class="fa fa-cog sv-gear" id="sv-gear-${++menuSeq}" data-name="${esc(name)}" title="Stack-Aktionen" onclick="SwarmView.stackMenu(this)"></i>`;
      const status = st ? statusIcon(run, want) : '<i class="fa fa-square red-text" style="margin-left:5px" title="nicht deployt"></i>';
      const nUpd = services.filter((sv) => sv.update).length;
      const desc = info.description ? `<div class="sv-desc">${esc(info.description)}</div>`
        : !managed && !pseudo ? '<div class="sv-desc sv-dim">extern deployt</div>' : '';
      const path = info.path ? `<div class="sv-path sv-dim sv-mono">${esc(info.path)}</div>` : '';

      let cmds = '';
      if (managed) cmds = btn('deploy', name, 'Stack Up') + btn('update', name, 'Update Stack', !!st) + btn('remove', name, 'Stack Down', !!st);
      else if (st && !pseudo) cmds = btn('remove', name, 'Stack Down');

      rows += `<tr class="sv-stack" data-stack="${esc(name)}">
        <td class="sv-stackcell">
          <i class="fa ${st ? `fa-chevron-${open ? 'down' : 'right'}` : 'fa-fw'} sv-caret"></i>${icon}<span class="sv-stackname">${esc(name)}</span>${status}
          ${desc}${path}
        </td>
        <td>${st ? `<span class="${replicaClass(run, want)}">${run}/${want}</span><div class="sv-dim sv-small">${services.length} Service${services.length === 1 ? '' : 's'}${nUpd ? ` · <span class="sv-upd">${nUpd} Update${nUpd === 1 ? '' : 's'}</span>` : ''}</div>` : '<span class="sv-dim">nicht deployt</span>'}</td>
        <td>${st ? aggregateBadges(tasks) : '<span class="sv-dim">–</span>'}</td>
        <td class="sv-cmds">${cmds}</td>
        <td class="sv-adv"></td>
      </tr>`;
      for (const sv of services) rows += serviceRow(name, sv, open);
    }
    $tb.html(rows);
  }

  $(document).on('click', '#sv-stacks tr.sv-stack', function (e) {
    if ($(e.target).closest('.sv-act, .sv-gear, .sv-edit').length) return;
    const name = String($(this).data('stack'));
    const $svc = $('#sv-stacks tr.sv-svc').filter(function () { return String($(this).data('stack')) === name; });
    if (!$svc.length) return;
    const open = !isOpen(name);
    setOpen(name, open);
    $(this).find('.sv-caret').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
    $svc.toggleClass('sv-open', open);
  });

  // Ansicht umgeschaltet → Standard für noch nicht angefasste Stacks ändert sich
  $(document).on('change', 'input.advancedview', () => setTimeout(() => SV.data && renderStacks(), 0));

  function runAction(act, name, $button) {
    SV.busy = true;
    $('.sv-act, #sv-editor input').prop('disabled', true);
    const old = $button ? $button.val() : '';
    if ($button) $button.val('läuft …');
    const req = act === 'update' ? { action: 'deploy', mode: 'update', name } : { action: act, name };
    post(req)
      .done((r) => showResult(`${LABELS[act]}: ${name}`, r))
      .fail((x) => showResult(`${LABELS[act]}: ${name}`, failResult(x)))
      .always(() => {
        SV.busy = false;
        $('.sv-act, #sv-editor input').prop('disabled', false);
        if ($button) $button.val(old);
        SV.load();
        // Swarm braucht ein paar Sekunden, bis Tasks laufen
        setTimeout(SV.load, 4000);
      });
  }

  function stackAction(act, name, $b) {
    if (act === 'remove') {
      confirmBox(`Stack „${name}“ herunterfahren?`, 'Alle Services des Stacks werden entfernt. Volumes und Daten bleiben erhalten.', () => runAction(act, name, $b));
    } else if (act === 'update') {
      confirmBox(`Stack „${name}“ aktualisieren?`, 'Swarm prüft für alle Services, ob es neuere Image-Versionen gibt, und rollt diese aus. Services ohne neue Version bleiben unverändert.', () => runAction(act, name, $b));
    } else if (act === 'restart') {
      confirmBox(`Stack „${name}“ neu starten?`, 'Alle Services werden mit --force neu ausgerollt.', () => runAction(act, name, $b));
    } else {
      runAction(act, name, $b);
    }
  }

  $(document).on('click', '#sv-stacks .sv-act', function (e) {
    e.stopPropagation();
    stackAction($(this).data('act'), String($(this).data('name')), $(this));
  });


  /* ---------- Editor ---------- */
  let edName = null;

  function switchTab(file) {
    $('.sv-ed-tab').removeClass('active').filter(`[data-file="${file}"]`).addClass('active');
    $('#sv-ed-yml').toggle(file === 'yml');
    $('#sv-ed-env').toggle(file === 'env');
  }

  function edLog(ok, text) {
    $('#sv-ed-log').attr('class', `sv-log ${ok ? 'sv-log-ok' : 'sv-log-err'}`).text(text).show();
  }

  function openEditor(name, preload) {
    const show = (r) => {
      edName = name;
      $('#sv-ed-name').text(name);
      $('#sv-ed-path').text(r.path || '');
      $('#sv-ed-yml').val(r.yml || '');
      $('#sv-ed-env').val(r.env || '');
      $('#sv-ed-log').hide();
      $('#sv-ed-delete').toggle(!r.deployed);
      switchTab('yml');
      $('#sv-editor').show()[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    if (preload) show(preload);
    else post({ action: 'get', name }).done((r) => (r.ok ? show(r) : showResult('Fehler', r))).fail((x) => showResult('Fehler', failResult(x)));
  }

  function closeEditor() { edName = null; $('#sv-editor').hide(); }

  function saveEditor() {
    return post({ action: 'save', name: edName, yml: $('#sv-ed-yml').val(), env: $('#sv-ed-env').val() })
      .done((r) => edLog(r.ok && r.valid, r.ok ? `Gespeichert. ${r.log || ''}` : (r.error || 'Fehler')))
      .fail((x) => edLog(false, failResult(x).error));
  }

  $(document).on('click', '.sv-edit', function (e) {
    e.stopPropagation();
    openEditor(String($(this).data('name')));
  });
  $(document).on('click', '.sv-ed-tab', function (e) { e.preventDefault(); switchTab($(this).data('file')); });
  $(document).on('click', '#sv-ed-close', closeEditor);
  $(document).on('click', '#sv-ed-save', () => saveEditor().done(() => SV.load()));
  $(document).on('click', '#sv-ed-deploy', function () {
    const name = edName;
    saveEditor().done((r) => {
      if (r.ok && r.valid) runAction('deploy', name, $(this));
      else edLog(false, `Nicht deployt – Datei ist ungültig:\n${r.log || r.error || ''}`);
    });
  });
  $(document).on('click', '#sv-ed-delete', () => deleteStack(edName));
  // Tab-Taste im Editor = 2 Leerzeichen (YAML)
  $(document).on('keydown', '.sv-ed-text', function (e) {
    if (e.key !== 'Tab') return;
    e.preventDefault();
    const s = this.selectionStart;
    this.value = this.value.slice(0, s) + '  ' + this.value.slice(this.selectionEnd);
    this.selectionStart = this.selectionEnd = s + 2;
  });

  /* ---------- Aufräumen ---------- */
  $(document).on('click', '#sv-cleanup', function () {
    const $b = $(this);
    confirmBox('Swarm aufräumen?',
      'Löscht auf allen Nodes gestoppte Swarm-Container und verwaiste Images. Laufende Container bleiben unberührt. Außerdem behält Swarm künftig nur noch den aktuellen Container pro Service.',
      () => {
        SV.busy = true;
        $b.prop('disabled', true).val('räumt auf …');
        post({ action: 'cleanup' })
          .done((r) => showResult('Aufräumen', r))
          .fail((x) => showResult('Aufräumen', failResult(x)))
          .always(() => {
            SV.busy = false;
            $b.prop('disabled', false).val('Aufräumen');
            SV.load();
            if (typeof window.loadlist === 'function' && !SV.listPending) window.loadlist();
          });
      });
  });

  /* ---------- Neuer Stack ---------- */
  function createStack(raw) {
    const name = String(raw || '').trim().toLowerCase();
    if (!name) return;
    post({ action: 'create', name })
      .done((r) => { if (r.ok) { openEditor(name, r); SV.load(); } else showResult('Neuer Stack', r); })
      .fail((x) => showResult('Neuer Stack', failResult(x)));
  }

  $(document).on('click', '#sv-add', () => {
    if (typeof swal === 'function') {
      swal({
        title: 'Neuer Swarm-Stack', text: 'Name (a-z, 0-9, - und _)', type: 'input',
        showCancelButton: true, closeOnConfirm: true, inputPlaceholder: 'stack_name',
        confirmButtonText: 'Anlegen', cancelButtonText: 'Abbrechen',
      }, (v) => { if (v !== false) setTimeout(() => createStack(v), 150); });
    } else {
      createStack(prompt('Name des neuen Swarm-Stacks (a-z, 0-9, - und _):'));
    }
  });

  Object.assign(SV, { renderMsg, renderNodes, renderStacks, openEditor, serviceAction });
})(jQuery);

/* Swarm View – Swarm Stacks section: nodes, stacks, services, actions, editor */
(function ($) {
  'use strict';

  const SV = window.SwarmView;
  const { t, tn, esc, isOpen, setOpen, placeBadge, aggregateBadges, replicaSum, replicaClass, statusIcon, findService, nodeCheck, placeSelect, placeService, post, failResult, showResult, confirmBox } = SV;

  const PSEUDO = '(no stack)';   // services that do not belong to a stack (from api.php)
  const LABELS = { deploy: 'Stack Up', update: 'Update Stack', remove: 'Stack Down', restart: t('Restart'), delete: t('Delete'), save: t('Save') };

  /* ---------- Message line ---------- */
  function renderMsg() {
    const $m = $('#sv-msg');
    const s = SV.data.swarm;
    if (SV.data.errors && SV.data.errors.length) {
      $m.attr('class', 'sv-msg error').html(SV.data.errors.map(esc).join('<br>')).show();
    } else if (!s.active) {
      $m.attr('class', 'sv-msg info')
        .html(esc(t('Swarm is not active on this host. Initialise it with')) + ' <code>docker swarm init --advertise-addr &lt;Unraid-IP&gt;</code>.')
        .show();
    } else if (!s.manager) {
      $m.attr('class', 'sv-msg info').text(t('This host is only a worker – stacks are only visible on a manager.')).show();
    } else {
      $m.hide();
    }
  }

  /* ---------- Header: summary + node cards ---------- */
  const bar = (label, pct) => {
    const v = Math.max(0, Math.min(100, Math.round(+pct || 0)));
    const cls = v >= 90 ? 'sv-bar-bad' : v >= 70 ? 'sv-bar-warn' : '';
    return `<div class="sv-meter"><span class="sv-meter-label">${label}</span><span class="sv-meter-track"><span class="sv-meter-fill ${cls}" style="width:${v}%"></span></span><span class="sv-meter-val">${v} %</span></div>`;
  };

  function nodeCard(n) {
    const st = (((SV.data.stats || {}).nodes) || {})[n.hostname];
    const role = n.role === 'manager' ? (n.leader ? 'Manager' : t('Manager (reserve)')) : 'Worker';
    const down = n.state !== 'ready';
    const maint = !down && n.availability !== 'active';
    const labels = Object.entries(n.labels || {}).map(([k, v]) => `${k}=${v}`).join(', ');
    const tip = esc(`${n.addr} · ${n.arch} · Docker ${n.engine}${labels ? ' · ' + labels : ''}`);
    const state = down ? `<span class="sv-bad">${esc(t('unreachable'))}</span>`
      : maint ? `<span class="sv-warn">${esc(n.availability === 'drain' ? t('maintenance') : t('paused'))}</span>` : `<span class="sv-ok">${esc(t('ready'))}</span>`;
    const statsOn = (SV.data.stats || {}).enabled;
    const since = st && st.ts ? new Date(st.ts * 1000).toLocaleTimeString(SV.LANG === 'de' ? 'de-DE' : undefined, { hour: '2-digit', minute: '2-digit' }) : '';
    const load = !statsOn ? `<div class="sv-card-note sv-dim">${esc(t('Load: switch on the CPU/RAM agent in the settings'))}</div>`
      : down ? `<div class="sv-card-note sv-bad">${esc(since ? t('Node unreachable – last values {0}', since) : t('Node unreachable'))}</div>`
        : !st ? `<div class="sv-card-note sv-warn">${esc(t('The agent has not reported values yet (it restarts after the node reboots)'))}</div>`
          : st.stale ? `<div class="sv-card-note sv-warn">${esc(since ? t('no current values – last {0}', since) : t('no current values'))}</div>`
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
    const remote = tasks.filter((tk) => tk.node && !tk.local && /^running/i.test(tk.current)).length;
    const upd = services.filter((sv) => sv.update).length;
    const nStacks = SV.data.stacks.filter((st) => st.name !== PSEUDO).length;
    const parts = [tn(nStacks, '{0} stack', '{0} stacks'), tn(services.length, '{0} service', '{0} services'),
      tn(remote, '{0} container on other nodes', '{0} containers on other nodes')].map(esc);
    if (upd) parts.push(`<span class="sv-upd">${esc(tn(upd, '{0} update', '{0} updates'))}</span>`);
    $('#sv-nodes').html(`<div class="sv-summary">${parts.join(' · ')}</div><div class="sv-cards">${SV.data.nodes.map(nodeCard).join('')}</div>`);
  }

  /* ---------- Update / rollback ---------- */
  function serviceAction(act, svc) {
    const short = (findService(svc) || {}).short || svc;
    const texts = {
      'svc-update': [t('Update "{0}"?', short), t('The newest image version is pulled and the service is rolled out again.')],
      'svc-rollback': [t('Roll back "{0}"?', short), t('Rollback to {0} (docker service rollback).', (findService(svc) || {}).rollbackTo || t('the previous version'))],
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

  /* ---------- Menus (Unraid's context menu, like the container icons) ---------- */
  function attachMenu(el, opts) {
    if (typeof context === 'undefined' || !el.id) return;
    context.settings({ right: false, above: 'auto' });
    context.destroy('#' + el.id);
    context.attach('#' + el.id, opts);
  }
  const item = (text, icon, fn) => ({ text, icon, action: (e) => { e.preventDefault(); fn(); } });

  function restartService(sv) {
    confirmBox(t('Restart "{0}"?', sv.short), t('The service is rolled out again with --force (all replicas).'), () => {
      post({ action: 'svc-restart', service: sv.name })
        .done((r) => showResult(t('Restart: {0}', sv.short), r))
        .fail((x) => showResult(t('Restart: {0}', sv.short), failResult(x)))
        .always(() => { SV.load(); setTimeout(SV.load, 4000); });
    });
  }

  /** ⋮ on a service row */
  SV.serviceMenu = function (el) {
    const sv = findService(String($(el).data('service')));
    if (!sv || SV.busy) return;
    const opts = [];
    if (sv.update) opts.push(item('Update', 'fa-flash', () => serviceAction('svc-update', sv.name)));
    if (sv.canRollback) opts.push(item(t('Rollback to {0}', sv.rollbackTo || t('previous version')), 'fa-undo', () => serviceAction('svc-rollback', sv.name)));
    opts.push(item(t('Restart'), 'fa-refresh', () => restartService(sv)));
    if (sv.mode !== 'global' && SV.data.nodes.length > 1) {
      const on = new Set(sv.tasks.map((tk) => tk.node));
      for (const n of SV.data.nodes) {
        if ((on.size === 1 && on.has(n.hostname)) || !nodeCheck(sv, n).ok) continue;
        opts.push(item(t('Move to {0} (fixed)', n.hostname), 'fa-exchange', () => placeService(sv.name, `fixed:${n.hostname}`)));
      }
      if (sv.placeNode || sv.prefer) opts.push(item(t('Place automatically'), 'fa-random', () => placeService(sv.name, '')));
    }
    const logTasks = sv.tasks.filter((tk) => tk.node && tk.id);
    if (logTasks.length) {
      opts.push({ divider: true });
      for (const tk of logTasks.slice(0, 6)) {
        opts.push(item(logTasks.length > 1 ? `Logs (${tk.node}${tk.slot ? ' #' + tk.slot : ''})` : 'Logs', 'fa-navicon',
          () => SV.openLogs({ taskId: tk.id, name: `${sv.name}${tk.slot ? '.' + tk.slot : ''}`, node: tk.node })));
      }
    }
    attachMenu(el, opts);
  };

  /** Gear on a stack row */
  SV.stackMenu = function (el) {
    const name = String($(el).data('name'));
    const e = stackList().find((x) => x.name === name);
    if (!e || SV.busy) return;
    const opts = [];
    if (e.managed) {
      opts.push(item(t('Edit'), 'fa-wrench', () => openEditor(name)));
      opts.push(item(t('Description'), 'fa-pencil', () => editDescription(name)));
    }
    if (e.st) opts.push(item(t('Restart'), 'fa-refresh', () => stackAction('restart', name)));
    if (e.managed && !e.st) {
      opts.push({ divider: true });
      opts.push(item(t('Delete files'), 'fa-trash', () => deleteStack(name)));
    }
    attachMenu(el, opts);
  };

  function editDescription(name) {
    const cur = (((SV.data.stackInfo || {})[name]) || {}).description || '';
    const save = (v) => {
      if (v === false || v === null) return;
      post({ action: 'describe', name, description: String(v).trim() })
        .done((r) => { if (!r.ok) showResult(t('Description'), r); SV.load(); })
        .fail((x) => showResult(t('Description'), failResult(x)));
    };
    if (typeof swal === 'function') {
      swal({
        title: t('Description for "{0}"', name), text: t('Shown below the stack name (empty = none).'), type: 'input',
        showCancelButton: true, closeOnConfirm: true, inputValue: cur,
        confirmButtonText: t('Save'), cancelButtonText: t('Cancel'),
      }, (v) => setTimeout(() => save(v), 150));
    } else {
      save(prompt(`${t('Description for "{0}"', name)}:`, cur));
    }
  }

  function deleteStack(name) {
    confirmBox(t('Delete the stack files of "{0}"?', name), t('docker-stack.yml, .env and the description are removed from the flash drive.'), () => {
      post({ action: 'delete', name })
        .done((r) => { if (r.ok) { if (edName === name) closeEditor(); SV.load(); } showResult(t('Delete: {0}', name), r); })
        .fail((x) => showResult(t('Delete: {0}', name), failResult(x)));
    });
  }

  /** Merge deployed stacks and stacks managed by Swarm View */
  function stackList() {
    const deployed = new Map(SV.data.stacks.map((s) => [s.name, s]));
    const managed = new Set(SV.data.managed || []);
    const names = [...new Set([...deployed.keys(), ...managed])].sort((a, b) =>
      (a === PSEUDO) - (b === PSEUDO) || a.localeCompare(b));
    return names.map((n) => ({ name: n, st: deployed.get(n) || null, managed: managed.has(n) }));
  }

  const btn = (act, name, label, enabled = true) =>
    `<input type="button" class="sv-act" data-act="${act}" data-name="${esc(name)}" value="${esc(label)}"${SV.busy || !enabled ? ' disabled' : ''}>`;

  /** Drop the registry: "ghcr.io/music-assistant/server:latest" → "music-assistant/server:latest" */
  const shortImage = (img) => {
    let s = String(img || '');
    const parts = s.split('/');
    if (parts.length > 1 && /[.:]|^localhost$/.test(parts[0])) s = parts.slice(1).join('/');
    s = s.replace(/^library\//, '');
    return s.slice(s.lastIndexOf('/') + 1).includes(':') ? s : `${s}:latest`;
  };
  // placement rules from the file; node.hostname is already shown by the select
  const extraConstraints = (sv) => (sv.constraints || []).filter((c) => !/^\s*node\.hostname\s*==/.test(c));

  let menuSeq = 0;
  const COLS = 5;

  function serviceRow(name, sv, open) {
    const m = String(sv.replicas || '').match(/^(\d+)\/(\d+)/);
    const rc = m ? replicaClass(+m[1], +m[2]) : '';
    const cons = extraConstraints(sv);
    const tip = esc([sv.ports && `Ports: ${sv.ports}`, cons.length && t('Rules: {0}', cons.join(', '))].filter(Boolean).join('\n'));
    const badges = (sv.update ? ` <span class="sv-badge sv-upd-badge" title="${esc(t('Newer image version in the registry – update via ⋮'))}"><i class="fa fa-flash"></i>Update</span>` : '')
      + (sv.prefer ? ` <span class="sv-badge sv-label" title="${esc(t('Preferred node (label swarm-view.prefer)'))}"><i class="fa fa-star"></i>${esc(sv.prefer)}</span>` : '')
      + (sv.rebalancing ? ` <span class="sv-badge sv-pending"><i class="fa fa-exchange"></i>${esc(t('returning …'))}</span>` : '');
    const id = `sv-menu-${++menuSeq}`;
    return `<tr class="sv-svc${open ? ' sv-open' : ''}" data-stack="${esc(name)}">
      <td class="sv-svcname"${tip ? ` title="${tip}"` : ''}><b>${esc(sv.short)}</b>${sv.mode === 'global' ? ' <span class="sv-dim">(global)</span>' : ''}${badges}${sv.ports ? `<span class="sv-adv sv-dim sv-mono sv-ports">${esc(sv.ports)}</span>` : ''}</td>
      <td class="${rc}">${esc(sv.replicas)}</td>
      <td class="sv-where"><span class="sv-where-badges">${sv.tasks.length ? sv.tasks.map(placeBadge).join('') : '<span class="sv-dim">–</span>'}</span>${placeSelect(sv)}${cons.length ? `<div class="sv-adv sv-cons">${cons.map(esc).join(' · ')}</div>` : ''}</td>
      <td class="sv-cmds"><i class="fa fa-ellipsis-v sv-menu" id="${id}" data-service="${esc(sv.name)}" title="${esc(t('Actions'))}" onclick="SwarmView.serviceMenu(this)"></i></td>
      <td class="sv-adv sv-image" title="${esc(sv.image)}"><span class="sv-mono">${esc(shortImage(sv.image))}</span>${sv.update ? ` <i class="fa fa-flash sv-upd" title="${esc(t('Update available'))}"></i>` : ''}</td>
    </tr>`;
  }

  function renderStacks() {
    const $tb = $('#sv-stacks tbody');
    if (document.activeElement && $(document.activeElement).is('#sv-stacks .sv-place')) return;
    if ($('.dropdown-context:visible').length) return; // do not re-render an open menu away under the mouse
    const swarmOk = SV.data.swarm.active && SV.data.swarm.manager;
    $('#sv-add, #sv-cleanup').prop('disabled', !swarmOk || SV.busy);
    const hl = SV.data.swarm.historyLimit;
    $('#sv-history').html(hl != null && hl > 1
      ? `${esc(t('Swarm keeps up to {0} containers per service', hl))} – <a href="/Settings/SwarmViewSettings">${esc(t('set it to 1 in the settings'))}</a>` : '');
    if (!swarmOk) {
      $tb.html(`<tr class="sv-empty"><td colspan="${COLS}">${esc(t('No Swarm stacks available (Swarm not active or not a manager)'))}</td></tr>`);
      return;
    }
    const list = stackList();
    if (!list.length) {
      $tb.html(`<tr class="sv-empty"><td colspan="${COLS}">${esc(t('No Swarm stacks yet – create one below with "Add New Swarm Stack"'))}</td></tr>`);
      return;
    }
    menuSeq = 0;
    let rows = '';
    for (const { name, st, managed } of list) {
      const services = st ? st.services : [];
      const tasks = services.flatMap((sv) => sv.tasks);
      const { run, want } = replicaSum(services);
      const open = !!st && isOpen(name);
      const pseudo = name === PSEUDO;
      const info = (SV.data.stackInfo || {})[name] || {};

      const icon = pseudo ? '<i class="fa fa-cubes sv-stackicon"></i>'
        : `<i class="fa fa-cog sv-gear" id="sv-gear-${++menuSeq}" data-name="${esc(name)}" title="${esc(t('Stack actions'))}" onclick="SwarmView.stackMenu(this)"></i>`;
      const status = st ? statusIcon(run, want) : `<i class="fa fa-square red-text" style="margin-left:5px" title="${esc(t('not deployed'))}"></i>`;
      const nUpd = services.filter((sv) => sv.update).length;
      const desc = info.description ? `<div class="sv-desc">${esc(info.description)}</div>`
        : !managed && !pseudo ? `<div class="sv-desc sv-dim">${esc(t('deployed externally'))}</div>` : '';
      const path = info.path ? `<div class="sv-path sv-dim sv-mono">${esc(info.path)}</div>` : '';

      let cmds = '';
      if (managed) cmds = btn('deploy', name, 'Stack Up') + btn('update', name, 'Update Stack', !!st) + btn('remove', name, 'Stack Down', !!st);
      else if (st && !pseudo) cmds = btn('remove', name, 'Stack Down');

      rows += `<tr class="sv-stack" data-stack="${esc(name)}">
        <td class="sv-stackcell">
          <i class="fa ${st ? `fa-chevron-${open ? 'down' : 'right'}` : 'fa-fw'} sv-caret"></i>${icon}<span class="sv-stackname">${esc(pseudo ? t(PSEUDO) : name)}</span>${status}
          ${desc}${path}
        </td>
        <td>${st ? `<span class="${replicaClass(run, want)}">${run}/${want}</span><div class="sv-dim sv-small">${esc(tn(services.length, '{0} service', '{0} services'))}${nUpd ? ` · <span class="sv-upd">${esc(tn(nUpd, '{0} update', '{0} updates'))}</span>` : ''}</div>` : `<span class="sv-dim">${esc(t('not deployed'))}</span>`}</td>
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

  // view switched → the default for stacks not toggled yet changes
  $(document).on('change', 'input.advancedview', () => setTimeout(() => SV.data && renderStacks(), 0));

  function runAction(act, name, $button) {
    SV.busy = true;
    $('.sv-act, #sv-editor input').prop('disabled', true);
    const old = $button ? $button.val() : '';
    if ($button) $button.val(t('running …'));
    const req = act === 'update' ? { action: 'deploy', mode: 'update', name } : { action: act, name };
    post(req)
      .done((r) => showResult(`${LABELS[act]}: ${name}`, r))
      .fail((x) => showResult(`${LABELS[act]}: ${name}`, failResult(x)))
      .always(() => {
        SV.busy = false;
        $('.sv-act, #sv-editor input').prop('disabled', false);
        if ($button) $button.val(old);
        SV.load();
        // Swarm needs a few seconds until tasks run
        setTimeout(SV.load, 4000);
      });
  }

  function stackAction(act, name, $b) {
    if (act === 'remove') {
      confirmBox(t('Shut down stack "{0}"?', name), t('All services of the stack are removed. Volumes and data are kept.'), () => runAction(act, name, $b));
    } else if (act === 'update') {
      confirmBox(t('Update stack "{0}"?', name), t('Swarm checks all services for newer image versions and rolls them out. Services without a new version stay unchanged.'), () => runAction(act, name, $b));
    } else if (act === 'restart') {
      confirmBox(t('Restart stack "{0}"?', name), t('All services are rolled out again with --force.'), () => runAction(act, name, $b));
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
    else post({ action: 'get', name }).done((r) => (r.ok ? show(r) : showResult(t('Error'), r))).fail((x) => showResult(t('Error'), failResult(x)));
  }

  function closeEditor() { edName = null; $('#sv-editor').hide(); }

  function saveEditor() {
    return post({ action: 'save', name: edName, yml: $('#sv-ed-yml').val(), env: $('#sv-ed-env').val() })
      .done((r) => edLog(r.ok && r.valid, r.ok ? `${t('Saved.')} ${r.log || ''}` : (r.error || t('Error'))))
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
      else edLog(false, `${t('Not deployed – the file is invalid:')}\n${r.log || r.error || ''}`);
    });
  });
  $(document).on('click', '#sv-ed-delete', () => deleteStack(edName));
  // Tab key in the editor = 2 spaces (YAML)
  $(document).on('keydown', '.sv-ed-text', function (e) {
    if (e.key !== 'Tab') return;
    e.preventDefault();
    const s = this.selectionStart;
    this.value = this.value.slice(0, s) + '  ' + this.value.slice(this.selectionEnd);
    this.selectionStart = this.selectionEnd = s + 2;
  });

  /* ---------- Cleanup ---------- */
  $(document).on('click', '#sv-cleanup', function () {
    const $b = $(this);
    const label = $b.val();
    confirmBox(t('Clean up Swarm?'),
      t('Removes stopped Swarm containers and unused images on all nodes. Running containers are not touched. Swarm also keeps only the current container per service from now on.'),
      () => {
        SV.busy = true;
        $b.prop('disabled', true).val(t('cleaning up …'));
        post({ action: 'cleanup' })
          .done((r) => showResult(t('Cleanup'), r))
          .fail((x) => showResult(t('Cleanup'), failResult(x)))
          .always(() => {
            SV.busy = false;
            $b.prop('disabled', false).val(label);
            SV.load();
            if (typeof window.loadlist === 'function' && !SV.listPending) window.loadlist();
          });
      });
  });

  /* ---------- New stack ---------- */
  function createStack(raw) {
    const name = String(raw || '').trim().toLowerCase();
    if (!name) return;
    post({ action: 'create', name })
      .done((r) => { if (r.ok) { openEditor(name, r); SV.load(); } else showResult(t('New stack'), r); })
      .fail((x) => showResult(t('New stack'), failResult(x)));
  }

  $(document).on('click', '#sv-add', () => {
    if (typeof swal === 'function') {
      swal({
        title: t('New Swarm stack'), text: t('Name (a-z, 0-9, - and _)'), type: 'input',
        showCancelButton: true, closeOnConfirm: true, inputPlaceholder: 'stack_name',
        confirmButtonText: t('Create'), cancelButtonText: t('Cancel'),
      }, (v) => { if (v !== false) setTimeout(() => createStack(v), 150); });
    } else {
      createStack(prompt(t('Name of the new Swarm stack (a-z, 0-9, - and _):')));
    }
  });

  Object.assign(SV, { renderMsg, renderNodes, renderStacks, openEditor, serviceAction });
})(jQuery);

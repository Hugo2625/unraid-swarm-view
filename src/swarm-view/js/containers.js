/* Swarm View – Unraid's container list: host column, containers on other nodes, context menu, logs, Folder View */
(function ($) {
  'use strict';

  const SV = window.SwarmView;
  const { t, tn, LANG, esc, cfg, localBadge, remoteBadge, findService, nodeCheck, placeSelect, placeService, post, failResult, showResult, confirmBox, openEditor, serviceAction } = SV;

  /* ---------- Extend Unraid's container table ---------- */
  let observer = null;
  let remoteIndex = {};      // element id → task info (for context menu/logs)
  const QUESTION_ICON = '/plugins/dynamix.docker.manager/images/question.png';

  /* ---------- Times like Unraid ("Uptime: 54 minutes", "Created 54 minutes ago") ----------
   * Like Docker's HumanDuration (Unraid translates "Up 54 minutes" from it). German needs the dative after "vor". */
  function duration(sec, dative) {
    sec = Math.max(0, sec);
    if (LANG === 'de') {
      const pl = (n, one, many, manyDat) => `${n} ${n === 1 ? one : dative ? manyDat : many}`;
      if (sec < 1) return dative ? 'weniger als einer Sekunde' : 'weniger als 1 Sekunde';
      if (sec < 60) return pl(Math.floor(sec), 'Sekunde', 'Sekunden', 'Sekunden');
      const min = Math.floor(sec / 60);
      if (min === 1) return dative ? 'etwa einer Minute' : 'etwa 1 Minute';
      if (min < 60) return pl(min, 'Minute', 'Minuten', 'Minuten');
      const h = Math.round(sec / 3600);
      if (h === 1) return dative ? 'etwa einer Stunde' : 'etwa 1 Stunde';
      if (h < 48) return pl(h, 'Stunde', 'Stunden', 'Stunden');
      if (h < 24 * 7 * 2) return pl(Math.floor(h / 24), 'Tag', 'Tage', 'Tagen');
      if (h < 24 * 30 * 2) return pl(Math.floor(h / 24 / 7), 'Woche', 'Wochen', 'Wochen');
      if (h < 24 * 365 * 2) return pl(Math.floor(h / 24 / 30), 'Monat', 'Monate', 'Monaten');
      return pl(Math.floor(h / 24 / 365), 'Jahr', 'Jahre', 'Jahren');
    }
    const pl = (n, unit) => `${n} ${unit}${n === 1 ? '' : 's'}`;
    if (sec < 1) return 'less than a second';
    if (sec < 60) return pl(Math.floor(sec), 'second');
    const min = Math.floor(sec / 60);
    if (min === 1) return 'about a minute';
    if (min < 60) return pl(min, 'minute');
    const h = Math.round(sec / 3600);
    if (h === 1) return 'about an hour';
    if (h < 48) return pl(h, 'hour');
    if (h < 24 * 7 * 2) return pl(Math.floor(h / 24), 'day');
    if (h < 24 * 30 * 2) return pl(Math.floor(h / 24 / 7), 'week');
    if (h < 24 * 365 * 2) return pl(Math.floor(h / 24 / 30), 'month');
    return pl(Math.floor(h / 24 / 365), 'year');
  }
  const since = (iso) => { const ms = Date.parse(iso || ''); return isNaN(ms) ? null : (Date.now() - ms) / 1000; };
  const timesHtml = (r) => {
    const up = since(r.since), cr = since(r.created);
    const state = String(r.current).split(' ')[0] || t('Stopped');
    const first = r.running ? (up != null ? t('Uptime: {0}', duration(up)) : t('running'))
      : (up != null ? t('{0}: {1} ago', state, duration(up, true)) : state);
    return `<div style="white-space:nowrap">${esc(first)}${cr != null ? `<div style="margin-top:4px">${esc(t('Created {0} ago', duration(cr, true)))}</div>` : ''}</div>`;
  };

  /* ---------- Display name (label swarm-view.name) ----------
   * Swarm names containers itself (service.slot.task-id) and ignores container_name.
   * The real name stays as text in the DOM – Folder View, autostart order and Unraid's menus keep
   * working with it. CSS (::before) shows only the display name. */
  function aliasFor(sv, slot) {
    if (!sv || !sv.alias) return '';
    return (sv.desired || 0) > 1 && slot ? `${sv.alias}.${slot}` : sv.alias;
  }
  function setAlias(el, alias, realName) {
    if (!el) return;
    if (!alias) {
      if (el.hasAttribute('data-sv-alias')) { el.removeAttribute('data-sv-alias'); el.removeAttribute('title'); el.style.removeProperty('--sv-fs'); }
      return;
    }
    if (el.getAttribute('data-sv-alias') === alias) return;
    if (!el.hasAttribute('data-sv-alias')) el.style.setProperty('--sv-fs', getComputedStyle(el).fontSize || '');
    el.setAttribute('data-sv-alias', alias);
    el.setAttribute('title', `Container: ${realName}`);
  }
  /** real name → display name, for Folder View's folder previews */
  function aliasMap() {
    const m = new Map();
    for (const r of Object.values(remoteIndex)) if (r.alias) m.set(r.name, r.alias);
    for (const [name, c] of Object.entries((SV.data && SV.data.containers) || {})) {
      if (!c.swarmService) continue;
      const a = aliasFor(findService(c.swarmService), (name.slice(c.swarmService.length + 1).split('.')[0]) || '');
      if (a) m.set(name, a);
    }
    return m;
  }

  const SWARM_STOP_HINT = t('Swarm container: stopping it in Unraid does not last – Swarm starts it again. Use "Stack Down" in the Swarm section to stop it.');
  const shortSvc = (svc, stack) => (stack && svc.startsWith(stack + '_') ? svc.slice(stack.length + 1) : svc);

  function hostCell(name) {
    if (!SV.data) return '<span class="sv-dim">…</span>';
    const c = (SV.data.containers || {})[name] || {};
    if (!c.swarmService) return `<span class="sv-dim sv-small" title="${esc(t('Regular container on this server (not Swarm)'))}">${esc(t('local'))}</span>`;
    let html = localBadge(SV.data.host);
    html += `<div class="sv-dim" style="margin-top:4px;white-space:nowrap"><i class="fa fa-cubes"></i> Swarm: ${esc(c.swarmStack || '–')} / ${esc(shortSvc(c.swarmService, c.swarmStack))}</div>`;
    const sel = placeSelect(findService(c.swarmService));
    if (sel) html += `<div style="margin-top:4px">${sel}</div>`;
    return html;
  }

  /** WebUI URL like Unraid: [IP] → Unraid IP (routing mesh), [PORT:x] → published port */
  function webUiFor(sv) {
    const host = location.hostname;
    const pubFor = (target) => {
      const p = (sv.portList || []).find((x) => +x.target === +target);
      return p ? p.published : target;
    };
    const tpl = (sv.labels || {})['net.unraid.docker.webui'];
    if (tpl) return tpl.replace(/\[IP\]/g, host).replace(/\[PORT:(\d+)\]/g, (m, p) => pubFor(p));
    const first = (sv.portList || []).find((p) => p.published && p.proto === 'tcp');
    return first ? `http://${host}:${first.published}` : '';
  }

  /** All Swarm tasks NOT running on this host – with a stable name stack_service.slot */
  function remoteTasks() {
    if (!SV.data || !SV.data.swarm.active) return [];
    const managed = new Set(SV.data.managed || []);
    const out = [];
    for (const st of SV.data.stacks) {
      for (const sv of st.services) {
        for (const tk of sv.tasks) {
          if (!tk.node || tk.local || !tk.id) continue;
          const image = String(sv.image || '');
          out.push({
            elId: `sv-${tk.id.slice(0, 12)}`,
            sid: `sv${tk.id.slice(0, 10)}`, // "short id" like Unraid's: CPU/RAM spans and Folder View use it
            name: `${sv.name}.${tk.slot}`,
            taskId: tk.id,
            service: sv.name,
            stack: st.name === '(no stack)' ? '' : st.name,
            managed: managed.has(st.name),
            node: tk.node,
            running: /^running/i.test(tk.current),
            current: tk.current,
            image,
            tag: image.includes(':') ? image.split(':').pop() : 'latest',
            icon: (sv.labels || {})['net.unraid.docker.icon'] || QUESTION_ICON,
            labels: sv.labels || {},
            ports: sv.portList || [],
            mounts: (sv.mounts || []).filter((m) => m.type === 'bind'),
            webui: webUiFor(sv),
            placeHtml: placeSelect(sv),
            update: sv.update,
            canRollback: sv.canRollback,
            rollbackTo: sv.rollbackTo || '',
            stats: ((SV.data.stats || {}).tasks || {})[tk.id] || null,
            short: sv.short || sv.name,
            alias: aliasFor(sv, tk.slot),
            created: tk.created || '',
            since: tk.since || '',
            containerId: tk.containerId || '',
            networks: tk.networks || [],
          });
        }
      }
    }
    return out;
  }

  /** Row with the same markup as Unraid's DockerContainers.php (so Folder View & co. understand it) */
  function remoteRowCells(r) {
    const host = location.hostname;
    const dash = '<span class="sv-dim">–</span>';
    const ports = r.ports.map((p) => `${p.target}:${String(p.proto).toUpperCase()}`).join('<br>') || dash;
    const lan = r.ports.filter((p) => p.published).map((p) => `${esc(host)}:${p.published}`).join('<br>') || dash;
    const vols = r.mounts.map((m) =>
      `${esc(m.target)}<i class="fa fa-${m.ro ? 'long-arrow-left' : 'arrows-h'}" style="margin:0 6px"></i>${esc(m.source)}`).join('<br>') || dash;
    const nets = r.networks.map((n) => esc(n.name)).join('<br>') || dash;
    const ips = r.networks.map((n) => esc(n.addr)).filter(Boolean).join('<br>') || dash;
    const upd = updateLine(r.update);
    const by = r.image.replace(/:[^:/]*$/, '');
    return `
      <td class="ct-name" style="width:220px;padding:8px"><i class="fa fa-arrows-v mover orange-text"></i>
        <span class="outer"><span id="${r.elId}" onclick="svRemoteContext('${r.elId}')" class="hand"><img src="${esc(r.icon)}" class="img" onerror="this.onerror=null;this.src='${QUESTION_ICON}';"></span>
        <span class="inner"><span class="appname" id="${r.elId}-n" onclick="svRemoteContext('${r.elId}', this.id)"${r.alias ? ` data-sv-alias="${esc(r.alias)}" title="Container: ${esc(r.name)}"` : ''}><a class="sv-name">${esc(r.name)}</a></span><br>
          <i id="load-${r.elId}" class="fa fa-${r.running ? 'play started green-text' : 'square stopped orange-text'}"></i><span class="state" title="${esc(SWARM_STOP_HINT)}">${r.running ? esc(t('started')) : esc(r.current.split(' ')[0] || '?')}<br/>Swarm: ${esc(r.stack || '–')} / ${esc(r.short)}</span>
        </span></span>
        <div class="sv-adv" style="margin-top:8px">${esc(r.containerId ? t('Container ID: {0}', r.containerId) : t('Task ID: {0}', r.taskId.slice(0, 12)))}<br>${esc(t('By: {0}', by))}</div>
      </td>
      <td class="updatecolumn">${upd}<div class="sv-adv" style="white-space:nowrap"><i class="fa fa-info-circle fa-fw"></i> ${esc(r.tag)}</div></td>
      <td style="white-space:nowrap">${nets}</td>
      <td style="white-space:nowrap">${ips}</td>
      <td style="white-space:nowrap">${ports}</td>
      <td style="white-space:nowrap">${lan}</td>
      <td style="word-break:break-all">${vols}</td>
      <td class="sv-adv sv-load"><span class="cpu-${r.sid}">…</span><div class="usage-disk mm"><span id="cpu-${r.sid}" style="width:0"></span><span></span></div><br><span class="mem-${r.sid}">…</span></td>
      ${cfg().hostColumn ? `<td class="sv-adv sv-hostcol">${remoteBadge(r.node)}${r.placeHtml ? `<div style="margin-top:4px">${r.placeHtml}</div>` : ''}</td>` : ''}
      <td style="white-space:nowrap" title="${esc(SWARM_STOP_HINT)}"><i class="fa fa-cubes fa-fw"></i> Swarm</td>
      <td class="sv-times">${timesHtml(r)}</td>`;
  }

  /** Version column: update status (only when the update check is on) */
  function updateLine(u) {
    if (u) return `<span class="orange-text" style="white-space:nowrap"><i class="fa fa-flash fa-fw"></i> ${esc(t('Update available'))}</span>`;
    if (u === false) return `<span class="green-text" style="white-space:nowrap"><i class="fa fa-check fa-fw"></i> ${esc(t('up-to-date'))}</span>`;
    return '';
  }

  const rowSig = (r, mode) => JSON.stringify(Object.assign({}, r, { stats: null })) + mode + cfg().hostColumn;

  /* ---------- CPU/RAM: remote containers like Unraid's rows, folder totals for Folder View ---------- */
  const UNITS = { B: 1, KiB: 2 ** 10, MiB: 2 ** 20, GiB: 2 ** 30, TiB: 2 ** 40, kB: 1e3, KB: 1e3, MB: 1e6, GB: 1e9, TB: 1e12 };
  const memToB = (m) => {
    const x = String(m || '').trim().match(/^([\d.,]+)\s*([A-Za-z]*)$/);
    return x ? parseFloat(x[1].replace(',', '.')) * (UNITS[x[2] || 'B'] || 1) : 0;
  };
  const bToMem = (b) => {
    const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return b ? `${b.toFixed(2)} ${u[i]}` : '0 B';
  };
  const pct = (v) => `${(Math.round(v * 100) / 100).toFixed(2)}%`;
  const selfNode = () => (SV.data.nodes || []).find((n) => n.self) || {};
  const nodeCpus = (host) => ((SV.data.nodes || []).find((n) => n.hostname === host) || {}).cpus || 0;

  /** Values of a remote container: cpu = % of its node's CPUs (like Unraid), cores = cores in use */
  function remoteLoad(r) {
    const st = r.stats;
    if (!st) return null;
    const ncpu = st.ncpu || nodeCpus(r.node) || 1;
    const raw = parseFloat(String(st.cpu).replace(',', '.')) || 0;   // docker stats: 100 % = 1 core
    const [used, lim] = String(st.mem || '').split('/').map((x) => x.trim());
    const usedB = memToB(used);
    // "0B / 0B": Docker on that node measures no memory (memory cgroup off, typical on a Raspberry Pi)
    const noMem = !memToB(lim);
    const limB = noMem ? 0 : memToB(lim);
    return { node: r.node, ncpu, cores: raw / 100, cpu: Math.min(raw / ncpu, 100), usedB, limB, memText: st.mem || '', noMem };
  }

  function updateRemoteLoads() {
    const enabled = (SV.data.stats || {}).enabled;
    for (const r of Object.values(remoteIndex)) {
      $(`tr.sv-remote-row[data-sv-name="${r.name}"] > td.sv-times`).html(timesHtml(r));
      const l = remoteLoad(r);
      const $cpu = $(`span.cpu-${r.sid}`), $mem = $(`span.mem-${r.sid}`), $bar = $(`[id="cpu-${r.sid}"]`);
      if (!l) {
        $cpu.text(enabled ? '…' : '–'); $mem.text(enabled ? '…' : '–').attr('title', enabled ? t('No values from the agent yet') : t('The CPU/RAM agent is off in the settings'));
        $bar.css('width', 0);
        continue;
      }
      $cpu.text(pct(l.cpu)); $bar.css('width', pct(l.cpu));
      if (l.noMem) $mem.text(t('RAM not measurable')).attr('title', t('Docker on {0} measures no memory: cgroup_enable=memory is missing in /boot/firmware/cmdline.txt', r.node));
      else $mem.text(l.memText).attr('title', r.stats.memApprox
        ? t("Estimated from the processes' memory (RSS) – Docker on {0} measures no container memory (memory cgroup off)", r.node)
        : t('CPU value relative to {0} cores of {1}', l.ncpu, r.node));
    }
  }

  /**
   * Folder View computes a folder's CPU/RAM only from Unraid's local values. When it writes its sum,
   * we remember the local value and – depending on the setting – write the sum over all nodes on top.
   * The split per node goes into the tooltip.
   */
  function folderParts(id, $row) {
    const remote = $(`tr.sv-remote-row.folder-${id}-element`).get().map((tr) => {
      const r = Object.values(remoteIndex).find((x) => x.name === tr.getAttribute('data-sv-name'));
      return r ? remoteLoad(r) : null;
    }).filter(Boolean);
    const hasLocal = $(`.folder-${id}-element`).not('.sv-remote-row').length > 0;
    const $c = $row.find(`span.cpu-folder-${id}`).first(), $m = $row.find(`span.mem-folder-${id}`).first();
    const local = {
      cpu: parseFloat(String($c.attr('data-sv-local') ?? $c.text()).replace(',', '.')) || 0,
      mem: String($m.attr('data-sv-local') ?? $m.text()).split('/').map((x) => memToB(x.trim())),
    };
    return { remote, hasLocal, local, $c, $m };
  }

  function updateFolder(row) {
    const m = String(row.className).match(/(?:^|\s)folder-id-(\S+)/);
    if (!m || !SV.data) return;
    const id = m[1];
    const $row = $(row);
    const { remote, hasLocal, local, $c, $m } = folderParts(id, $row);
    const $td = $row.children('td.folder-advanced');
    const anyRemote = $(`tr.sv-remote-row.folder-${id}-element`).length > 0;
    if (!anyRemote) { $td.removeAttr('title'); return; }

    // sum up per node
    const me = selfNode();
    const per = new Map();
    if (hasLocal) per.set(me.hostname || t('local'), { cores: local.cpu / 100 * (me.cpus || 1), ncpu: me.cpus || 1, used: local.mem[0] || 0, lim: local.mem[1] || 0 });
    for (const l of remote) {
      const e = per.get(l.node) || { cores: 0, ncpu: l.ncpu, used: 0, lim: 0, noMem: false };
      e.cores += l.cores; e.used += l.usedB; e.lim = Math.max(e.lim, l.limB); e.noMem = e.noMem || l.noMem;
      per.set(l.node, e);
    }
    const lines = [...per].map(([n, e]) => `${n}: ${t('{0} of {1} cores', pct(e.cores / e.ncpu * 100), e.ncpu)} · `
      + (e.noMem ? t('RAM not measurable (cgroup_enable=memory missing)') : `${bToMem(e.used)}${e.lim ? ' / ' + bToMem(e.lim) : ''}`));
    const missing = $(`tr.sv-remote-row.folder-${id}-element`).length - remote.length;
    if (missing > 0) lines.push(`(${tn(missing, '{0} container without values', '{0} containers without values')})`);

    if (cfg().folderLoad === 'local') {
      // back to Folder View's values in case we wrote the sum before
      for (const $s of [$c, $m]) if ($s.attr('data-sv-written') && $s.text() === $s.attr('data-sv-written')) $s.text($s.attr('data-sv-local'));
      $td.attr('title', `${t('Local only. With other nodes:')}\n${lines.join('\n')}`);
      return;
    }
    let cores = 0, ncpu = 0, used = 0, lim = 0;
    for (const e of per.values()) { cores += e.cores; ncpu += e.ncpu; if (!e.noMem) { used += e.used; lim += e.lim; } }
    const cpu = ncpu ? Math.min(cores / ncpu * 100, 100) : 0;
    const cpuText = pct(cpu), memText = `${bToMem(used)} / ${bToMem(lim)}`;
    if ($c.attr('data-sv-local') === undefined) $c.attr('data-sv-local', $c.text());
    if ($m.attr('data-sv-local') === undefined) $m.attr('data-sv-local', $m.text());
    $c.attr('data-sv-written', cpuText).text(cpuText);
    $m.attr('data-sv-written', memText).text(memText);
    $row.find(`[id="cpu-folder-${id}"]`).css('width', cpuText);
    $td.attr('title', `${tn(per.size, 'Total over {0} node', 'Total over {0} nodes')} (${t('{0} cores', ncpu)}):\n${lines.join('\n')}`);
  }

  function watchFolder(row) {
    if (row.__svLoadObs) return;
    const obs = new MutationObserver((muts) => {
      for (const mu of muts) {
        const span = mu.target.nodeType === 1 ? mu.target : mu.target.parentElement;
        if (!span || !/(?:^|\s)(cpu|mem)-folder-/.test(span.className)) continue;
        // not our own write → Folder View delivered new local values
        if (span.textContent !== span.getAttribute('data-sv-written')) span.setAttribute('data-sv-local', span.textContent);
      }
      if (muts.some((mu) => { const sp = mu.target.nodeType === 1 ? mu.target : mu.target.parentElement; return sp && sp.textContent !== sp.getAttribute('data-sv-written'); })) updateFolder(row);
    });
    const td = $(row).children('td.folder-advanced').get(0);
    if (!td) return;
    obs.observe(td, { childList: true, characterData: true, subtree: true });
    row.__svLoadObs = obs;
  }

  function updateLoads() {
    if (!SV.data) return;
    updateRemoteLoads();
    $('#docker_list > tr.folder').each(function () { watchFolder(this); updateFolder(this); });
  }

  /** Keep remote containers in the DOM up to date – rows are kept (even when Folder View moved them into folders) */
  function syncRemoteRows() {
    const list = document.getElementById('docker_list');
    if (!list) return;
    const mode = cfg().remoteRows;
    const tasks = mode === 'never' ? [] : remoteTasks();
    remoteIndex = Object.fromEntries(tasks.map((r) => [r.elId, r]));
    const want = new Map(tasks.map((r) => [r.name, r]));

    $(list).find('tr.sv-remote-row').each(function () {
      const name = this.getAttribute('data-sv-name');
      const r = want.get(name);
      if (!r) { $(this).remove(); return; }
      const sig = rowSig(r, mode);
      $(this).toggleClass('sv-adv', mode === 'advanced');
      if (this.getAttribute('data-sv-sig') !== sig) {
        this.innerHTML = remoteRowCells(r);
        this.setAttribute('data-sv-sig', sig);
      }
      want.delete(name);
    });
    for (const r of want.values()) {
      const tr = document.createElement('tr');
      tr.className = 'sortable sv-remote-row' + (mode === 'advanced' ? ' sv-adv' : '');
      tr.setAttribute('data-sv-name', r.name);
      tr.setAttribute('data-sv-sig', rowSig(r, mode));
      tr.innerHTML = remoteRowCells(r);
      list.appendChild(tr);
    }
    // show the mover only when Unraid's sorting is unlocked
    $(list).find('tr.sv-remote-row i.mover').toggle($.cookie && $.cookie('lockbutton') != null);
  }

  /**
   * Adapt a Folder View folder row to the host column.
   * Folder View sets the width of its wide cell to "number of columns − 5", or fixed to 6 with the folder
   * option "update column". Both assume 10 columns. Instead of a fixed number, the folder's CPU cell is
   * moved to the position of the "CPU & Memory load" column and an empty host cell is added after it.
   */
  function fixFolderRow(tr, $tbl) {
    const ths = $tbl.find('thead tr').first().children('th').get();
    const loadIdx = ths.findIndex((th) => th.classList.contains('load'));
    const cpu = $(tr).children('td.folder-advanced').get(0);
    if (loadIdx < 0 || !cpu) return;
    let pos = 0;
    for (const td of $(tr).children('td').get()) {
      if (td === cpu) break;
      pos += +td.getAttribute('colspan') || 1;
    }
    const diff = loadIdx - pos;
    if (diff) {
      const wide = $(tr).children('td').filter((i, td) => $(td).children('div.folder-storage').length).get(0)
        || $(cpu).prevAll('td[colspan]').get(0);
      if (wide) wide.setAttribute('colspan', Math.max(1, (+wide.getAttribute('colspan') || 1) + diff));
    }
    if (!$(cpu).next('td.sv-hostcol').length) $(cpu).after('<td class="sv-adv sv-hostcol"></td>');
  }

  /** Local Swarm containers in Unraid's list: update status, start/stop hint, display name */
  function localSwarmRow($tr) {
    const name = $tr.find('.appname').first().text().trim();
    const c = SV.data && (SV.data.containers || {})[name];
    if (!c || !c.swarmService) return;
    const sv = findService(c.swarmService) || {};
    const $u = $tr.children('td.updatecolumn');
    const html = updateLine(sv.update);
    let $line = $u.children('div.sv-updline');
    if (!$line.length) $line = $('<div class="sv-updline"></div>').prependTo($u);
    if ($line.html() !== html) $line.html(html);
    $tr.find('td.ct-name span.state').first().attr('title', SWARM_STOP_HINT);
    setAlias($tr.find('td.ct-name .appname').get(0), aliasFor(sv, (name.slice(c.swarmService.length + 1).split('.')[0]) || ''), name);
  }

  /* ---------- Hide orphan images (Unraid: tr.advanced with addDockerImageContext) ---------- */
  const ORPHAN_KEY = 'swarmview_orphans_show';
  const normImg = (s) => String(s || '').trim().replace(/@sha256:[0-9a-f]+$/i, '').replace(/^docker\.io\//, '').replace(/^library\//, '')
    .replace(/^([^/:]+)$/, '$1:latest').replace(/^([^:]*\/[^/:]+)$/, '$1:latest');
  function hideOrphans($tbl) {
    const mode = cfg().orphans || 'swarm';
    const $rows = $('#docker_list > tr').filter(function () { return $(this).find('span[onclick^="addDockerImageContext"]').length > 0; });
    $('#docker_list > tr.sv-orphan-sum').remove();
    $rows.removeClass('sv-orphan-hidden');
    if (mode === 'show' || !$rows.length) return;
    const swarmImgs = new Set();
    for (const st of (SV.data && SV.data.stacks) || []) for (const sv of st.services) swarmImgs.add(normImg(sv.image));
    let reserve = 0, old = 0, other = 0;
    const hide = $rows.filter(function () {
      const m = String($(this).find('span[onclick^="addDockerImageContext"]').attr('onclick')).match(/addDockerImageContext\('[^']*','([^']*)'\)/);
      const tags = (m ? m[1] : '').split(',').map((x) => x.trim()).filter(Boolean);
      if (!tags.length) { old++; return true; }
      if (tags.some((x) => swarmImgs.has(normImg(x)))) { reserve++; return true; }
      if (mode === 'hide') { other++; return true; }
      return false;
    });
    if (!hide.length) return;
    let show = false;
    try { show = sessionStorage.getItem(ORPHAN_KEY) === '1'; } catch (e) { /* ignore */ }
    if (!show) hide.addClass('sv-orphan-hidden');
    const parts = [reserve && tn(reserve, '{0} reserve for Swarm services', '{0} reserve for Swarm services'),
      old && tn(old, '{0} old version without name', '{0} old versions without name'), other && t('{0} other', other)].filter(Boolean);
    const cols = $tbl.find('thead tr').first().children('th').length || 10;
    const $sum = $(`<tr class="sv-orphan-sum advanced"><td colspan="${cols}"><a href="#" class="sv-orphan-toggle"><i class="fa fa-eye${show ? '-slash' : ''} fa-fw"></i>
      ${esc(show ? tn(hide.length, '{0} orphan image shown', '{0} orphan images shown') : tn(hide.length, '{0} orphan image hidden', '{0} orphan images hidden'))}</a> <span class="sv-dim">(${esc(parts.join(', '))}) · ${esc(show ? t('hide again') : t('show'))}</span></td></tr>`);
    $sum.insertBefore(hide.first());
    // Unraid toggles .advanced via jQuery when the view changes – the row follows the view like the image rows
    $sum.toggle(isAdvancedView());
  }
  const isAdvancedView = () => $('body').hasClass('sv-advanced');
  $(document).on('click', '.sv-orphan-toggle, .sv-orphan-sum .sv-dim', function (e) {
    e.preventDefault();
    let show = false;
    try { show = sessionStorage.getItem(ORPHAN_KEY) === '1'; sessionStorage.setItem(ORPHAN_KEY, show ? '0' : '1'); } catch (er) { /* ignore */ }
    hideOrphans($('#docker_containers'));
  });

  function decorateDockerTable() {
    const list = document.getElementById('docker_list');
    const $tbl = $('#docker_containers');
    if (!list || !$tbl.length) return;
    if (observer) observer.disconnect();
    hookFolderView();

    const withHost = cfg().hostColumn;
    if (!withHost) {
      // remove the column (if it was active before)
      $tbl.find('thead th.sv-hostcol').remove();
      $('#docker_list > tr:not(.sv-remote-row)').children('td.sv-hostcol').remove();
    }
    // header: "Host" after "CPU & Memory load"
    if (withHost && !$tbl.find('thead th.sv-hostcol').length) {
      const $load = $tbl.find('thead th.load');
      const $th = $('<th class="sv-adv sv-hostcol">Host</th>');
      if ($load.length) $load.after($th); else $tbl.find('thead th').eq(7).after($th);
    }

    $('#docker_list > tr').each(function () {
      const $tr = $(this);
      if ($tr.hasClass('sv-remote-row')) return;                              // our own rows
      if ($tr.hasClass('folder')) { if (withHost) fixFolderRow(this, $tbl); return; } // Folder View folder
      if ($tr.hasClass('sortable') && /^swarm-view-(agent|cleanup)\./.test($tr.find('.appname').first().text().trim())) {
        $tr.hide().next('tr.advanced').hide();
        return;
      }
      if ($tr.hasClass('sortable')) {
        localSwarmRow($tr);
        if (!withHost) return;
        let $td = $tr.children('td.sv-hostcol');
        if (!$td.length) {
          $td = $('<td class="sv-adv sv-hostcol"></td>');
          const $cpu = $tr.children('td.advanced').last();
          ($cpu.length ? $cpu : $tr.children('td').eq(7)).after($td);
        }
        $td.html(hostCell($tr.find('.appname').first().text().trim()));
      } else if (withHost && !this.svFixed) {
        const $c = $tr.children('td[colspan]').last();
        if ($c.length) $c.attr('colspan', (+$c.attr('colspan') || 1) + 1);
        this.svFixed = true;
      }
    });

    syncRemoteRows();
    updateLoads();
    hideOrphans($tbl);
    // font size for display names in our own rows: take it from a normal name
    const ref = $('#docker_list tr:not(.sv-remote-row) .appname:not([data-sv-alias])').get(0);
    const fs = ref ? getComputedStyle(ref).fontSize : '';
    $('#docker_list tr.sv-remote-row .appname[data-sv-alias]').each(function () {
      if (!this.style.getPropertyValue('--sv-fs')) this.style.setProperty('--sv-fs', fs || getComputedStyle(this.parentElement).fontSize);
    });
    // rename Folder View's folder previews (cloned names) as well
    const am = aliasMap();
    $('#docker_list .folder-preview span.appname').each(function () {
      const real = $(this).text().trim();
      setAlias(this, am.get(real) || (this.hasAttribute('data-sv-alias') ? '' : null), real);
    });

    if (!observer) observer = new MutationObserver(() => decorateDockerTable());
    observer.observe(list, { childList: true });
  }

  /* ---------- Context menu for remote containers (like Unraid's addDockerContainerContext) ---------- */
  window.svRemoteContext = function (elId, target) {
    const r = remoteIndex[elId];
    if (!r || typeof context === 'undefined') return;
    const opts = [];
    if (r.running && r.webui) {
      opts.push({ text: 'WebUI', icon: 'fa-globe', action: (e) => { e.preventDefault(); window.open(r.webui, '_blank'); } });
      opts.push({ divider: true });
    }
    if (r.update) opts.push({ text: 'Update', icon: 'fa-flash', action: (e) => { e.preventDefault(); serviceAction('svc-update', r.service); } });
    if (r.canRollback) opts.push({ text: t('Rollback to {0}', r.rollbackTo || t('previous version')), icon: 'fa-undo', action: (e) => { e.preventDefault(); serviceAction('svc-rollback', r.service); } });
    opts.push({
      text: t('Restart'), icon: 'fa-refresh', action: (e) => {
        e.preventDefault();
        confirmBox(t('Restart "{0}"?', r.service), t('The service is rolled out again with --force (all replicas).'), () => {
          post({ action: 'svc-restart', service: r.service })
            .done((res) => showResult(t('Restart: {0}', r.service), res))
            .fail((x) => showResult(t('Restart: {0}', r.service), failResult(x)))
            .always(() => { SV.load(); setTimeout(SV.load, 4000); });
        });
      },
    });
    const svObj = findService(r.service);
    if (svObj && svObj.mode !== 'global' && SV.data.nodes.length > 1) {
      for (const n of SV.data.nodes) {
        if (n.hostname === r.node || !nodeCheck(svObj, n).ok) continue;
        opts.push({ text: t('Move to {0} (fixed)', n.hostname), icon: 'fa-exchange', action: (e) => { e.preventDefault(); placeService(svObj.name, `fixed:${n.hostname}`); } });
      }
    }
    opts.push({ divider: true });
    opts.push({ text: 'Logs', icon: 'fa-navicon', action: (e) => { e.preventDefault(); openLogs(r); } });
    if (r.managed) {
      opts.push({
        text: t('Edit stack'), icon: 'fa-wrench', action: (e) => {
          e.preventDefault();
          openEditor(r.stack);
        },
      });
    }
    opts.push({ divider: true });
    opts.push({ text: t('Runs on: {0}', r.node), icon: 'fa-external-link', action: (e) => e.preventDefault() });
    context.settings({ right: false, above: false });
    const sel = '#' + (target || elId);
    context.destroy(sel);
    context.attach(sel, opts);
  };

  /* ---------- Log window ---------- */
  let logTask = null;
  function ensureLogBox() {
    if ($('#sv-logbox').length) return;
    $('body').append(`<div id="sv-logbox" style="display:none">
      <div class="sv-logbox-inner">
        <div class="sv-logbox-head"><b id="sv-logbox-title"></b>
          <span class="sv-right">
            <input type="button" id="sv-logbox-refresh" value="${esc(t('Refresh'))}">
            <input type="button" id="sv-logbox-close" value="${esc(t('Close'))}">
          </span></div>
        <pre id="sv-logbox-text" class="sv-log"></pre>
      </div></div>`);
    $('#sv-logbox-close').on('click', () => { $('#sv-logbox').hide(); logTask = null; });
    $('#sv-logbox-refresh').on('click', () => logTask && fetchLogs(logTask));
    $('#sv-logbox').on('click', (e) => { if (e.target.id === 'sv-logbox') $('#sv-logbox-close').trigger('click'); });
  }
  function fetchLogs(r) {
    $('#sv-logbox-text').text(t('Loading logs …'));
    post({ action: 'logs', task: r.taskId, tail: 500 })
      .done((res) => {
        const $p = $('#sv-logbox-text').text(res.log || res.error || '');
        $p.scrollTop($p[0].scrollHeight);
      })
      .fail((x) => $('#sv-logbox-text').text(failResult(x).error));
  }
  function openLogs(r) {
    ensureLogBox();
    logTask = r;
    $('#sv-logbox-title').text(`${r.name} @ ${r.node}`);
    // use the theme background (light/dark)
    const bg = getComputedStyle(document.body).backgroundColor;
    if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) $('#sv-logbox .sv-logbox-inner').css('background', bg);
    $('#sv-logbox').show();
    fetchLogs(r);
  }

  /* ---------- Folder View: add remote containers to its container list ---------- */
  function hookFolderView() {
    let fe;
    try { fe = folderEvents; } catch (e) { return; } // Folder View (2) not installed
    if (!fe || fe.__svHooked) return;
    fe.__svHooked = true;
    fe.addEventListener('docker-pre-folders-creation', (ev) => {
      const { order, containersInfo, folders } = ev.detail || {};
      if (!Array.isArray(order) || !containersInfo) return;
      for (const r of Object.values(remoteIndex)) {
        if (!containersInfo[r.name]) {
          containersInfo[r.name] = {
            Id: `swarm-${r.taskId}`,
            shortId: r.sid,
            shortImageId: '',
            Labels: r.labels,
            Mounts: r.mounts.map((m) => ({ Type: 'bind', Source: m.source, Destination: m.target })),
            info: {
              Name: r.name,
              State: { Running: r.running, Paused: false, Autostart: false, Updated: true, manager: 'swarm', WebUi: r.webui, TSWebUi: '' },
              Config: { Image: r.image },
              Ports: r.ports.map((p) => ({ PrivatePort: p.target, PublicPort: p.published, Type: p.proto })),
              registry: '', Support: '', Project: '', ReadMe: '', DonateLink: '', Shell: 'sh', template: null,
            },
          };
        }
        if (!order.includes(r.name)) order.push(r.name);
      }
      // display names for folders: a folder's regex or container list may also mean the display name.
      // For that the real name is added to the folder's list in memory only (never saved).
      const am = aliasMap();
      if (folders && am.size) {
        for (const f of Object.values(folders)) {
          if (!f) continue;
          if (!Array.isArray(f.containers)) f.containers = [];
          let re = null;
          try { if (f.regex && String(f.regex).trim()) re = new RegExp(f.regex); } catch (e) { re = null; }
          for (const [real, alias] of am) {
            if (!order.includes(real) || f.containers.includes(real)) continue;
            if ((re && !re.test(real) && re.test(alias)) || f.containers.includes(alias)) f.containers.push(real);
          }
        }
      }
    });
  }

  Object.assign(SV, { decorateDockerTable, remoteTasks, hookFolderView, openLogs });
  SV.start();
})(jQuery);

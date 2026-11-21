/* Swarm View – Unraids Container-Liste: Host-Spalte, Container anderer Nodes, Kontextmenü, Logs, Folder View */
(function ($) {
  'use strict';

  const SV = window.SwarmView;
  const { esc, cfg, localBadge, remoteBadge, findService, nodeCheck, placeSelect, placeService, post, failResult, showResult, confirmBox, openEditor, serviceAction } = SV;

  /* ---------- 2) Unraids Container-Tabelle erweitern ---------- */
  let observer = null;
  let remoteIndex = {};      // Element-ID → Task-Infos (für Kontextmenü/Logs)
  const QUESTION_ICON = '/plugins/dynamix.docker.manager/images/question.png';

  /* ---------- Zeiten auf Deutsch (wie Unraid: „Betriebszeit: 54 Minuten“, „Erstellt: vor 54 Minuten“) ---------- */
  /** Wie Dockers HumanDuration (daraus übersetzt Unraid „Up 54 minutes“), nur auf Deutsch; dative = „vor 2 Tagen“ */
  function durationDe(sec, dative) {
    const pl = (n, one, many, manyDat) => `${n} ${n === 1 ? one : dative ? manyDat : many}`;
    sec = Math.max(0, sec);
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
  const since = (iso) => { const t = Date.parse(iso || ''); return isNaN(t) ? null : (Date.now() - t) / 1000; };
  const timesHtml = (r) => {
    const up = since(r.since), cr = since(r.created);
    const first = r.running ? (up != null ? `Betriebszeit: ${durationDe(up)}` : 'läuft')
      : `${esc(String(r.current).split(' ')[0] || 'Beendet')}${up != null ? `: vor ${durationDe(up, true)}` : ''}`;
    return `<div style="white-space:nowrap">${first}${cr != null ? `<div style="margin-top:4px">Erstellt: vor ${durationDe(cr, true)}</div>` : ''}</div>`;
  };

  /* ---------- Anzeigename (Label swarm-view.name) ----------
   * Swarm vergibt Containernamen selbst (service.slot.task-id), container_name wird ignoriert.
   * Der echte Name bleibt als Text im DOM – Folder View, Autostart-Reihenfolge und Unraids Menüs
   * arbeiten damit weiter. Angezeigt wird per CSS (::before) nur der Anzeigename. */
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
  /** echter Name → Anzeigename, für Ordner-Vorschauen von Folder View */
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

  const SWARM_STOP_HINT = 'Swarm-Container: Stoppen über Unraid hält nicht – Swarm startet ihn neu. Zum Anhalten „Stack Down“ in der Swarm-Leiste nutzen.';
  const shortSvc = (svc, stack) => (stack && svc.startsWith(stack + '_') ? svc.slice(stack.length + 1) : svc);

  function hostCell(name) {
    if (!SV.data) return '<span class="sv-dim">…</span>';
    const c = (SV.data.containers || {})[name] || {};
    if (!c.swarmService) return '<span class="sv-dim sv-small" title="Normaler Container auf diesem Server (nicht Swarm)">lokal</span>';
    let html = localBadge(SV.data.host);
    html += `<div class="sv-dim" style="margin-top:4px;white-space:nowrap"><i class="fa fa-cubes"></i> Swarm: ${esc(c.swarmStack || '–')} / ${esc(shortSvc(c.swarmService, c.swarmStack))}</div>`;
    const sel = placeSelect(findService(c.swarmService));
    if (sel) html += `<div style="margin-top:4px">${sel}</div>`;
    return html;
  }

  /** WebUI-URL wie bei Unraid: [IP] → Unraid-IP (Routing-Mesh), [PORT:x] → veröffentlichter Port */
  function webUiFor(sv) {
    const host = location.hostname;
    const pubFor = (target) => {
      const p = (sv.portList || []).find((x) => +x.target === +target);
      return p ? p.published : target;
    };
    const tpl = (sv.labels || {})['net.unraid.docker.webui'];
    if (tpl) return tpl.replace(/\[IP\]/g, host).replace(/\[PORT:(\d+)\]/g, (m, t) => pubFor(t));
    const first = (sv.portList || []).find((p) => p.published && p.proto === 'tcp');
    return first ? `http://${host}:${first.published}` : '';
  }

  /** Alle Swarm-Tasks, die NICHT auf diesem Host laufen – mit stabilem Namen stack_service.slot */
  function remoteTasks() {
    if (!SV.data || !SV.data.swarm.active) return [];
    const managed = new Set(SV.data.managed || []);
    const out = [];
    for (const st of SV.data.stacks) {
      for (const sv of st.services) {
        for (const t of sv.tasks) {
          if (!t.node || t.local || !t.id) continue;
          const image = String(sv.image || '');
          out.push({
            elId: `sv-${t.id.slice(0, 12)}`,
            sid: `sv${t.id.slice(0, 10)}`, // „Kurz-ID“ wie bei Unraid: CPU/RAM-Spans und Folder View hängen daran
            name: `${sv.name}.${t.slot}`,
            taskId: t.id,
            service: sv.name,
            stack: st.name === '(ohne Stack)' ? '' : st.name,
            managed: managed.has(st.name),
            node: t.node,
            running: /^running/i.test(t.current),
            current: t.current,
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
            stats: ((SV.data.stats || {}).tasks || {})[t.id] || null,
            short: sv.short || sv.name,
            alias: aliasFor(sv, t.slot),
            created: t.created || '',
            since: t.since || '',
            containerId: t.containerId || '',
            networks: t.networks || [],
          });
        }
      }
    }
    return out;
  }

  /** Zeile im selben Markup wie Unraids DockerContainers.php (damit Folder View & Co. sie verstehen) */
  function remoteRowCells(r) {
    const host = location.hostname;
    const dash = '<span class="sv-dim">–</span>';
    const ports = r.ports.map((p) => `${p.target}:${String(p.proto).toUpperCase()}`).join('<br>') || dash;
    const lan = r.ports.filter((p) => p.published).map((p) => `${esc(host)}:${p.published}`).join('<br>') || dash;
    const vols = r.mounts.map((m) =>
      `${esc(m.target)}<i class="fa fa-${m.ro ? 'long-arrow-left' : 'arrows-h'}" style="margin:0 6px"></i>${esc(m.source)}`).join('<br>') || dash;
    const nets = r.networks.map((n) => esc(n.name)).join('<br>') || dash;
    const ips = r.networks.map((n) => esc(n.addr)).filter(Boolean).join('<br>') || dash;
    const upd = r.update ? '<span class="orange-text" style="white-space:nowrap"><i class="fa fa-flash fa-fw"></i> Update verfügbar</span>'
      : r.update === false ? '<span class="green-text" style="white-space:nowrap"><i class="fa fa-check fa-fw"></i> aktuell</span>' : '';
    const by = r.image.replace(/:[^:/]*$/, '');
    return `
      <td class="ct-name" style="width:220px;padding:8px"><i class="fa fa-arrows-v mover orange-text"></i>
        <span class="outer"><span id="${r.elId}" onclick="svRemoteContext('${r.elId}')" class="hand"><img src="${esc(r.icon)}" class="img" onerror="this.onerror=null;this.src='${QUESTION_ICON}';"></span>
        <span class="inner"><span class="appname" id="${r.elId}-n" onclick="svRemoteContext('${r.elId}', this.id)"${r.alias ? ` data-sv-alias="${esc(r.alias)}" title="Container: ${esc(r.name)}"` : ''}><a class="sv-name">${esc(r.name)}</a></span><br>
          <i id="load-${r.elId}" class="fa fa-${r.running ? 'play started green-text' : 'square stopped orange-text'}"></i><span class="state" title="${esc(SWARM_STOP_HINT)}">${r.running ? 'Gestartet' : esc(r.current.split(' ')[0] || '?')}<br/>Swarm: ${esc(r.stack || '–')} / ${esc(r.short)}</span>
        </span></span>
        <div class="sv-adv" style="margin-top:8px">${r.containerId ? `Container-ID: ${esc(r.containerId)}` : `Task-ID: ${esc(r.taskId.slice(0, 12))}`}<br>Von: ${esc(by)}</div>
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

  const rowSig = (r, mode) => JSON.stringify(Object.assign({}, r, { stats: null })) + mode + cfg().hostColumn;

  /* ---------- CPU/RAM: Pi-Container wie Unraids Zeilen, Ordner-Summen für Folder View ---------- */
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

  /** Werte eines Pi-Containers: cpu = % der CPUs seines Nodes (wie Unraid), cores = belegte Kerne */
  function remoteLoad(r) {
    const st = r.stats;
    if (!st) return null;
    const ncpu = st.ncpu || nodeCpus(r.node) || 1;
    const raw = parseFloat(String(st.cpu).replace(',', '.')) || 0;   // docker stats: 100 % = 1 Kern
    const [used, lim] = String(st.mem || '').split('/').map((x) => x.trim());
    const usedB = memToB(used);
    // "0B / 0B": Docker auf dem Node misst keinen Speicher (cgroup_enable=memory fehlt, typisch beim Pi)
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
        $cpu.text(enabled ? '…' : '–'); $mem.text(enabled ? '…' : '–').attr('title', enabled ? 'Noch keine Werte vom Agenten' : 'CPU/RAM-Agent ist in den Einstellungen aus');
        $bar.css('width', 0);
        continue;
      }
      $cpu.text(pct(l.cpu)); $bar.css('width', pct(l.cpu));
      if (l.noMem) $mem.text('RAM nicht messbar').attr('title', `Docker auf ${r.node} misst keinen Speicher: cgroup_enable=memory fehlt in /boot/firmware/cmdline.txt`);
      else $mem.text(l.memText).attr('title', r.stats.memApprox
        ? `Geschätzt aus dem Speicher der Prozesse (RSS) – Docker auf ${r.node} misst keinen Container-Speicher (Speicher-cgroup aus)`
        : `CPU-Wert bezogen auf ${l.ncpu} Kerne von ${r.node}`);
    }
  }

  /**
   * Folder View rechnet CPU/RAM eines Ordners nur aus Unraids lokalen Messwerten. Wenn es seine Summe
   * schreibt, merken wir uns den lokalen Wert und schreiben – je nach Einstellung – die Summe über alle
   * Nodes darüber. Die Aufteilung pro Node steht im Tooltip.
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

    // Pro Node zusammenfassen
    const me = selfNode();
    const per = new Map();
    if (hasLocal) per.set(me.hostname || 'lokal', { cores: local.cpu / 100 * (me.cpus || 1), ncpu: me.cpus || 1, used: local.mem[0] || 0, lim: local.mem[1] || 0 });
    for (const l of remote) {
      const e = per.get(l.node) || { cores: 0, ncpu: l.ncpu, used: 0, lim: 0, noMem: false };
      e.cores += l.cores; e.used += l.usedB; e.lim = Math.max(e.lim, l.limB); e.noMem = e.noMem || l.noMem;
      per.set(l.node, e);
    }
    const lines = [...per].map(([n, e]) => `${n}: ${pct(e.cores / e.ncpu * 100)} von ${e.ncpu} Kernen · `
      + (e.noMem ? 'RAM nicht messbar (cgroup_enable=memory fehlt)' : `${bToMem(e.used)}${e.lim ? ' / ' + bToMem(e.lim) : ''}`));
    const missing = $(`tr.sv-remote-row.folder-${id}-element`).length - remote.length;
    if (missing > 0) lines.push(`(${missing} Container ohne Messwerte)`);

    if (cfg().folderLoad === 'local') {
      // Zurück zu Folder Views Werten, falls wir vorher die Summe geschrieben hatten
      for (const $s of [$c, $m]) if ($s.attr('data-sv-written') && $s.text() === $s.attr('data-sv-written')) $s.text($s.attr('data-sv-local'));
      $td.attr('title', `Nur lokal. Mit anderen Nodes:\n${lines.join('\n')}`);
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
    $td.attr('title', `Gesamt über ${per.size} Node${per.size === 1 ? '' : 's'} (${ncpu} Kerne):\n${lines.join('\n')}`);
  }

  function watchFolder(row) {
    if (row.__svLoadObs) return;
    const obs = new MutationObserver((muts) => {
      for (const mu of muts) {
        const span = mu.target.nodeType === 1 ? mu.target : mu.target.parentElement;
        if (!span || !/(?:^|\s)(cpu|mem)-folder-/.test(span.className)) continue;
        // Nicht unser eigener Schreibvorgang → Folder View hat neue lokale Werte geliefert
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

  /** Pi-Container im DOM aktuell halten – Zeilen bleiben erhalten (auch wenn Folder View sie in Ordner verschoben hat) */
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
    // Mover nur zeigen, wenn Unraids Sortierung entsperrt ist
    $(list).find('tr.sv-remote-row i.mover').toggle($.cookie && $.cookie('lockbutton') != null);
  }

  /**
   * Folder-View-Ordnerzeile an die Host-Spalte anpassen (F2).
   * Folder View setzt die Breite seiner Sammelzelle auf „Spaltenzahl − 5“ bzw. bei der Ordner-Option
   * „Update-Spalte“ fest auf 6. Beides geht von 10 Spalten aus. Statt mit einer festen Zahl zu rechnen,
   * wird die CPU-Zelle des Ordners auf die Position der Spalte „Prozessor/Speicher“ geschoben und
   * dahinter eine leere Host-Zelle eingefügt.
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

  /** Lokale Swarm-Container in Unraids Liste: Update-Status (C5) und Hinweis zu Start/Stopp (C6) */
  function localSwarmRow($tr) {
    const name = $tr.find('.appname').first().text().trim();
    const c = SV.data && (SV.data.containers || {})[name];
    if (!c || !c.swarmService) return;
    const sv = findService(c.swarmService) || {};
    const $u = $tr.children('td.updatecolumn');
    const html = sv.update ? '<span class="orange-text" style="white-space:nowrap"><i class="fa fa-flash fa-fw"></i> Update verfügbar</span>'
      : sv.update === false ? '<span class="green-text" style="white-space:nowrap"><i class="fa fa-check fa-fw"></i> aktuell</span>' : '';
    let $line = $u.children('div.sv-updline');
    if (!$line.length) $line = $('<div class="sv-updline"></div>').prependTo($u);
    if ($line.html() !== html) $line.html(html);
    $tr.find('td.ct-name span.state').first().attr('title', SWARM_STOP_HINT);
    setAlias($tr.find('td.ct-name .appname').get(0), aliasFor(sv, (name.slice(c.swarmService.length + 1).split('.')[0]) || ''), name);
  }

  /* ---------- Verwaiste Images (Unraid: tr.advanced mit addDockerImageContext) ausblenden ---------- */
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
      const tags = (m ? m[1] : '').split(',').map((t) => t.trim()).filter(Boolean);
      if (!tags.length) { old++; return true; }
      if (tags.some((t) => swarmImgs.has(normImg(t)))) { reserve++; return true; }
      if (mode === 'hide') { other++; return true; }
      return false;
    });
    if (!hide.length) return;
    let show = false;
    try { show = sessionStorage.getItem(ORPHAN_KEY) === '1'; } catch (e) { /* egal */ }
    if (!show) hide.addClass('sv-orphan-hidden');
    const parts = [reserve && `${reserve} Reserve für Swarm-Services`, old && `${old} alte Version${old === 1 ? '' : 'en'} ohne Namen`, other && `${other} weitere`].filter(Boolean);
    const cols = $tbl.find('thead tr').first().children('th').length || 10;
    const $sum = $(`<tr class="sv-orphan-sum advanced"><td colspan="${cols}"><a href="#" class="sv-orphan-toggle"><i class="fa fa-eye${show ? '-slash' : ''} fa-fw"></i>
      ${hide.length} verwaiste Image${hide.length === 1 ? '' : 's'} ${show ? 'eingeblendet' : 'ausgeblendet'}</a> <span class="sv-dim">(${parts.join(', ')}) · ${show ? 'wieder ausblenden' : 'anzeigen'}</span></td></tr>`);
    $sum.insertBefore(hide.first());
    // Unraid blendet .advanced beim Ansichtswechsel per jQuery ein/aus – die Zeile folgt der Ansicht wie die Image-Zeilen
    $sum.toggle(isAdvancedView());
  }
  const isAdvancedView = () => $('body').hasClass('sv-advanced');
  $(document).on('click', '.sv-orphan-toggle, .sv-orphan-sum .sv-dim', function (e) {
    e.preventDefault();
    let show = false;
    try { show = sessionStorage.getItem(ORPHAN_KEY) === '1'; sessionStorage.setItem(ORPHAN_KEY, show ? '0' : '1'); } catch (er) { /* egal */ }
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
      // Spalte (falls vorher aktiv) wieder entfernen
      $tbl.find('thead th.sv-hostcol').remove();
      $('#docker_list > tr:not(.sv-remote-row)').children('td.sv-hostcol').remove();
    }
    // Kopfzeile: "Host" hinter "CPU & Memory load"
    if (withHost && !$tbl.find('thead th.sv-hostcol').length) {
      const $load = $tbl.find('thead th.load');
      const $th = $('<th class="sv-adv sv-hostcol">Host</th>');
      if ($load.length) $load.after($th); else $tbl.find('thead th').eq(7).after($th);
    }

    $('#docker_list > tr').each(function () {
      const $tr = $(this);
      if ($tr.hasClass('sv-remote-row')) return;                              // eigene Zeilen
      if ($tr.hasClass('folder')) { if (withHost) fixFolderRow(this, $tbl); return; } // Folder-View-Ordner
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
    // Schriftgröße für Anzeigenamen der eigenen Zeilen von einem normalen Namen übernehmen
    const ref = $('#docker_list tr:not(.sv-remote-row) .appname:not([data-sv-alias])').get(0);
    const fs = ref ? getComputedStyle(ref).fontSize : '';
    $('#docker_list tr.sv-remote-row .appname[data-sv-alias]').each(function () {
      if (!this.style.getPropertyValue('--sv-fs')) this.style.setProperty('--sv-fs', fs || getComputedStyle(this.parentElement).fontSize);
    });
    // Ordner-Vorschauen von Folder View (geklonte Namen) ebenfalls umbenennen
    const am = aliasMap();
    $('#docker_list .folder-preview span.appname').each(function () {
      const real = $(this).text().trim();
      setAlias(this, am.get(real) || (this.hasAttribute('data-sv-alias') ? '' : null), real);
    });

    if (!observer) observer = new MutationObserver(() => decorateDockerTable());
    observer.observe(list, { childList: true });
  }

  /* ---------- Kontextmenü für Pi-Container (wie Unraids addDockerContainerContext) ---------- */
  window.svRemoteContext = function (elId, target) {
    const r = remoteIndex[elId];
    if (!r || typeof context === 'undefined') return;
    const opts = [];
    if (r.running && r.webui) {
      opts.push({ text: 'WebUI', icon: 'fa-globe', action: (e) => { e.preventDefault(); window.open(r.webui, '_blank'); } });
      opts.push({ divider: true });
    }
    if (r.update) opts.push({ text: 'Update', icon: 'fa-flash', action: (e) => { e.preventDefault(); serviceAction('svc-update', r.service); } });
    if (r.canRollback) opts.push({ text: `Rollback auf ${r.rollbackTo || 'vorherige Version'}`, icon: 'fa-undo', action: (e) => { e.preventDefault(); serviceAction('svc-rollback', r.service); } });
    opts.push({
      text: 'Neustart', icon: 'fa-refresh', action: (e) => {
        e.preventDefault();
        confirmBox(`„${r.service}“ neu starten?`, 'Der Service wird mit --force neu ausgerollt (alle Replicas).', () => {
          post({ action: 'svc-restart', service: r.service })
            .done((res) => showResult(`Neustart: ${r.service}`, res))
            .fail((x) => showResult(`Neustart: ${r.service}`, failResult(x)))
            .always(() => { SV.load(); setTimeout(SV.load, 4000); });
        });
      },
    });
    const svObj = findService(r.service);
    if (svObj && svObj.mode !== 'global' && SV.data.nodes.length > 1) {
      for (const n of SV.data.nodes) {
        if (n.hostname === r.node || !nodeCheck(svObj, n).ok) continue;
        opts.push({ text: `Auf ${n.hostname} verschieben (fest)`, icon: 'fa-exchange', action: (e) => { e.preventDefault(); placeService(svObj.name, `fixed:${n.hostname}`); } });
      }
    }
    opts.push({ divider: true });
    opts.push({ text: 'Logs', icon: 'fa-navicon', action: (e) => { e.preventDefault(); openLogs(r); } });
    if (r.managed) {
      opts.push({
        text: 'Stack bearbeiten', icon: 'fa-wrench', action: (e) => {
          e.preventDefault();
          openEditor(r.stack);
        },
      });
    }
    opts.push({ divider: true });
    opts.push({ text: `Läuft auf: ${r.node}`, icon: 'fa-external-link', action: (e) => e.preventDefault() });
    context.settings({ right: false, above: false });
    const sel = '#' + (target || elId);
    context.destroy(sel);
    context.attach(sel, opts);
  };

  /* ---------- Log-Fenster ---------- */
  let logTask = null;
  function ensureLogBox() {
    if ($('#sv-logbox').length) return;
    $('body').append(`<div id="sv-logbox" style="display:none">
      <div class="sv-logbox-inner">
        <div class="sv-logbox-head"><b id="sv-logbox-title"></b>
          <span class="sv-right">
            <input type="button" id="sv-logbox-refresh" value="Aktualisieren">
            <input type="button" id="sv-logbox-close" value="Schließen">
          </span></div>
        <pre id="sv-logbox-text" class="sv-log"></pre>
      </div></div>`);
    $('#sv-logbox-close').on('click', () => { $('#sv-logbox').hide(); logTask = null; });
    $('#sv-logbox-refresh').on('click', () => logTask && fetchLogs(logTask));
    $('#sv-logbox').on('click', (e) => { if (e.target.id === 'sv-logbox') $('#sv-logbox-close').trigger('click'); });
  }
  function fetchLogs(r) {
    $('#sv-logbox-text').text('Lade Logs …');
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
    // Theme-Hintergrund übernehmen (hell/dunkel)
    const bg = getComputedStyle(document.body).backgroundColor;
    if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) $('#sv-logbox .sv-logbox-inner').css('background', bg);
    $('#sv-logbox').show();
    fetchLogs(r);
  }

  /* ---------- Folder View: Pi-Container in die Container-Liste einschleusen ---------- */
  function hookFolderView() {
    let fe;
    try { fe = folderEvents; } catch (e) { return; } // Folder View (2) nicht installiert
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
      // Anzeigenamen für Ordner: Regex oder Containerliste eines Ordners darf auch den Anzeigenamen meinen.
      // Der echte Name wird dafür nur im Speicher (nicht gespeichert) in die Liste des Ordners aufgenommen.
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

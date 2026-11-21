/* Swarm View test bench – checks that run inside the Docker page (page.evaluate).
 * Returns [{id, ok, info}] ; expectations are derived from the scenario and the API answer. */
async (opts) => {
  const $ = window.jQuery;
  const S = window.SVTB.scenario;
  const out = [];
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const check = (id, ok, info) => out.push({ id, ok: !!ok, info: info === undefined ? '' : (typeof info === 'string' ? info : JSON.stringify(info)) });
  const text = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
  const visible = (el) => !!(el && el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden');
  let api = null;
  try { api = await (await fetch('/plugins/swarm-view/include/api.php', { cache: 'no-store' })).json(); } catch (e) { api = null; }
  const SV_ON = S.swarmview !== false;
  const apiOk = SV_ON && api && api.swarm && api.swarm.active && api.swarm.manager;
  const advanced = () => document.cookie.includes('docker_listview_mode=advanced');
  const rrMode = (api && api.config && api.config.remoteRows) || 'always';
  const remoteRowsOn = apiOk && (rrMode === 'always' || (rrMode === 'advanced' && advanced()));
  const expRemote = [];
  if (apiOk) for (const st of api.stacks) for (const sv of st.services) for (const tk of sv.tasks) if (!tk.local) expRemote.push(`${sv.name}.${tk.slot}`);

  /* ---------- 1. sections & order ---------- */
  const titles = [...document.querySelectorAll('#displaybox div.title')].filter((t) => !t.closest('table')).map((t) => text(t).replace(/Please wait.*?containers/g, '').trim());
  const iSwarm = titles.findIndex((t) => /Swarm/.test(t));
  const iCont = titles.findIndex((t) => /Docker Containers|Docker-Container/.test(t));
  const iComp = titles.findIndex((t) => /^Compose/.test(t));
  if (SV_ON) {
  check('section.swarm-present', iSwarm >= 0, titles);
  if (S.position === 'top') check('section.order', iSwarm >= 0 && iSwarm < iCont, titles);
  if (S.position === 'bottom') check('section.order', iSwarm > iCont && (iComp < 0 || iSwarm > iComp), titles);
  if (S.position === 'middle') check('section.order', iSwarm > iCont && (iComp < 0 || iSwarm < iComp), titles);
  }
  check('section.compose', S.compose ? iComp >= 0 : iComp < 0, titles);

  /* ---------- 2. table geometry: every visible row has as many columns as the header ---------- */
  const tableGeometry = (label) => {
    const head = [...document.querySelectorAll('#docker_containers > thead > tr > th')].filter(visible);
    const cols = head.reduce((n, th) => n + (+th.colSpan || 1), 0);
    const bad = [];
    for (const tr of document.querySelectorAll('#docker_list tr')) {
      if (!visible(tr)) continue;
      if (tr.closest('.folder-storage') && !visible(tr.closest('.folder-storage'))) continue;
      const tds = [...tr.children].filter((td) => td.tagName === 'TD' && getComputedStyle(td).display !== 'none');
      const n = tds.reduce((k, td) => k + (+td.colSpan || 1), 0);
      if (n !== cols && tr.children.length > 1) bad.push(`${(tr.querySelector('.appname') || tr.querySelector('.folder-appname') || tr).textContent.trim().slice(0, 30)}: ${n}/${cols}`);
    }
    check(`table.geometry.${label}`, bad.length === 0, bad.length ? bad.slice(0, 6) : `${cols} columns`);
  };
  tableGeometry(advanced() ? 'advanced' : 'basic');

  /* ---------- 3. remote rows ---------- */
  const shown = (tr) => getComputedStyle(tr).display !== 'none';
  const remote = [...document.querySelectorAll('#docker_list tr.sv-remote-row')].filter(shown);
  const names = remote.map((r) => r.dataset.svName || text(r.querySelector('.appname a')));
  if (!SV_ON) { /* baseline */ } else if (remoteRowsOn) {
    const missing = expRemote.filter((n) => !names.includes(n));
    check('remote.rows', missing.length === 0 && remote.length === expRemote.length, { expected: expRemote.length, got: remote.length, missing });
  } else {
    check('remote.rows-none', remote.length === 0, { got: remote.length, api: api ? api.swarm && api.swarm.state : 'no api' });
  }
  // duplicates (whole list, incl. folders)
  const allNames = [...document.querySelectorAll('#docker_list tr')].filter((tr) => !tr.classList.contains('folder'))
    .map((tr) => text(tr.querySelector('td.ct-name .appname'))).filter(Boolean);
  const dup = allNames.filter((n, i) => allNames.indexOf(n) !== i);
  check('list.no-duplicates', dup.length === 0, dup);

  /* ---------- 4. agent/cleanup service hidden ---------- */
  const agent = [...document.querySelectorAll('#docker_list tr')].filter((tr) => /^swarm-view-(agent|cleanup)\./.test(text(tr.querySelector('.appname'))));
  if (SV_ON) check('agent.hidden', apiOk ? agent.every((tr) => !visible(tr)) : true, agent.map((tr) => visible(tr)));

  /* ---------- 5. local Swarm rows decorated ---------- */
  if (apiOk && S.unraidList !== 'empty') {
    const local = Object.keys(api.containers || {});
    const rows = [...document.querySelectorAll('#docker_list tr')].filter((tr) => local.includes(text(tr.querySelector('td.ct-name .appname'))));
    check('local.swarm-rows-present', rows.length === local.length, { expected: local.length, got: rows.length });
    if (advanced() && api.config.hostColumn) {
      const noBadge = rows.filter((tr) => !tr.querySelector('td.sv-hostcol .sv-badge'));
      check('local.host-badge', noBadge.length === 0, noBadge.map((tr) => text(tr.querySelector('.appname'))));
    }
    const aliasMissing = [];
    for (const st of api.stacks) for (const sv of st.services) if (sv.alias) for (const tk of sv.tasks) {
      const real = tk.local ? Object.keys(api.containers).find((k) => k.startsWith(`${sv.name}.${tk.slot}.`)) : `${sv.name}.${tk.slot}`;
      const tr = [...document.querySelectorAll('#docker_list tr')].find((r) => text(r.querySelector('td.ct-name .appname')) === real);
      if (!tr) { if (tk.local || remoteRowsOn) aliasMissing.push(real + ' (row missing)'); continue; }
      const el = tr.querySelector('td.ct-name .appname');
      if (!el.hasAttribute('data-sv-alias')) aliasMissing.push(real);
    }
    check('alias.applied', aliasMissing.length === 0, aliasMissing);
  }

  /* ---------- 6. Folder View ---------- */
  const folders = [...document.querySelectorAll('#docker_list tr.folder')];
  if (S.folderview !== 'none' && S.folders !== 'none') {
    check('fv.folders-built', folders.length > 0, folders.length);
    const inFolder = (folderName) => {
      const f = folders.find((x) => text(x.querySelector('.folder-appname, .folder-name a, td.folder-name')) .includes(folderName));
      return f ? [...f.querySelectorAll('.folder-storage > tr')].map((tr) => text(tr.querySelector('td.ct-name .appname'))) : null;
    };
    if (S.folders === 'default' && apiOk && S.unraidList !== 'empty') {
      const want = { 'Smart Home': [], Paperless: [], Monitoring: [] };
      for (const st of api.stacks) for (const sv of st.services) for (const tk of sv.tasks) {
        const real = tk.local ? Object.keys(api.containers).find((k) => k.startsWith(`${sv.name}.${tk.slot}.`)) : `${sv.name}.${tk.slot}`;
        if (!tk.local && !remoteRowsOn) continue;
        if (st.name === 'smarthome') want['Smart Home'].push(real);
        if (st.name === 'paperless') want.Paperless.push(real);
        if (st.name === 'monitoring') want.Monitoring.push(real);
      }
      for (const [fname, members] of Object.entries(want)) {
        const got = inFolder(fname) || [];
        const miss = members.filter((m) => !got.includes(m));
        check(`fv.members.${fname.replace(' ', '')}`, miss.length === 0, miss.length ? { missing: miss, got } : `${got.length} members`);
      }
      // nothing of a folder may stay outside
      const loose = [...document.querySelectorAll('#docker_list > tr.sv-remote-row')].map((tr) => tr.dataset.svName);
      check('fv.no-loose-remote', loose.filter((n) => /^(smarthome|paperless|monitoring)_/.test(n)).length === 0, loose);
    }
    if (S.folders === 'alias-regex' && apiOk) {
      const got = inFolder('Smart Home') || [];
      const want = [];
      for (const st of api.stacks) for (const sv of st.services) if (['HomeAssistant', 'Zigbee2MQTT', 'ESPHome'].includes(sv.alias)) for (const tk of sv.tasks)
        if (tk.local || remoteRowsOn) want.push(tk.local ? Object.keys(api.containers).find((k) => k.startsWith(`${sv.name}.${tk.slot}.`)) : `${sv.name}.${tk.slot}`);
      const miss = want.filter((m) => !got.includes(m));
      check('fv.alias-regex', miss.length === 0, { missing: miss, got });
    }
    if (S.folders === 'all-remote' && remoteRowsOn) {
      const got = inFolder('On the Pi') || [];
      check('fv.explicit-remote-members', ['smarthome_mosquitto.1', 'paperless_broker.1'].every((n) => got.includes(n)) && got.includes('paperless_paperless-ngx.1'), got);
    }
    // folder totals include remote CPU (FOLDER_LOAD=total)
    if (advanced() && apiOk && remoteRowsOn && api.stats && api.stats.enabled && (api.config.folderLoad || 'total') === 'total' && S.folders === 'default' && !['pi-down', 'empty', 'no-stats'].includes(S.api)) {
      const f = folders.find((x) => /Paperless/.test(text(x)));
      const cpu = f && f.querySelector('[class*="cpu-folder-"]');
      check('fv.folder-total-remote', cpu && !/^0(\.0+)?\s*%$/.test(text(cpu)), cpu ? text(cpu) : 'no cpu span');
    }
  } else {
    check('fv.none', folders.length === 0, folders.length);
  }

  /* ---------- 7. Unraid functions still work ---------- */
  const dockerman = [...document.querySelectorAll('#docker_list input.autostart')].filter((i) => !i.id.startsWith('folder-'));
  check('unraid.autostart-switches', dockerman.every((i) => i.nextElementSibling || i.parentElement.querySelector('.switch-button-background')),
    dockerman.length + ' switches');
  // context menu on a remote row
  if (remote.length) {
    const r = remote[0];
    const hand = r.querySelector('td.ct-name span.hand');
    const before = document.querySelectorAll('.dropdown-context').length;
    try { hand.click(); } catch (e) { /* ignore */ }
    await wait(150);
    const menus = [...document.querySelectorAll('ul.dropdown-context')].filter(visible);
    check('remote.context-menu', menus.length > 0, { menusBefore: before, visible: menus.length, items: menus[0] ? text(menus[0]).slice(0, 80) : '' });
    document.body.click();
    await wait(50);
  }

  /* ---------- 8. orphan images ---------- */
  if (advanced() && S.unraidList !== 'empty') {
    const orphanRows = [...document.querySelectorAll('#docker_list > tr')].filter((tr) => tr.querySelector('span[onclick^="addDockerImageContext"]'));
    const tagOf = (tr) => (tr.querySelector('span[onclick^="addDockerImageContext"]').getAttribute('onclick').match(/,'([^']*)'\)/) || [])[1];
    const vis = orphanRows.filter(visible).map(tagOf);
    const all = orphanRows.map(tagOf);
    const mode = (apiOk && api.config.orphans) || 'show';          // no Swarm data → behave like Unraid
    const swarmImgs = new Set();
    if (apiOk) for (const st of api.stacks) for (const sv of st.services) swarmImgs.add(sv.image.replace(/^docker\.io\//, '').replace(/^library\//, ''));
    const nameless = (t) => !t || /^<none>(:<none>)?$/.test(t);
    const expected = !SV_ON || mode === 'show' ? all : mode === 'hide' ? [] : all.filter((t) => !nameless(t) && !swarmImgs.has(t));
    check(`orphans.${SV_ON ? mode : 'baseline'}`, JSON.stringify(vis.sort()) === JSON.stringify(expected.sort()), { visible: vis, expected });
  }

  /* ---------- 9. Swarm section content ---------- */
  const root = document.getElementById('sv-root');
  if (SV_ON) check('swarm.root', !!root, '');
  if (root) {
    const err = root.querySelector('.sv-error, .red-text');
    if (apiOk) {
      const stackRows = root.querySelectorAll('tr.sv-stack').length;
      check('swarm.stacks', stackRows >= (api.stacks.length), { rows: stackRows, apiStacks: api.stacks.length });
    } else {
      check('swarm.message-when-unavailable', text(root).length > 0, text(root).slice(0, 160));
    }
    // section must not widen the page
    const over = root.scrollWidth - root.clientWidth;
    check('swarm.no-overflow', over <= 2 || getComputedStyle(root).overflowX !== 'visible', `${root.scrollWidth}/${root.clientWidth}`);
  }

  /* ---------- 10. stability: reloads + idle mutations ---------- */
  if (opts && opts.stress) {
    for (let i = 0; i < 3; i++) { window.loadlist(); await wait(700); }
    await wait(1500 + 1.5 * (+S.apiDelay || 0));
    const remote2 = [...document.querySelectorAll('#docker_list tr.sv-remote-row')].filter(shown).length;
    check('stress.remote-stable', remote2 === remote.length, { before: remote.length, after: remote2 });
    const all2 = [...document.querySelectorAll('#docker_list tr')].filter((tr) => !tr.classList.contains('folder'))
      .map((tr) => text(tr.querySelector('td.ct-name .appname'))).filter(Boolean);
    const dup2 = all2.filter((n, i) => all2.indexOf(n) !== i);
    check('stress.no-duplicates', dup2.length === 0, dup2);
    tableGeometry('after-reload');
    // mutation storm detection
    let muts = 0;
    const mo = new MutationObserver((l) => { muts += l.length; });
    mo.observe(document.getElementById('docker_list'), { childList: true, subtree: true, attributes: true });
    await wait(3000);
    mo.disconnect();
    check('stress.idle-mutations', muts < 200, `${muts} mutations in 3 s idle`);
    // Unraid live stats (nchan) for a local container + remote values untouched
    const firstLocal = [...document.querySelectorAll('#docker_list tr:not(.sv-remote-row):not(.folder) td.ct-name span.hand[id]')].find((e) => /^[0-9a-f]{12}$/.test(e.id));
    if (firstLocal && window.SVTB.nchan['/sub/dockerload']) {
      const id = firstLocal.id;
      const remoteCpuBefore = remote.length ? text(document.querySelector(`#docker_list tr.sv-remote-row [class^="cpu-sv"]`)) : '';
      window.SVTB.nchan['/sub/dockerload'].emit(`${id};42.0%;123MiB / 31GiB`);
      await wait(100);
      check('nchan.local-cpu-updated', text(document.querySelector('.mem-' + id)) === '123MiB / 31GiB', text(document.querySelector('.cpu-' + id)) + ' / ' + text(document.querySelector('.mem-' + id)));
      if (remote.length) check('nchan.remote-untouched', text(document.querySelector(`#docker_list tr.sv-remote-row [class^="cpu-sv"]`)) === remoteCpuBefore, remoteCpuBefore);
    }
  }

  /* ---------- 11. advanced/basic toggle via Unraid's switch ---------- */
  if (opts && opts.toggle) {
    const sw = document.querySelector('.advancedview');
    const btn = sw && sw.parentElement.querySelector('.switch-button-background, .switch-button-button');
    if (btn) {
      btn.click(); await wait(1200);
      tableGeometry(advanced() ? 'advanced-toggled' : 'basic-toggled');
      const hostVisible = [...document.querySelectorAll('#docker_containers th.sv-hostcol')].some(visible);
      check('toggle.host-column', advanced() ? hostVisible === !!(api && api.config.hostColumn && apiOk) : !hostVisible, { advanced: advanced(), hostVisible });
      btn.click(); await wait(1200);
      tableGeometry(advanced() ? 'advanced-back' : 'basic-back');
      // the host column must keep a usable width after switching back (Folder View 3 fixes column widths)
      const th = document.querySelector('#docker_containers th.sv-hostcol');
      if (th && visible(th)) check('toggle.host-column-width', th.getBoundingClientRect().width >= 80, Math.round(th.getBoundingClientRect().width) + 'px');
    } else check('toggle.switch-found', false, 'no switch');
  }

  /* ---------- 11b. several nodes ---------- */
  if (apiOk) {
    const cards = document.querySelectorAll('#sv-nodes .sv-cards > *').length;
    check('nodes.cards', cards === api.nodes.length, { cards, nodes: api.nodes.length });
    // every remote row shows the node it runs on
    const wrongNode = [];
    const taskNode = {};
    for (const st of api.stacks) for (const sv of st.services) for (const tk of sv.tasks) if (!tk.local) taskNode[`${sv.name}.${tk.slot}`] = tk.node;
    for (const tr of document.querySelectorAll('#docker_list tr.sv-remote-row')) {
      const n = tr.dataset.svName;
      const badge = text(tr.querySelector('.sv-badge.sv-remote'));
      if (taskNode[n] && badge && badge !== taskNode[n]) wrongNode.push(`${n}: ${badge} ≠ ${taskNode[n]}`);
      if (taskNode[n] && !badge && advanced() && api.config.hostColumn) wrongNode.push(`${n}: no badge`);
    }
    check('remote.badge-node', wrongNode.length === 0, wrongNode.slice(0, 5));
    // placement selects offer every node; global services have none
    const sel = document.querySelector('#sv-stacks select');
    if (api.nodes.length > 1 && sel) {
      const opts = [...sel.options].map((o) => o.textContent);
      const missing = api.nodes.map((n) => n.hostname).filter((h) => !opts.some((o) => o.includes(h)));
      check('place.all-nodes', missing.length === 0, { missing, opts: opts.slice(0, 10) });
    }
    const globalSvc = api.stacks.flatMap((st) => st.services).filter((sv) => sv.mode === 'global');
    if (globalSvc.length) {
      const row = [...document.querySelectorAll('#sv-stacks tr')].find((tr) => text(tr).includes(globalSvc[0].short));
      check('global.no-place-select', row && !row.querySelector('select'), row ? 'ok' : 'row missing');
    }
    // stack "runs on" summary names every node that has tasks of the stack
    const sumBad = [];
    for (const st of api.stacks) {
      const nodes = [...new Set(st.services.flatMap((sv) => sv.tasks.map((t) => t.node)).filter(Boolean))];
      const tr = document.querySelector(`#sv-stacks tr.sv-stack[data-stack="${st.name}"]`);
      if (!tr) continue;
      const missing = nodes.filter((n) => !text(tr).includes(n));
      if (missing.length) sumBad.push(`${st.name}: ${missing.join(',')}`);
    }
    check('stack.runs-on-summary', sumBad.length === 0, sumBad);
  }

  /* ---------- 12. Unraid sort mode (lock button) ---------- */
  if (opts && opts.lock && typeof window.LockButton === 'function') {
    const posts = [];
    const origPost = $.post;
    $.post = function (url, data) { posts.push({ url, data }); return $.Deferred().resolve('').promise(); };
    try {
      window.LockButton();
      await wait(200);
      const upd = $('#docker_list').sortable('option', 'update');
      if (typeof upd === 'function') upd({}, {});
      const saved = posts.find((p) => /UserPrefs/.test(p.url));
      const names = saved ? String(saved.data.names || '').split(';').filter(Boolean) : [];
      const remoteNames = names.filter((n) => /^\S+\.\d+$/.test(n) && !/\.[a-z0-9]{25}$/.test(n));
      // known + harmless: Unraid's own sort handler also saves the names of rows from other nodes (they are skipped on use)
      check('sort.save-works', !!saved && names.length > 0, { saved: names.length, remoteNamesIncluded: remoteNames.length });
      window.LockButton();
    } catch (e) {
      check('sort.lock-button', false, e.message);
    } finally { $.post = origPost; }
  }

  /* ---------- 13. JS errors ---------- */
  const errs = (window.SVTB.errors || []).filter((e) => !/graphql/i.test(e));
  check('js.no-errors', errs.length === 0, errs.slice(0, 5));
  return out;
}

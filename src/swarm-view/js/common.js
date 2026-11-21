/* Swarm View – shared part: state, helpers, translation, node selection, loading
 * Load order: common.js → stacks.js → containers.js (the last one calls SwarmView.start())
 */
(function ($) {
  'use strict';

  const SV = window.SwarmView = window.SwarmView || {};
  const API = '/plugins/swarm-view/include/api.php';
  const REFRESH_MS = 15000;
  const OPEN_KEY = 'swarmview_open';
  SV.data = null;        // last response of api.php
  SV.busy = false;       // is an action running?
  SV.listPending = 0;    // running loads of Unraid's container list
  const cfg = () => Object.assign({ remoteRows: 'always', hostColumn: true, refresh: 15 }, (SV.data && SV.data.config) || {});

  /* ---------- Translation ----------
   * Source language is English. The page injects window.SwarmViewLang (English → target language)
   * when Unraid (or the plugin setting) uses another language. Placeholders: {0}, {1}, … */
  const DICT = window.SwarmViewLang || {};
  const LANG = window.SwarmViewLangCode || 'en';
  function t(s, ...args) {
    let r = Object.prototype.hasOwnProperty.call(DICT, s) ? DICT[s] : s;
    args.forEach((v, i) => { r = r.split(`{${i}}`).join(v); });
    return r;
  }
  /** Singular/plural: tn(n, '{0} update', '{0} updates') */
  const tn = (n, one, many) => t(n === 1 ? one : many, n);

  /* ---------- Helpers ---------- */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const isAdvanced = () => {
    const $t = $('input.advancedview');
    if ($t.length) return $t.is(':checked');
    return /(?:^|;\s*)docker_listview_mode=advanced/.test(document.cookie);
  };
  const applyViewMode = () => $('body').toggleClass('sv-advanced', isAdvanced());

  /**
   * Expanded/collapsed per stack: { name: true|false }. Applies to both views.
   * Without a stored state: advanced view = expanded, basic view = collapsed.
   */
  const openState = () => {
    try {
      const v = JSON.parse(sessionStorage.getItem(OPEN_KEY) || '{}');
      if (Array.isArray(v)) return Object.fromEntries(v.map((n) => [n, true])); // old format
      return v && typeof v === 'object' ? v : {};
    } catch (e) { return {}; }
  };
  const isOpen = (name) => { const v = openState()[name]; return v === undefined ? isAdvanced() : !!v; };
  const setOpen = (name, open) => {
    const st = openState(); st[name] = !!open;
    try { sessionStorage.setItem(OPEN_KEY, JSON.stringify(st)); } catch (e) { /* ignore */ }
  };

  // Node badges: server icon = local, arrow = on another node; the text is only the node name
  const localBadge = (host, extra) =>
    `<span class="sv-badge sv-local" title="${esc(t('local (on this server)'))}"><i class="fa fa-server"></i>${esc(host)}${extra || ''}</span>`;
  const remoteBadge = (host, extra) =>
    `<span class="sv-badge sv-remote" title="${esc(t('running on {0}', host))}"><i class="fa fa-external-link"></i>${esc(host)}${extra || ''}</span>`;

  function placeBadge(t_) {
    const tip = esc([t_.current, t_.error].filter(Boolean).join(' – '));
    if (!t_.node) return `<span class="sv-badge sv-pending" title="${tip}"><i class="fa fa-hourglass-half"></i>${esc(t('not placed'))}</span>`;
    return `<span title="${tip}">${t_.local ? localBadge(t_.node) : remoteBadge(t_.node)}</span>`;
  }

  /** Groups tasks per node: "Tower ×2", "pi ×1" */
  function aggregateBadges(tasks) {
    const per = new Map();
    for (const tk of tasks) {
      const k = tk.node || '';
      const e = per.get(k) || { n: 0, local: tk.local };
      e.n++; per.set(k, e);
    }
    let out = '';
    for (const [node, e] of per) {
      const cnt = e.n > 1 ? ` ×${e.n}` : '';
      if (!node) out += `<span class="sv-badge sv-pending"><i class="fa fa-hourglass-half"></i>${esc(t('not placed'))}${cnt}</span>`;
      else if (e.local) out += localBadge(node, cnt);
      else out += remoteBadge(node, cnt);
    }
    return out || '<span class="sv-dim">–</span>';
  }

  function replicaSum(services) {
    let run = 0, want = 0;
    for (const sv of services) {
      const m = String(sv.replicas || '').match(/^(\d+)\/(\d+)/);
      if (m) { run += +m[1]; want += +m[2]; }
    }
    return { run, want };
  }
  // Status: all running = green · some = orange · none = red · 0 replicas on purpose = grey
  const replicaClass = (run, want) => (want === 0 ? 'sv-dim' : run === want ? 'sv-ok' : run === 0 ? 'sv-bad' : 'sv-warn');
  const statusIcon = (run, want) =>
    want === 0 ? `<i class="fa fa-square sv-dim" style="margin-left:5px" title="${esc(t('0 replicas requested'))}"></i>`
      : run === want ? '<i class="fa fa-play green-text" style="margin-left:5px"></i>'
        : run === 0 ? '<i class="fa fa-square red-text" style="margin-left:5px"></i>'
          : '<i class="fa fa-play orange-text" style="margin-left:5px"></i>';


  /* ---------- Node selection ---------- */
  const ARCH = { x86_64: 'amd64', amd64: 'amd64', aarch64: 'arm64', arm64: 'arm64', armv7l: 'arm', armhf: 'arm' };

  function findService(fullName) {
    if (!SV.data) return null;
    for (const st of SV.data.stacks) for (const sv of st.services) if (sv.name === fullName) return sv;
    return null;
  }

  /**
   * Can the service run on node n? → { ok, note }
   *  - architecture: platforms from the registry (empty = unknown = ok)
   *  - local image (no registry digest): only nodes it currently runs on – the image surely exists there
   *  - node ready and active (unless ignoreState)
   */
  function nodeCheck(sv, n, ignoreState = false) {
    const arch = ARCH[n.arch] || n.arch;
    if ((sv.platforms || []).length && !sv.platforms.includes(arch)) return { ok: false, note: t('no {0} image', arch) };
    if (sv.localImage) {
      const where = new Set((sv.tasks || []).filter((tk) => tk.node && /^running/i.test(tk.current)).map((tk) => tk.node));
      if (where.size && !where.has(n.hostname)) return { ok: false, note: t('local image') };
    }
    if (!ignoreState && (n.state !== 'ready' || n.availability !== 'active')) return { ok: false, note: n.state !== 'ready' ? t('unreachable') : t('maintenance') };
    return { ok: true, note: '' };
  }

  /** Current placement as select value: '' | 'fixed:N' | 'prefer:N' */
  const placeValue = (sv) => (sv.placeNode && !sv.rebalancing ? `fixed:${sv.placeNode}` : sv.prefer ? `prefer:${sv.prefer}` : '');

  /**
   * <select> "Runs on" – or '' when it makes no sense.
   *  Preferred: runs there while the node is available; otherwise elsewhere, the watchdog brings it back
   *  Fixed:     runs only there (if the node is down, the service does not start)
   */
  function placeSelect(sv) {
    if (!sv || !SV.data || SV.data.nodes.length < 2 || sv.mode === 'global') return '';
    const cur = placeValue(sv);
    const opt = (val, label, c) => `<option value="${esc(val)}"${cur === val ? ' selected' : ''}${c.ok || cur === val ? '' : ' disabled'}>${esc(label)}${c.note ? ` (${esc(c.note)})` : ''}</option>`;
    const pref = SV.data.nodes.map((n) => opt(`prefer:${n.hostname}`, `★ ${n.hostname}`, nodeCheck(sv, n, true))).join('');
    const fixed = SV.data.nodes.map((n) => opt(`fixed:${n.hostname}`, t('Only {0}', n.hostname), nodeCheck(sv, n))).join('');
    return `<select class="sv-place" data-service="${esc(sv.name)}" data-current="${esc(cur)}"${SV.busy ? ' disabled' : ''} title="${esc(t('Where should the service run? ★ = preferred (fails over when the node is down), Only = fixed. Applies until the next Stack Up.'))}">`
      + `<option value=""${cur === '' ? ' selected' : ''}>${esc(t('Automatic'))}</option>`
      + `<optgroup label="${esc(t('Preferred – fails over when down'))}">${pref}</optgroup>`
      + `<optgroup label="${esc(t('Fixed – only this node'))}">${fixed}</optgroup></select>`;
  }

  $(document).on('click', '.sv-place', (e) => e.stopPropagation());
  /** Change the placement of a service (value: '' | 'fixed:N' | 'prefer:N') – with confirmation */
  function placeService(svc, value) {
    const sv = findService(svc) || {};
    const short = sv.short || svc;
    const [mode, node] = value ? String(value).split(':') : ['', ''];
    const keep = t('Applies until the next Stack Up – then docker-stack.yml counts again.');
    const c = cfg();
    let title, text;
    if (mode === 'prefer') {
      title = t('Prefer "{0}" on {1}?', short, node);
      text = t('Runs on {0} while the node is available. If it goes down, Swarm starts the service on another node; once {0} has been stable for {1} minutes, it returns automatically.', node, c.rebalanceDelay || 5)
        + ` ${keep} ` + t('Permanently: in docker-stack.yml under deploy: labels: swarm-view.prefer: {0}', node)
        + (c.rebalance ? '' : `\n\n${t('The automatic return will be switched on in the settings for this.')}`);
    } else if (mode === 'fixed') {
      title = t('Pin "{0}" to {1}?', short, node);
      text = `${t('The service runs only on {0}. If the node is down, it does not start.', node)} ${keep}`;
    } else {
      title = t('Place "{0}" automatically?', short);
      text = `${t('Swarm decides again where the service runs (may restart).')} ${keep}`;
    }
    confirmBox(title, text, () => {
      SV.busy = true;
      $('.sv-place').prop('disabled', true);
      post({ action: 'place', service: svc, mode, node: node || '' })
        .done((r) => showResult(t('Placement: {0}', short), r))
        .fail((x) => showResult(t('Placement: {0}', short), failResult(x)))
        .always(() => { SV.busy = false; SV.load(); setTimeout(SV.load, 3000); setTimeout(SV.load, 8000); });
    });
  }

  $(document).on('change', '.sv-place', function () {
    const $sel = $(this);
    const cur = String($sel.data('current') || '');
    placeService(String($sel.data('service')), $sel.val());
    // restore the old value if cancelled (the next render sets it anyway)
    setTimeout(() => { if (!SV.busy) $sel.val(cur); }, 0);
  });


  /* ---------- Actions ---------- */
  const ACTIONS = '/plugins/swarm-view/include/actions.php';

  // Unraid's $.ajaxPrefilter adds the CSRF token to POSTs automatically
  const post = (d) => $.ajax({ url: ACTIONS, type: 'POST', data: d, dataType: 'json', timeout: 310000 });
  const failResult = (x) => ({ ok: false, error: t('Request failed ({0})', x.status || t('timeout')), log: x.responseText || '' });

  function showResult(title, r) {
    const text = [r.error, r.log].filter(Boolean).join('\n\n') || (r.ok ? 'OK' : t('Error'));
    if (typeof swal === 'function') {
      setTimeout(() => swal({ title, text: `<pre class="sv-log">${esc(text)}</pre>`, html: true, type: r.ok ? 'success' : 'error' }), 150);
    } else {
      alert(`${title}\n\n${text}`);
    }
  }

  function confirmBox(title, text, cb) {
    if (typeof swal === 'function') {
      swal({ title, text, type: 'warning', showCancelButton: true, confirmButtonText: t('Yes'), cancelButtonText: t('Cancel') },
        (ok) => { if (ok) cb(); });
    } else if (confirm(`${title}\n\n${text}`)) cb();
  }



  /* ---------- Loading ---------- */
  // Unraid's loadlist() starts its spinner with a timer (timers.docker). If two loadlist()
  // calls overlap, one timer is lost and the spinner stays forever.
  // So: only trigger a reload when no container list is currently loading.
  const LIST_URL = /DockerContainers\.php/;
  let reloadWanted = false;
  let keyAtLastListLoad = null;   // which remote containers were in the DOM at the last list load
  const currentRemoteKey = () => (cfg().remoteRows === 'never' ? '' : SV.remoteTasks().map((r) => r.name).sort().join('|'));

  $(document).ajaxSend((e, x, o) => { if (LIST_URL.test(o.url || '')) SV.listPending++; });
  // same task as Unraid's callback → the rows about to be inserted come from exactly this data
  $(document).ajaxSuccess((e, x, o) => {
    if (LIST_URL.test(o.url || '')) keyAtLastListLoad = SV.data ? currentRemoteKey() : '';
  });
  $(document).ajaxComplete((e, x, o) => {
    if (!LIST_URL.test(o.url || '')) return;
    SV.listPending = Math.max(0, SV.listPending - 1);
    // evaluate after Unraid's callback + Folder View (microtasks)
    setTimeout(() => {
      if (SV.listPending) return;
      // safety net: hide an orphaned spinner
      setTimeout(() => { if (!SV.listPending) $('div.spinner.fixed').hide(); }, 700);
      if (reloadWanted) { reloadWanted = false; maybeReloadList(); }
    }, 50);
  });

  function maybeReloadList() {
    if (!folderViewActive() || typeof window.loadlist !== 'function' || !SV.data) return;
    if (keyAtLastListLoad === null || keyAtLastListLoad === currentRemoteKey()) return;
    if (SV.listPending) { reloadWanted = true; return; }
    window.loadlist();
  }

  function render() {
    if (!SV.data) return;
    SV.renderMsg();
    SV.renderNodes();
    SV.renderStacks();
    SV.decorateDockerTable();
    // new/vanished remote containers → Folder View has to sort again
    maybeReloadList();
  }

  function folderViewActive() {
    try { return typeof folderEvents !== 'undefined'; } catch (e) { return false; }
  }

  let refreshTimer = null;
  let refreshEvery = 0;
  function scheduleRefresh() {
    const sec = Math.max(5, +cfg().refresh || REFRESH_MS / 1000);
    if (sec === refreshEvery && refreshTimer) return;
    refreshEvery = sec;
    clearInterval(refreshTimer);
    refreshTimer = setInterval(() => { if (!document.hidden) SV.load(); }, sec * 1000);
  }

  function load() {
    $.ajax({ url: API, dataType: 'json', cache: false, timeout: 20000 })
      .done((d) => { SV.data = d; render(); if (refreshTimer) scheduleRefresh(); })
      .fail((x, status) => {
        $('#sv-msg').attr('class', 'sv-msg error')
          .text(t('Swarm API not reachable ({0}).', x.status || status)).show();
      });
  }

  /** Called at the end of containers.js once all parts are loaded */
  function start() {
    // fetch data as early as possible so remote containers are there on the first list load
    SV.load();

    $(function () {
      applyViewMode();
      // follow Unraid's "Basic/Advanced View" switch
      $(document).on('change', 'input.advancedview', applyViewMode);
      SV.decorateDockerTable();
      scheduleRefresh();
    });
  }

  Object.assign(SV, {
    t, tn, LANG, esc, cfg, isAdvanced, applyViewMode, isOpen, setOpen, localBadge, remoteBadge, placeBadge, aggregateBadges,
    replicaSum, replicaClass, statusIcon, ARCH, findService, nodeCheck, placeSelect, placeService, ACTIONS, post, failResult,
    showResult, confirmBox, load, start,
  });
})(jQuery);

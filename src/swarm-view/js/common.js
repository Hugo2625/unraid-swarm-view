/* Swarm View – gemeinsamer Teil: Zustand, Hilfsfunktionen, Node-Auswahl, Laden
 * Reihenfolge: common.js → stacks.js → containers.js (startet am Ende SwarmView.start())
 */
(function ($) {
  'use strict';

  const SV = window.SwarmView = window.SwarmView || {};
  const API = '/plugins/swarm-view/include/api.php';
  const REFRESH_MS = 15000;
  const OPEN_KEY = 'swarmview_open';
  SV.data = null;        // letzte Antwort von api.php
  SV.busy = false;       // läuft gerade eine Aktion?
  SV.listPending = 0;    // laufende Ladevorgänge von Unraids Container-Liste
  const cfg = () => Object.assign({ remoteRows: 'always', hostColumn: true, refresh: 15 }, (SV.data && SV.data.config) || {});

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
   * Auf-/Zugeklappt pro Stack: { name: true|false }. Gilt in beiden Ansichten.
   * Ohne gespeicherten Zustand: erweiterte Ansicht = aufgeklappt, Basisansicht = zugeklappt.
   */
  const openState = () => {
    try {
      const v = JSON.parse(sessionStorage.getItem(OPEN_KEY) || '{}');
      if (Array.isArray(v)) return Object.fromEntries(v.map((n) => [n, true])); // altes Format
      return v && typeof v === 'object' ? v : {};
    } catch (e) { return {}; }
  };
  const isOpen = (name) => { const v = openState()[name]; return v === undefined ? isAdvanced() : !!v; };
  const setOpen = (name, open) => {
    const st = openState(); st[name] = !!open;
    try { sessionStorage.setItem(OPEN_KEY, JSON.stringify(st)); } catch (e) { /* egal */ }
  };

  // Einheitliche Node-Abzeichen: Server-Symbol = lokal, Pfeil = ausgelagert; Text nur der Node-Name
  const localBadge = (host, extra) =>
    `<span class="sv-badge sv-local" title="lokal (auf diesem Server)"><i class="fa fa-server"></i>${esc(host)}${extra || ''}</span>`;
  const remoteBadge = (host, extra) =>
    `<span class="sv-badge sv-remote" title="ausgelagert auf ${esc(host)}"><i class="fa fa-external-link"></i>${esc(host)}${extra || ''}</span>`;

  function placeBadge(t) {
    const tip = esc([t.current, t.error].filter(Boolean).join(' – '));
    if (!t.node) return `<span class="sv-badge sv-pending" title="${tip}"><i class="fa fa-hourglass-half"></i>nicht platziert</span>`;
    return `<span title="${tip}">${t.local ? localBadge(t.node) : remoteBadge(t.node)}</span>`;
  }

  /** Fasst Tasks pro Node zusammen: "Tower ×2", "ausgelagert: pi ×1" */
  function aggregateBadges(tasks) {
    const per = new Map();
    for (const t of tasks) {
      const k = t.node || '';
      const e = per.get(k) || { n: 0, local: t.local };
      e.n++; per.set(k, e);
    }
    let out = '';
    for (const [node, e] of per) {
      const cnt = e.n > 1 ? ` ×${e.n}` : '';
      if (!node) out += `<span class="sv-badge sv-pending"><i class="fa fa-hourglass-half"></i>nicht platziert${cnt}</span>`;
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
  // Status: alle laufen = grün · teilweise = orange · keiner = rot · bewusst 0 Replicas = grau
  const replicaClass = (run, want) => (want === 0 ? 'sv-dim' : run === want ? 'sv-ok' : run === 0 ? 'sv-bad' : 'sv-warn');
  const statusIcon = (run, want) =>
    want === 0 ? '<i class="fa fa-square sv-dim" style="margin-left:5px" title="0 Replicas gewünscht"></i>'
      : run === want ? '<i class="fa fa-play green-text" style="margin-left:5px"></i>'
        : run === 0 ? '<i class="fa fa-square red-text" style="margin-left:5px"></i>'
          : '<i class="fa fa-play orange-text" style="margin-left:5px"></i>';


  /* ---------- Node-Auswahl ---------- */
  const ARCH = { x86_64: 'amd64', amd64: 'amd64', aarch64: 'arm64', arm64: 'arm64', armv7l: 'arm', armhf: 'arm' };

  function findService(fullName) {
    if (!SV.data) return null;
    for (const st of SV.data.stacks) for (const sv of st.services) if (sv.name === fullName) return sv;
    return null;
  }

  /**
   * Kann der Service auf Node n laufen? → { ok, note }
   *  - Architektur: Plattformen aus der Registry (leer = unbekannt = ok)
   *  - Lokales Image (ohne Registry-Digest): nur Nodes, auf denen er gerade läuft – dort liegt das Image sicher
   *  - Node bereit und aktiv
   */
  function nodeCheck(sv, n, ignoreState = false) {
    const arch = ARCH[n.arch] || n.arch;
    if ((sv.platforms || []).length && !sv.platforms.includes(arch)) return { ok: false, note: `kein ${arch}-Image` };
    if (sv.localImage) {
      const where = new Set((sv.tasks || []).filter((t) => t.node && /^running/i.test(t.current)).map((t) => t.node));
      if (where.size && !where.has(n.hostname)) return { ok: false, note: 'lokales Image' };
    }
    if (!ignoreState && (n.state !== 'ready' || n.availability !== 'active')) return { ok: false, note: n.state !== 'ready' ? 'nicht erreichbar' : 'Wartung' };
    return { ok: true, note: '' };
  }

  /** Aktuelle Platzierung als Wert der Auswahl: '' | 'fixed:N' | 'prefer:N' */
  const placeValue = (sv) => (sv.placeNode && !sv.rebalancing ? `fixed:${sv.placeNode}` : sv.prefer ? `prefer:${sv.prefer}` : '');

  /**
   * <select> „Läuft auf“ – oder '' wenn nicht sinnvoll.
   *  Bevorzugt: läuft dort, wenn der Node verfügbar ist; sonst woanders, der Wächter holt ihn zurück
   *  Fest:      läuft nur dort (fällt der Node aus, startet der Service nicht)
   */
  function placeSelect(sv) {
    if (!sv || !SV.data || SV.data.nodes.length < 2 || sv.mode === 'global') return '';
    const cur = placeValue(sv);
    const opt = (val, label, c) => `<option value="${esc(val)}"${cur === val ? ' selected' : ''}${c.ok || cur === val ? '' : ' disabled'}>${esc(label)}${c.note ? ` (${esc(c.note)})` : ''}</option>`;
    const pref = SV.data.nodes.map((n) => opt(`prefer:${n.hostname}`, `★ ${n.hostname}`, nodeCheck(sv, n, true))).join('');
    const fixed = SV.data.nodes.map((n) => opt(`fixed:${n.hostname}`, `Nur ${n.hostname}`, nodeCheck(sv, n))).join('');
    return `<select class="sv-place" data-service="${esc(sv.name)}" data-current="${esc(cur)}"${SV.busy ? ' disabled' : ''} title="Wo soll der Service laufen? ★ = bevorzugt (weicht bei Ausfall aus), Nur = fest. Gilt bis zum nächsten Stack Up.">`
      + `<option value=""${cur === '' ? ' selected' : ''}>Automatisch</option>`
      + `<optgroup label="Bevorzugt – weicht bei Ausfall aus">${pref}</optgroup>`
      + `<optgroup label="Fest – nur dieser Node">${fixed}</optgroup></select>`;
  }

  $(document).on('click', '.sv-place', (e) => e.stopPropagation());
  /** Service-Platzierung ändern (value: '' | 'fixed:N' | 'prefer:N') – mit Rückfrage */
  function placeService(svc, value) {
    const sv = findService(svc) || {};
    const short = sv.short || svc;
    const [mode, node] = value ? String(value).split(':') : ['', ''];
    const keep = 'Gilt bis zum nächsten Stack Up – dann zählt wieder die docker-stack.yml.';
    const c = cfg();
    let title, text;
    if (mode === 'prefer') {
      title = `„${short}“ bevorzugt auf ${node}?`;
      text = `Läuft auf ${node}, solange der Node verfügbar ist. Fällt er aus, startet Swarm den Service auf einem anderen Node; `
        + `ist ${node} danach ${c.rebalanceDelay || 5} Minuten stabil, kommt er automatisch zurück. ${keep} `
        + `Dauerhaft: in der docker-stack.yml unter deploy: labels: swarm-view.prefer: ${node}`
        + (c.rebalance ? '' : '\n\nDie automatische Rückkehr wird dafür in den Einstellungen eingeschaltet.');
    } else if (mode === 'fixed') {
      title = `„${short}“ fest auf ${node}?`;
      text = `Der Service läuft nur noch auf ${node}. Ist der Node aus, startet er nicht. ${keep}`;
    } else {
      title = `„${short}“ automatisch platzieren?`;
      text = `Swarm entscheidet wieder selbst, wo der Service läuft (Neustart möglich). ${keep}`;
    }
    confirmBox(title, text, () => {
      SV.busy = true;
      $('.sv-place').prop('disabled', true);
      post({ action: 'place', service: svc, mode, node: node || '' })
        .done((r) => showResult(`Platzierung: ${short}`, r))
        .fail((x) => showResult(`Platzierung: ${short}`, failResult(x)))
        .always(() => { SV.busy = false; SV.load(); setTimeout(SV.load, 3000); setTimeout(SV.load, 8000); });
    });
  }

  $(document).on('change', '.sv-place', function () {
    const $sel = $(this);
    const cur = String($sel.data('current') || '');
    placeService(String($sel.data('service')), $sel.val());
    // Bei Abbruch alte Auswahl wiederherstellen (wird beim nächsten Render ohnehin neu gesetzt)
    setTimeout(() => { if (!SV.busy) $sel.val(cur); }, 0);
  });


  /* ---------- Stack-Aktionen ---------- */
  const ACTIONS = '/plugins/swarm-view/include/actions.php';

  // CSRF-Token hängt Unraids $.ajaxPrefilter automatisch an POSTs an
  const post = (d) => $.ajax({ url: ACTIONS, type: 'POST', data: d, dataType: 'json', timeout: 310000 });
  const failResult = (x) => ({ ok: false, error: `Anfrage fehlgeschlagen (${x.status || 'Timeout'})`, log: x.responseText || '' });

  function showResult(title, r) {
    const text = [r.error, r.log].filter(Boolean).join('\n\n') || (r.ok ? 'OK' : 'Fehler');
    if (typeof swal === 'function') {
      setTimeout(() => swal({ title, text: `<pre class="sv-log">${esc(text)}</pre>`, html: true, type: r.ok ? 'success' : 'error' }), 150);
    } else {
      alert(`${title}\n\n${text}`);
    }
  }

  function confirmBox(title, text, cb) {
    if (typeof swal === 'function') {
      swal({ title, text, type: 'warning', showCancelButton: true, confirmButtonText: 'Ja', cancelButtonText: 'Abbrechen' },
        (ok) => { if (ok) cb(); });
    } else if (confirm(`${title}\n\n${text}`)) cb();
  }



  /* ---------- Laden ---------- */
  // Unraids loadlist() startet den Ladebalken per Timer (timers.docker). Laufen zwei
  // loadlist() gleichzeitig, geht ein Timer verloren und der Balken bleibt für immer.
  // Darum: Neuladen nur anstoßen, wenn gerade keine Container-Liste geladen wird.
  const LIST_URL = /DockerContainers\.php/;
  let reloadWanted = false;
  let keyAtLastListLoad = null;   // welche Pi-Container beim letzten Laden der Liste im DOM waren
  const currentRemoteKey = () => (cfg().remoteRows === 'never' ? '' : SV.remoteTasks().map((r) => r.name).sort().join('|'));

  $(document).ajaxSend((e, x, o) => { if (LIST_URL.test(o.url || '')) SV.listPending++; });
  // Gleicher Task wie Unraids Callback → die Zeilen, die gleich eingefügt werden, stammen aus genau diesen Daten
  $(document).ajaxSuccess((e, x, o) => {
    if (LIST_URL.test(o.url || '')) keyAtLastListLoad = SV.data ? currentRemoteKey() : '';
  });
  $(document).ajaxComplete((e, x, o) => {
    if (!LIST_URL.test(o.url || '')) return;
    SV.listPending = Math.max(0, SV.listPending - 1);
    // nach Unraids Callback + Folder View (Microtasks) auswerten
    setTimeout(() => {
      if (SV.listPending) return;
      // Sicherheitsnetz: verwaisten Ladebalken ausblenden
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
    // Neue/verschwundene Pi-Container → Folder View muss neu sortieren
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
          .text(`Swarm-API nicht erreichbar (${x.status || status}).`).show();
      });
  }

  /** Wird am Ende von containers.js aufgerufen, wenn alle Teile geladen sind */
  function start() {
    // Daten so früh wie möglich holen, damit die Pi-Container schon beim ersten Laden der Liste da sind
    SV.load();

    $(function () {
      applyViewMode();
      // Unraids "Basic/Advanced View"-Schalter mitbenutzen
      $(document).on('change', 'input.advancedview', applyViewMode);
      SV.decorateDockerTable();
      scheduleRefresh();
    });
  }

  Object.assign(SV, {
    esc, cfg, isAdvanced, applyViewMode, isOpen, setOpen, localBadge, remoteBadge, placeBadge, aggregateBadges,
    replicaSum, replicaClass, statusIcon, ARCH, findService, nodeCheck, placeSelect, placeService, ACTIONS, post, failResult,
    showResult, confirmBox, load, start,
  });
})(jQuery);

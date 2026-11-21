/* Defines window.svtbSettle(quietMs, maxMs): resolves once the Docker page has settled instead of sleeping a fixed time.
 * Settled = no jQuery request pending, Unraid's list rendered, Swarm View data (or its error) there, Folder View
 * folders built – and no DOM change in the container list / Swarm section for quietMs. Returns the time it took. */
() => {
  window.svtbSettle = (quiet = 400, max = 10000) => new Promise((resolve) => {
    const S = (window.SVTB && window.SVTB.scenario) || {};
    const $ = window.jQuery;
    const t0 = performance.now();
    let last = t0;
    const mo = new MutationObserver(() => { last = performance.now(); });
    for (const id of ['docker_list', 'sv-root', 'docker_containers']) {
      const el = document.getElementById(id);
      if (el) mo.observe(el, { childList: true, subtree: true, attributes: true, characterData: true });
    }
    const ready = () => {
      if (!$ || $.active > 0) return false;
      const list = document.getElementById('docker_list');
      if (!list || !list.children.length) return false;
      if (S.swarmview !== false) {
        const SV = window.SwarmView;
        if (!SV || !(SV.data || $('#sv-msg.error').length)) return false;
        if (SV.listPending > 0) return false;
      }
      const wantFolders = S.folderview !== 'none' && S.folders !== 'none' && S.unraidList !== 'empty';
      if (wantFolders && !list.querySelector('tr.folder')) return false;
      return true;
    };
    const tick = () => {
      const now = performance.now();
      if ((ready() && now - last >= quiet) || now - t0 >= max) {
        mo.disconnect();
        resolve(Math.round(now - t0));
      } else setTimeout(tick, 50);
    };
    tick();
  });
  return true;
}

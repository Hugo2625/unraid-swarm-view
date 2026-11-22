# Changelog
### 2026.11.22
- "returning …" badge only while a return to the preferred node really runs: it no longer sticks to services that run stable, that have already arrived or that have no preferred node anymore
- Return to the preferred node: if the service does not start there, the return is cancelled after 5 minutes and Swarm runs it elsewhere again; the next attempt follows after 30 minutes, then 1 h, 2 h … (before: a new attempt – and a restart – every 10 minutes)
- Switching "automatic return" off ends pending returns at once and removes their temporary node binding; Stack Up forgets returns of that stack; returns of deleted services are forgotten
- New settings tab "Log": what Swarm View does in the background – watchdog decisions (failover, return, crash loops, nodes offline/online, notifications), your actions on the Docker page with their output, changed settings and errors. Filter by level, source and text, live view, details per entry, download, clear. Recording level: errors / warnings / info (default) / debug (every watchdog run and why a service does or does not return)
### 2026.11.21d
- Folder View: when the Swarm data arrived while Folder View was still building its folders, a folder could stay empty – Swarm View now waits until Folder View is done before it reloads the list
### 2026.11.21c
- Folder View 3: the host column no longer shrinks to a sliver after switching Basic → Advanced view
- Folder View 2: Swarm containers running on Unraid are now sorted into folders as well
- Orphan images: an image was hidden by mistake when the agent container was the last container in the list (and reappeared in the basic view); "<none>:<none>" counts as an image without name; without Swarm data nothing is hidden
- Swarm section scrolls horizontally on narrow screens instead of being cut off
- Host column keeps a minimum width
### 2026.11.21b
- Support link to the Unraid forum thread
- GitHub account renamed to Hugo2625: new plugin URL https://raw.githubusercontent.com/Hugo2625/unraid-swarm-view/main/swarm-view.plg
### 2026.11.21a
- English interface; German stays available. The language follows Unraid's display language or can be chosen in the settings (General → Display → Language)
- Notifications, messages and the settings page are translated as well
- Settings: popup after "Apply" only for noteworthy changes (agent started/stopped, watchdog switched on, swarm values changed)
### 2026.11.21
- First public release
- Swarm Stacks section on the Docker page: create, edit and deploy stacks (Stack Up / Update Stack / Stack Down), per-service actions (update, rollback, restart, logs, move)
- Host column and rows for containers running on other nodes, with CPU/RAM, network, uptime and context menu; Folder View support (folder totals across nodes, regex on display names)
- Node placement per service at runtime: automatic, preferred (with failover and automatic return) or fixed
- Notifications (node offline, crash loops, pending tasks, node changes, image updates), update hints with rollback, cleanup of stopped task containers and old images
- Settings with tabs: general, nodes (with warnings), watchdog, maintenance, status

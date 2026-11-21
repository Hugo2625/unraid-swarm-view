# Changelog
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

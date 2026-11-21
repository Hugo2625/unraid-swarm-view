# Swarm View – test bench

GitHub Actions runs this bench on every pull request (quick set) and every night (all scenarios with 1, 2 and 6 workers) – see `.github/workflows/ui-tests.yml`.

Runs the **original** Docker page of Unraid together with Folder View 3 / Folder View 2, Compose Manager and
Swarm View in headless Chrome – against mocked backends – and checks that everything still works together.
Each run also saves a few curated screenshots in `<out>/gallery` (Docker page, container list with open folders, Swarm section, node down, German, light theme, mobile).

## What is real, what is mocked

| Part | Source |
|---|---|
| Unraid page system (PageBuilder: order, titles), Docker page, `dynamix.js`, CSS, themes | real (`unraid/webgui`) |
| Folder View 3 / Folder View 2 pages and scripts | real (`chodeus/folder.view3`, `VladoPortos/folder.view2`) |
| Compose Manager section | real (`dcflachs/compose_plugin`), rendered with PHP |
| Swarm View page, JS, CSS | real – symlinked to your checkout (`../unraid-swarm-view`) |
| `DockerContainers.php` (container rows), FolderView `server/*.php`, Swarm View `api.php`/`actions.php` | mocked from `scenarios/world.json` |
| Nchan (live CPU) | stand-in: `SVTB.nchan['/sub/dockerload'].emit('id;42%;1MiB / 2GiB')` |

## Setup (once, as root, in a throwaway container/VM)

```bash
sudo tests/bench/setup.sh            # clones the vendor sources, builds /usr/local/emhttp + /boot/config
sudo tests/bench/setup.sh --update   # same + git pull of all vendor sources
pip install -r tests/bench/requirements.txt && playwright install chromium   # or an installed Chrome via --chrome
```
Swarm View is taken from this repository (`src/swarm-view`); `SV_REPO=/path/to/checkout` overrides it.
`setup.sh` writes to `/usr/local/emhttp` and `/boot/config` – do not run it on a real Unraid server.

## Run

```bash
cd tests/bench
sudo ./server.sh &                    # http://127.0.0.1:8090/Docker?s=<scenario>  (open it in a browser, too)
python3 make_fixtures.py              # only after changing the test data
python3 run_tests.py                  # all scenarios → out/report.md, out/results.json, out/<scenario>.png
python3 run_tests.py --quick          # the small set the pull-request check runs
python3 run_tests.py --jobs 8 --shots # more tabs in parallel, full-page screenshot of every scenario (default: failed ones only)
python3 run_tests.py default fv2      # single scenarios
python3 run_tests.py --workers 6      # same scenarios with 6 worker nodes → out-w6/ (also 2 or 3)
python3 backend/test_backend.py       # api.php / actions.php with a fake docker CLI in 13 cluster states (incl. 3 workers, worker down)
```

## Scenarios (`scenarios/<name>.json`, merged with the defaults in `server/scenario.php`)

| Key | Values |
|---|---|
| `folderview` | `fv3` · `fv2` · `none` |
| `compose` | `true` · `false` |
| `swarmview` | `true` · `false` (baseline: same page without Swarm View) |
| `position` | `top` · `middle` · `bottom` |
| `view` / `lang` / `theme` | `advanced`/`basic` · `en`/`de` · `black`/`white`/`azure`/`gray` |
| `api` | `normal` · `error500` · `invalid` · `php-warning` · `swarm-inactive` · `worker` · `pi-down` · `no-stats` · `empty` · `no-local` · `many` |
| `apiDelay` | ms – slow API (race with Folder View) |
| `cfg` | Swarm View settings, e.g. `{"HOST_COLUMN":"no","REMOTE_ROWS":"advanced","FOLDER_LOAD":"local","ORPHAN_IMAGES":"show"}` |
| `folders` | `default` · `alias-regex` · `all-remote` · `none` |
| `unraidList` | `ok` · `empty` |
| `_viewport` | `[width, height]`, e.g. `[390, 844]` |
| `workers` | `1`, `2`, `3` or `6` worker nodes (also `run_tests.py --workers N` or `/Docker?s=…&w=N`) – with 2+ workers the test data adds a global service (`monitoring_node-exporter`, one task per node) |

New scenario = new JSON file. New check = add it to `checks.js` (runs inside the page, gets the API answer and the scenario).

## What the checks cover (`checks.js`)

Section order per position · node cards, node badge of every remote row, placement select lists every node, global services without select, stack summary names every node · table geometry (every row has as many columns as the header, advanced and basic,
after toggling Unraid's view switch and after reloads) · remote rows complete, no duplicates, stable over reloads ·
agent/cleanup rows hidden · local Swarm rows: host badge, display names · Folder View: folders built, members incl.
remote rows, regex on display names, explicit remote members, folder CPU totals incl. remote nodes · Unraid autostart
switches, context menu on remote rows, sort mode saves · orphan images per setting · Swarm section content and
overflow on narrow screens · idle DOM mutations (no update loops) · Unraid live stats (nchan) · JS errors, console errors,
unexpected HTTP errors.

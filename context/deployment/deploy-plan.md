---
project: 10xdevs-home-library
planned_at: 2026-10-20
revised_at: 2026-10-20
platform: Fly.io
status: DEPLOYED to https://10xdevs-home-library.fly.dev on 2026-10-21 (Phases 2-5 done). Open: custom domain (6), CI (7), first user, backups (9)
inputs: context/foundation/infrastructure.md, context/foundation/tech-stack.md
app_name: 10xdevs-home-library
primary_region: fra   # decided 2026-10-31, see D6 (waw does not exist on Fly)
database: cyberfolks MySQL (external, remote access)
web_server: nginx + php-fpm (single container)
domain: custom domain accepted (name to be filled in D4)
---

# Deploy plan: My home library on Fly.io

Legend: `[ ]` todo, `[x]` done, `[~]` in progress, `[!]` blocked.
Gates: **HUMAN** = only the user can do it (account, card, secrets, DNS, destructive). **AGENT** = agent may run it unattended. **DECIDE** = needs a user decision.
Every phase has a **Verify** block and **Support steps**. Facts marked *(verified)* were read from vendor docs on 2026-10-20; *(unverified)* must be checked with `--help` or a test before relying on them.
Tip for running interactive commands (login, token creation) inside the agent session: type them with the `!` prefix, e.g. `! fly auth login`, so the output lands in the conversation and no secret is pasted by hand.

## Decisions taken (Revision 2)

| ID | Decision | Consequence |
|---|---|---|
| D1 | Database on **cyberfolks** (external MySQL, remote access) | Fly machines have changing outbound IPs, cyberfolks allows remote access only from whitelisted IPs *(verified)* -> need a **static egress IP** on Fly (about $3.60/month *(verified)*). TLS support unconfirmed (see risks). |
| D2 | **nginx + php-fpm** instead of FrankenPHP | Two processes in one container (supervisord), hand-written Dockerfile, nginx config, php-fpm pool config. |
| D3 | Machine `shared-cpu-1x` 512 MB, always on | about $3.69/month (NA price; confirm for your region) |
| D4 | **Custom domain accepted** | DNS + certificate steps added (Phase 1D, Phase 6). Domain name still needed from user. |
| D5 | No staging app for MVP | Revisit if migrations become risky. |
| D6 | Region: **`fra`** (decided 2026-10-31) | `waw` is not available on Fly. Measured from a `fra` machine: about 30 ms per query to the cyberfolks DB (Phase 1E). Changing region later means new egress IPs and a new whitelist. |

Estimated cost: machine about $3.69 + static egress IPv4 $3.60 = about **$7.3/month** + domain (already owned or bought) + cyberfolks plan you already pay for. No compute free tier; trial is only about 2 h or 7 days *(verified)*.

## Current repo state

- No `Dockerfile`, `.dockerignore`, `fly.toml`, nginx config or CI workflow yet; only `compose.yaml` (dev MySQL 8.0).
- `.env`: `APP_ENV=dev`, empty `APP_SECRET`, local MySQL 8.0 on port 3307, `MAILER_DSN=null://null`.
- `framework.yaml`: `session: true` without handler (files; lost on restart). No `trusted_proxies`.
- `monolog` prod logs JSON to `php://stderr` (fits `fly logs`).
- `doctrine.yaml` has no `server_version`; goes into `DATABASE_URL`.
- Stack hints: auth required, no payments/realtime/AI/jobs; CI = GitHub Actions auto-deploy on merge; deadline 2027-01-10.

## External integrations map

| # | Integration | Credential | Where the secret lives | Failure impact |
|---|---|---|---|---|
| 1 | Fly.io platform | `fly auth` login + scoped deploy token | Local CLI session; deploy token in GitHub Secrets | No deploys / app down |
| 2 | **cyberfolks MySQL** | DB user + password, host, port | `fly secrets` (`DATABASE_URL`) | Every DB page returns 500 |
| 3 | **Fly static egress IP** | n/a (resource) | Whitelisted in cyberfolks panel | If missing/changed: `Access denied`/timeouts |
| 4 | GitHub Actions | `FLY_API_TOKEN` | GitHub repo secret | Merge does not deploy (app stays up) |
| 5 | **DNS + TLS for custom domain** | registrar/DNS panel login | n/a | Custom hostname fails; `*.fly.dev` still works |
| 6 | Docker Hub base images (`php:8.4-fpm-alpine`, `composer:2`) | none | n/a | Build fails if a tag is removed |
| 7 | Mail provider (later) | `MAILER_DSN` | `fly secrets` | Reset mails not sent |

Rules: least-privilege tokens, secrets only in `fly secrets` / GitHub Secrets / password manager (never in `fly.toml`, `.env`, `.mcp.json` or chat), destructive actions by a human.

---

## Phase 0 - Prerequisites checklist (read first)

- [x] Windows 11 PowerShell available (this machine); Git installed (`git --version`).
- [x] Docker Desktop running (`docker version` shows client and server).
- [x] Repo is on branch `deploy/fly` (commit deploy files there).
- [x] Payment card ready for Fly (required). (implied: app created and deployed on 2026-10-21; Fly refuses app creation without a card. Not checked in the billing dashboard.)
- [x] cyberfolks panel login ready (DB `uwzkfxjtru_homelibrary`, dedicated user created and egress IPs whitelisted in the panel on 2026-10-21). Panel type (DirectAdmin vs WebAs) is not recorded; see 1D.
- [~] Domain name chosen and DNS panel access ready (see 1C/1D). Not needed now: Phase 6 skipped, app runs on `10xdevs-home-library.fly.dev`. Reopen only if a custom domain is revived.
- [x] Password manager ready for: Fly login, DB password, `APP_SECRET`, tokens. (confirmed by user 2026-10-31: Fly login, DB password and `APP_SECRET` stored; the deploy token does not exist yet, store it when created in Phase 3.)

---

## Phase 1 - Prerequisites: Fly.io account, CLI, DB, DNS (HUMAN)

### 1A. Install and configure the Fly CLI (`flyctl`)

- [x] Install in PowerShell: `pwsh -Command "iwr https://fly.io/install.ps1 -useb | iex"` (if `pwsh` is missing, use `powershell -Command "iwr https://fly.io/install.ps1 -useb | iex"`) *(verified)*.
- [x] Open a **new** terminal (PATH refresh) and check: `fly version` (the binary is available as both `fly` and `flyctl`). (fly.exe v0.4.120 already installed)
- [x] Update when needed: `fly version update` (already on latest flyctl v0.5.1, verified 2026-10-31).
- [x] Log in: `fly auth signup` (new account) or `fly auth login` (existing). A browser window opens; the session is stored in `%USERPROFILE%\.fly\config.yml`.
- [x] Check identity: `fly auth whoami` (logged in as m.petlak@protonmail.com).
- [~] Optional: tab completion `fly completion powershell` (command works, verified 2026-10-31; not yet loaded in `$PROFILE`, so completion is not active).
- [x] Health check of the local setup: `fly doctor` (all checks passed 2026-10-31 after `fly agent restart`; first run failed on the flyctl agent socket).

**Support steps:**
- `fly` not recognized: reopen the terminal; otherwise add `%USERPROFILE%\.fly\bin` to the user PATH and reopen.
- Execution policy blocks the installer: run PowerShell as normal user with `-ExecutionPolicy Bypass` for that single command, or download the script and read it first.
- Browser login fails behind VPN/proxy: disable VPN, retry; or `fly auth login --interactive` *(unverified)*.
- Antivirus quarantines `flyctl.exe`: restore and add an exclusion for `%USERPROFILE%\.fly`.

### 1B. Fly.io account configuration

- [x] **HUMAN** Add the payment card in the dashboard (Billing). Fly has no compute free tier *(verified)*. (confirmed by user 2026-10-31)
- [x] **HUMAN** Enable 2FA on the account (Account > Security). (confirmed by user 2026-10-31)
- [x] Note your **organization slug** (`fly orgs list`): app owner is `personal` (seen in `fly apps list`); use `--org <slug>` in `fly launch` if you have more than one (`--org` flag *(verified)*).
- [x] Check available regions: `fly platform regions` and see whether `waw` and `fra` are listed (see D6). (checked 2026-10-31: `fra` is listed, `waw` is not available on Fly, so D6 reduces to `fra` vs other EU regions.)
- [x] Decide token strategy (important for agent safety; decided 2026-10-31: keep the strategy below, token creation is still pending in Phase 3):
  - Your interactive `fly auth login` session has **full account access**. Use it yourself.
  - For CI and for the agent use a **deploy token scoped to one app**: `fly tokens create deploy -a 10xdevs-home-library -x 720h` *(verified: scope is one app; `-x` sets expiry; default would be 20 years, so always set a shorter one)*. Create it **after** the app exists (Phase 3).
  - Provide it to a shell as an environment variable (`$env:FLY_API_TOKEN = "..."`), never in a committed file.
- [x] (app `10xdevs-home-library` already created, confirmed by user 2026-10-31) Reserve the app name early: `fly apps create 10xdevs-home-library` happens in Phase 3; check beforehand that the name is free.

**Support steps:**
- No card = `fly launch` refuses to create the app. Add the card first.
- Name taken: use `10xdevs-home-library-<suffix>` and update all references (`fly.toml`, `DEFAULT_URI`, CI).
- Organisation confusion (personal vs team): `fly orgs list`, then pass `-o <org>` explicitly in every create command.

### 1C. Custom domain and DNS prerequisites (D4)

- [ ] **DECIDE** the hostname. Recommendation: a **subdomain** such as `biblioteka.<your-domain>` (a CNAME works on subdomains; an apex/root domain needs A/AAAA records instead).
- [ ] **HUMAN** Make sure you can edit DNS records for that domain (at cyberfolks DNS panel or your registrar).
- [ ] Lower the TTL of any existing record you will change to 300 s a day before cutover.
- [ ] Check for existing **CAA** records: if present, they must allow `letsencrypt.org` (Fly issues Let's Encrypt certificates *(unverified, confirm on docs.fly.io/docs/networking/custom-domain)*).
- [ ] (Done later, Phase 6) `fly certs add <hostname>`, then create the DNS record(s) that `fly certs show`/`fly certs add` print, wait for validation. First 10 single-hostname certificates are free *(verified)*.

**Support steps:**
- Certificate stays "awaiting configuration": record typo, wrong type (CNAME vs A), or DNS not propagated; check with `nslookup -type=CNAME <hostname>` and `fly certs check <hostname>`.
- Root-domain site is hosted elsewhere (e.g. cyberfolks website): keep root untouched and use a subdomain for the app.
- Cloudflare in front (orange cloud): set DNS-only (grey cloud) until the certificate is issued.

### 1D. cyberfolks database prerequisites (D1)

Facts from cyberfolks help *(verified 2026-10-20)*: remote access to MySQL is **blocked by default**; you must whitelist each client IP. In **DirectAdmin**: panel > domain > *Zarządzanie MySQL* > the database > *Dodaj host* next to the DB user > enter IP > *Zapisz*. In **WebAs** (VPS): email `webas@cyberfolks.pl` with the IPs. `%` allows everyone (avoid); `1.2.3.%` allows a range. Ports differ by MySQL version (**3307** older, **3308** newer; WebAs docs show 3306): read the exact host/port on the database details page. WebAs limits **5 simultaneous connections per DB user** for connections from outside cyberfolks.

- [ ] Identify your hosting type (DirectAdmin shared hosting vs WebAs) and plan.
- [x] DB details (no password here): host `s63.cyber-folks.pl` (185.208.164.63, IPv4 only in DNS), port `3308` (TCP reachable from this laptop 2026-10-21), db `uwzkfxjtru_homelibrary`, user `uwzkfxjtru_app` (dedicated app user, created 2026-10-21; password only in local `.env.local`, gitignored status to be confirmed).
- [x] Note the MySQL version shown in the panel and charset; put it into `DATABASE_URL` as `serverVersion=`. Result: server is MariaDB 10.11, `serverVersion=10.11.0-MariaDB`; local dev switched to `mariadb:10.11` (see execution log).
- [ ] **Check TLS:** ask cyberfolks support (or test with `mysql --ssl-mode=VERIFY_CA ...`) whether the MySQL server supports TLS and which CA certificate to use. **TLS is not mentioned in their help page.** Without TLS, DB credentials and household data cross the public internet in plaintext between Fly and cyberfolks. Decision gate **D7** below.
- [ ] Ask support (one email, answers go into this file): max connections/`max_user_connections` for this user, backup policy and restore procedure, whether remote connections count against any quota, MySQL port to use.
- [ ] Plan a **whitelist swap**: the Fly static egress IPv4 **and IPv6** (Phase 4) must both be added in the panel, since machines may connect over IPv6 when the destination supports it *(verified, Fly egress docs)*. cyberfolks help mentions no IPv6 access for some packages, so confirm with support whether an IPv6 whitelist entry is accepted; if not, force IPv4 (e.g. use the host's IPv4 address in `DATABASE_URL`).

**DECIDE D7 - TLS outcome:**
- [x] (a) TLS optional on cyberfolks (user, 2026-10-21): enabled in `doctrine.yaml` (`when@prod`), encrypted without cert verification until a CA is obtained. Still to confirm with the `mysql --ssl-mode=REQUIRED` test.
- [ ] (b) No TLS -> either move DB to a provider with TLS (e.g. Aiven Developer tier) or accept the risk knowingly with a strong unique password and IP whitelist only. Recommendation: do not launch with real family data over plaintext; revisit D1.

**Support steps:**
- `Access denied for user ... @ <ip>`: the source IP is not whitelisted for **that DB user**, or you whitelisted the wrong database; verify the egress IP the app really uses (Phase 5 support steps).
- Timeout: firewall/port wrong (3307 vs 3308 vs 3306). Test from your own whitelisted IP first with `mysql -h HOST -P PORT -u USER -p`.
- Too many connections (limit 5): keep PHP-FPM `pm.max_children` at or below 4 and avoid persistent connections.
- Panel differs from the description above: follow your panel's own help or ask support.

### 1E. Region and latency check (D6)

- [x] From your laptop or a temporary Fly machine measure RTT to the cyberfolks DB host (`ping`, or timing a `SELECT 1`). Measured 2026-10-31: laptop ping 23-29 ms, TCP 3308 27-41 ms; Fly machine in `fra`: TCP about 30 ms, PDO connect 89 ms, `SELECT 1` 29.5-29.7 ms. `waw` does not exist on Fly. *(Original assumption: a Polish host is closer to `waw`; DB server location still unconfirmed with support.)*
- [x] Pick the region; set `primary_region` accordingly. Decision: keep `fra` (about 30 ms per query, no better EU option, avoids re-allocating egress IPs). Every uncached page does several DB queries, so keep RTT under about 20-30 ms if possible.
- [ ] Remember: static egress IPs are **scoped per app and per region** *(verified)*; changing region later means allocating and whitelisting new IPs.

**Verify (Phase 1 overall):** `fly auth whoami` shows your email; `fly orgs list` shows the org; `fly platform regions` includes the chosen region; card added; cyberfolks DB + user exist and the connection details (including port and TLS answer) are in the password manager; domain/DNS access confirmed.

---

## Phase 2 - Production container with nginx + php-fpm (local only) (AGENT)

Goal: prove PHP 8.4 + Symfony 8.1 runs in prod mode behind nginx in one container.

- [x] `.dockerignore`: `.git`, `.idea`, `var/`, `vendor/`, `.env.local*`, `context/`, `tests/`, `.claude/`, `compose*.yaml`.
- [x] `Dockerfile` (multi-stage, hand-written; not the Laravel scanner trick):
  - Stage `vendor`: `composer:2`, `composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader`.
  - Stage `app`: `php:8.4-fpm-alpine` (confirm tag) + `nginx` + `supervisor` *(alternative: `serversideup/php:8.4-fpm-nginx`, a maintained image bundling both; tag unverified)*; extensions via `install-php-extensions`: `pdo_mysql intl opcache zip`.
  - Build time: `bin/console asset-map:compile --env=prod` and `bin/console cache:warmup --env=prod` with dummy build-args for `APP_SECRET`/`DATABASE_URL` (not left in final ENV).
  - `ENV APP_ENV=prod`; run as non-root where practical; `EXPOSE 8080`.
- [x] `docker/nginx/default.conf` (suggested path): `listen 8080;` `root /app/public;` front controller `try_files $uri /index.php$is_args$args;` `location ~ ^/index\.php(/|$) { fastcgi_pass 127.0.0.1:9000; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; fastcgi_param DOCUMENT_ROOT $realpath_root; internal; }` and `return 404` for other `.php`; hide dotfiles; `access_log /dev/stdout; error_log /dev/stderr;`; `client_max_body_size` small.
- [x] `docker/php/www.conf` (php-fpm pool): `listen = 127.0.0.1:9000`, `pm = static`/`dynamic` with `pm.max_children = 4` (matches cyberfolks 5-connection limit), `catch_workers_output = yes`, **`clear_env = no`** (otherwise Fly secrets/env vars never reach PHP, a classic nginx+fpm trap).
- [x] `docker/supervisord.conf`: run `php-fpm -F` and `nginx -g 'daemon off;'`, both logging to stdout/stderr, `exit` on child failure so Fly restarts the machine.
- [x] `docker/php/prod.ini`: `opcache.validate_timestamps=0`, `memory_limit=256M`, `expose_php=0`.
- [x] `GET /healthz` returning `200 ok` without DB/session access; make it public in `security.yaml`; add a test (TDD).
- [x] `framework.yaml` prod: `trusted_proxies: '%env(TRUSTED_PROXIES)%'` + `trusted_headers: ['x-forwarded-for','x-forwarded-host','x-forwarded-proto','x-forwarded-port']`; value on Fly `REMOTE_ADDR` (trust the immediate peer = Fly proxy; Fly peer behavior *(unverified)*, tested in Phase 5). nginx passes `X-Forwarded-*` request headers to PHP automatically as `HTTP_*` params, so no rewrite is needed; do **not** use `realip` modules unless required.
- [ ] Sessions in DB: `PdoSessionHandler` via the Doctrine connection + migration for the `sessions` table (keep inside the 5-connection budget: it reuses the same connection).
- [x] Doctrine: `serverVersion` in the URL (done, `10.11.0-MariaDB`); TLS enabled in `doctrine.yaml` `when@prod` without cert verification (D7 a; CA pinning still open). Original note: TLS options only if D7 (a): `driver_options` with `PDO::MYSQL_ATTR_SSL_CA` pointing at `config/certs/<cyberfolks-ca>.pem` (CA certificates are public; do not commit client keys).
- [x] Build/run locally: `docker build -t mhl:test .`, `docker run --rm -p 8080:8080 -e APP_ENV=prod -e APP_SECRET=<random> -e DATABASE_URL=<local-mysql-url> -e TRUSTED_PROXIES=REMOTE_ADDR mhl:test`.

**Verify:** `curl -i http://localhost:8080/healthz` = 200; login page renders with assets; `docker run --rm mhl:test php -v` = 8.4.x; `bin/console about --env=prod` = Symfony 8.1; env vars visible to PHP (`php -r 'echo getenv("APP_ENV");'` via a request, not CLI only); both processes alive (`docker top`); `nginx -t` passes.

**Support steps:**
- 502 Bad Gateway: php-fpm not listening on 127.0.0.1:9000; check supervisor logs.
- 404 on every route except `/`: missing `try_files` front-controller rule.
- Blank 500, no logs: php-fpm `catch_workers_output = yes`; Monolog goes to stderr.
- `APP_SECRET` empty in prod: `clear_env = no` missing.
- Static assets 404: `asset-map:compile` not run at build; compiled files must be in `public/assets`.
- Windows CRLF in shell/config files copied into the image: add `.gitattributes` with `text eol=lf` for `docker/**`.

---

## Phase 3 - Fly app creation and `fly.toml` (AGENT writes, HUMAN creates)

- [x] **HUMAN/AGENT** (app `10xdevs-home-library` exists, owner personal, region fra; `fly.toml` in repo) `fly launch --no-deploy --name 10xdevs-home-library --region fra --no-db --dockerfile Dockerfile` (flags *(verified)*); add `--copy-config` if a `fly.toml` already exists; answer **No** to any offer to create a database, Redis, or overwrite the Dockerfile; use `--no-github-workflow` to avoid a generated workflow (we write our own) *(verified flag)*.
- [x] Edit `fly.toml` (written to repo root; DEFAULT_URI temporarily the fly.dev host):

```toml
app = "10xdevs-home-library"
primary_region = "fra"   # decided in D6

[build]
  dockerfile = "Dockerfile"

[env]
  APP_ENV = "prod"
  TRUSTED_PROXIES = "REMOTE_ADDR"
  DEFAULT_URI = "https://biblioteka.example.com"   # replace with the D4 hostname
  # secrets (APP_SECRET, DATABASE_URL, MAILER_DSN) go to `fly secrets`

[deploy]
  strategy = "rolling"
  release_command = "bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"
  release_command_timeout = "5m"

[http_service]
  internal_port = 8080
  force_https = true
  auto_stop_machines = "off"
  auto_start_machines = true
  min_machines_running = 1

  [[http_service.checks]]
    grace_period = "15s"
    interval = "30s"
    timeout = "5s"
    method = "get"
    path = "/healthz"
    [http_service.checks.headers]
      X-Forwarded-Proto = "https"

[[vm]]
  size = "shared-cpu-1x"
  memory = "512mb"
```

  *(All keys verified against docs.fly.io/reference/configuration.)* No `[[mounts]]`: the DB is external, the machine stays stateless.
- [x] `fly config validate` (valid, 2026-10-21).
- [x] (created by user 2026-10-31, 720h expiry; renew before about 2026-11-30) Create the **scoped deploy token** now (Phase 1B strategy): `fly tokens create deploy -a 10xdevs-home-library -x 720h` (HUMAN runs it; store in GitHub secret and password manager).

**Verify:** `fly apps list` shows the app; `fly ips list` shows shared IPv4/IPv6 only; `fly config validate` OK.

**Support steps:** app name taken, wrong org (`-o`), launch generated unwanted files (delete `Dockerfile` overwrite via git diff and restore); a `[[vm]]` mismatch with `fly scale` resets on deploy *(verified)*, so keep `fly.toml` authoritative.

---

## Phase 4 - Static egress IP, secrets, and DB wiring (HUMAN for credentials)

- [x] Egress IPs allocated 2026-10-21 for `10xdevs-home-library` in `fra`: IPv4 `209.71.114.192`, IPv6 `2a09:8280:e612:1:0:1c3:35f6:0`.
- [x] `fly ips list` and record both addresses (above).
- [x] **HUMAN** In the cyberfolks panel add **both** addresses as hosts for the DB user (1D). Keep your own current IP too so you can run `mysqldump`/Adminer locally. Never use `%`.
- [x] Wait a few minutes: new egress IPs may take a short time to apply to existing machines *(verified)*. (Release command connected from the egress IP 209.71.114.192 on first deploy.)
- [x] **HUMAN** Generate `APP_SECRET` (PowerShell: `-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })`).
- [x] **HUMAN** Set secrets in your own terminal (not in chat): `fly secrets set APP_SECRET=... DATABASE_URL="mysql://USER:PASS@HOST:PORT/DBNAME?serverVersion=8.0&charset=utf8mb4" --stage --app 10xdevs-home-library` (`--stage` skips deployment *(verified)*). URL-encode special characters in the password.
- [x] `fly secrets list` shows names and digests only.

**Verify:** after the first deploy (Phase 5) run `fly ssh console -C "php -r \"new PDO('mysql:host=HOST;port=PORT;dbname=DB','USER','PASS'); echo 'ok';\""` *(command shape unverified)* from the machine, and confirm the migration release command connects.

**Support steps:**
- `Access denied ... @'<ip>'`: the message shows the IP cyberfolks sees; compare with `fly ips list`; add it for **that DB user** in the panel.
- Release command (temporary machine) cannot connect while the app can: the temp machine may not use the app's egress IP *(unverified)*; test with a dry deploy and read `fly logs`; fallback: run migrations from a whitelisted machine (`fly ssh console` after deploy) or from your own whitelisted IP.
- `SQLSTATE[HY000] [2002]` timeout: wrong port (3307 vs 3308 vs 3306) or firewall; test from your laptop.
- IPv6 not accepted by cyberfolks: use the IPv4 literal host in `DATABASE_URL` so connections are IPv4 only.
- Leaked secret: rotate DB password in the panel (HUMAN), then `fly secrets set` the new `DATABASE_URL`; rotate `APP_SECRET` only deliberately (invalidates sessions).
- Static egress IP billing: `fly ips list`; release with `fly ips release-egress <ip>` only when intentionally giving up the whitelist (HUMAN).

---

## Phase 5 - First deploy (HUMAN approves, AGENT executes)

Pre-flight (all `[x]`):
- [ ] Phases 1-4 verified (open items remain); D7 decision recorded; `php bin/phpunit` green (verified 2026-10-31: 1 test, 2 assertions, OK, local PHP 8.5.11).
- [x] DB reachable from your whitelisted IP; egress IPs whitelisted (migrations ran on first deploy).
- [x] Explicit user go-ahead for the production change (deploy executed 2026-10-21).

Steps:
- [x] `fly deploy --ha=false` (HA default would create spare machines; `--ha=false` is the flag named in infrastructure.md).
- [x] Watch the release command (non-zero exit aborts the deploy; default timeout 5 min *(verified)*).
- [x] `fly status`, `fly logs --no-tail`, `fly releases --image`.
- [ ] Create the first household user (no self-registration in MVP) via a console command through `fly ssh console`; **HUMAN** types the password.

**Verify (production smoke test):**
1. `curl -sI https://10xdevs-home-library.fly.dev/healthz` = 200.
2. `curl -sI http://10xdevs-home-library.fly.dev/` redirects to https.
3. Login page: assets load, links/forms are `https://` (proves `trusted_proxies`).
4. Log in, `fly machine restart <id>`, reload: still logged in (DB sessions).
5. Time first page load from Poland: under 2 s (also after 30 minutes idle).
6. `fly logs --no-tail`: no warning flood. Connection count on the DB stays under 5.

**Support steps:**
- Health check hangs: raise `grace_period`; nginx must listen on 8080.
- Health check gets a redirect: keep the `X-Forwarded-Proto = "https"` check header *(verified)*.
- Mixed content / `http://` URLs: `TRUSTED_PROXIES` not applied; debug with a temporary route echoing `isSecure()` and `getClientIp()`; remove it afterwards.
- `Release command failed`: `fly logs`, fix, redeploy; the previous release keeps serving.
- OOM kills: raise memory to 1 GB in `fly.toml` (about $6.70/month *(verified, NA)*).
- Rollback now: `fly releases --image` then `fly deploy --image <ref> --ha=false` (migrations do not roll back; Phase 9).
- Debug shell: `fly ssh console`, `fly machine list`, `fly doctor`.

---

## Phase 6 - Custom domain and certificate (HUMAN does DNS) - SKIPPED for now: using 10xdevs-home-library.fly.dev (decided 2026-10-21)

- [ ] `fly certs add <hostname> --app 10xdevs-home-library`.
- [ ] `fly certs show <hostname>` (or `fly certs setup`) to read the **exact DNS records** required *(unverified output shape)*.
- [ ] **HUMAN** Create the records at the DNS provider: for a subdomain a `CNAME <sub> -> 10xdevs-home-library.fly.dev`; for an apex use the `A` and `AAAA` records Fly prints from `fly ips list`.
- [ ] `fly certs check <hostname>` until the certificate shows as issued.
- [ ] Update `DEFAULT_URI` in `fly.toml` to the real hostname and redeploy.
- [ ] Re-run smoke tests 1-4 on the custom hostname; optionally add a redirect from `*.fly.dev` (low priority).

**Support steps:** see 1C (propagation, CAA, Cloudflare proxy). If the certificate fails for days, remove and re-add it (`fly certs remove`, `fly certs add`) after checking DNS with `nslookup`.

---

## Phase 7 - CI/CD: auto-deploy on merge (AGENT writes, HUMAN adds secret)

- [x] **HUMAN** Add the deploy token as GitHub secret `FLY_API_TOKEN`. (done by user 2026-10-31; not verified by the agent, `gh` not logged in)
- [x] `.github/workflows/deploy.yml` (written 2026-10-21, actions pinned by major tag, not SHA): trigger `push` on `main`; job `test` (setup-php 8.4, `composer install`, MySQL service for tests, `php bin/phpunit`); job `deploy` (`needs: test`, `concurrency: deploy-production`, `cancel-in-progress: false`): `superfly/flyctl-actions/setup-flyctl@master`, then `flyctl deploy --remote-only --ha=false`. Pin action versions/SHAs.
- [ ] Optional GitHub `environment: production` with a required reviewer as the approval gate.
- [x] Protect `main` (PR + passing tests). (applied 2026-10-31 via `gh api`: PR required with 0 approvals, required check `test`, enforced for admins, no force pushes or deletions. The `test` check has never run yet; if the first PR cannot merge, confirm the check name in the PR checks list.)
- [x] (verified in `deploy.yml` 2026-10-31: `mariadb:10.11` service, local `DATABASE_URL`, no cyberfolks credentials) **Important cyberfolks implication:** CI tests must use a **local** MySQL service container, never the cyberfolks DB (GitHub runners have unpredictable IPs that cannot be whitelisted safely).

**Verify:** a trivial PR merge triggers test then deploy; `fly releases` shows the new release; smoke tests 1-3 pass.

**Support steps:** `No access token available` = secret name or expired token (recreate with `-x`); forked PRs get no secrets (deploy only from `main`); builder flakiness: re-run the job.

---

## Phase 8 - Operations, access boundary, cost

- [ ] Agent may run unattended: `fly deploy --ha=false`, `fly logs --no-tail`, `fly releases --image`, `fly status`, `fly secrets list`.
- [ ] Human only: `fly secrets set/unset` for `APP_SECRET`/`DATABASE_URL`, `fly apps destroy`, `fly ips release-egress`, `fly certs remove`, DB drops, token creation/revocation, DNS changes, cyberfolks whitelist edits.
- [ ] CLI only for production; no `fly mcp` (experimental).
- [ ] Propose a runbook in `AGENTS.md` through `/10x-agents-md` or `/10x-lesson` (not edited silently).
- [ ] After week 1: check Fly billing (expect about $7/month: machine + egress IPv4); `fly machines list` shows exactly 1.
- [ ] Optional external uptime ping on `/healthz`.

---

## Phase 9 - Backups, restore drill, rollback runbook

Data at risk: household ratings and read-status.

- [ ] Ask cyberfolks about their backup frequency/retention and restore process (1D); do not assume.
- [ ] **Independent dump** done from a whitelisted place: weekly manual or scheduled `mysqldump --single-transaction --no-tablespaces -h HOST -P PORT -u USER -p DB | gzip > backup-YYYYMMDD.sql.gz` from your own whitelisted IP (not from GitHub Actions), stored outside both cyberfolks and Fly (e.g. encrypted local/cloud drive).
- [ ] Take a dump before every migration that drops/alters data.
- [ ] **Restore drill once before launch:** import the dump into the local compose MySQL and open the app against it.
- [ ] App rollback: `fly releases --image` then `fly deploy --image <ref> --ha=false`.
- [ ] Migration rollback: `down()` per migration; revert via `fly ssh console -C "bin/console doctrine:migrations:migrate prev --no-interaction"` only with human confirmation and a fresh dump.
- [ ] DB unreachable: cyberfolks status, whitelist, egress IP unchanged (`fly ips list`), connection limit.
- [ ] Secret compromised: rotate at source, `fly secrets set`, revoke old tokens (HUMAN).

**Support steps:** `mysqldump` access denied (`--no-tablespaces`, missing `LOCK TABLES`), large dumps (`gzip`), shared hosting `max_allowed_packet` limits.

---

## Phase 10 - Hardening and follow-ups

- [ ] `/10x-lesson` for agent mistakes found (e.g. "always `--ha=false`", "never whitelist `%`").
- [ ] Mail provider for password reset (nice-to-have): transactional provider over HTTPS API or SMTP submission 587/465; outbound port 25 is often restricted *(unverified for Fly)*; SPF/DKIM on the domain.
- [ ] Symfony 8.1 is not LTS; maintenance ends 01/2027, plan the upgrade after the 2027-01-10 deadline.
- [ ] Revisit region choice or a DB provider with TLS if latency or security measurements demand it.

---

## Risk register (updated)

| Risk | Source | Handled in |
|---|---|---|
| **cyberfolks TLS unconfirmed: DB traffic may be plaintext over the internet** | New (research 2026-10-20) | 1D, D7 |
| **IP whitelist vs dynamic Fly IPs** | New | Phase 4 static egress ($3.60/month) |
| **Release-command machine may not use the app egress IP** | New *(unverified)* | Phase 4 support steps |
| **5-connection limit per DB user (WebAs, outside network)** | New | php-fpm `pm.max_children = 4`, no persistent connections |
| **DB latency (Poland vs region)** | New | D6, 1E |
| **`clear_env` hides env/secrets from php-fpm** | New (nginx change) | Phase 2 |
| DB lost/corrupted | infrastructure.md | Phase 9 |
| Cold start vs 2 s | infrastructure.md | `auto_stop_machines = "off"`, build-time warmup |
| Wrong URLs/scheme | infrastructure.md | Phase 2 `trusted_proxies`, Phase 5 verify 3 |
| Sessions/cache lost | infrastructure.md | DB sessions, build-time warmup |
| Double bill from HA | infrastructure.md | `--ha=false` everywhere |
| Irreversible migration | infrastructure.md | `release_command`, Phase 9 |
| Symfony 8.1 EOL | infrastructure.md | Phase 10 |
| `fly mcp` experimental | infrastructure.md | CLI only |

## Sources checked

- docs.fly.io/reference/configuration, /about/pricing, /networking/egress-ips, /reference/runtime-environment, /volumes/volume-manage
- docs.fly.io/flyctl/install, /flyctl/launch, /flyctl/tokens-create-deploy, /flyctl/secrets-set
- cyberfolks.pl/pomoc/jak-polaczyc-sie-z-baza-mysql-z-zewnatrz/ (remote MySQL, whitelist, ports, limits)
- fly.io/docs/laravel/advanced-guides/using-symfony (we avoid the scanner trick)

## Execution log

- 2026-10-21: first deploy OK (release 8, machine 9080dd6df22d08, fra). Learned: (1) DATABASE_URL percent-encoding breaks `%env(resolve:...)%`, now plain `%env(DATABASE_URL)%`; (2) DB is MariaDB 10.11 -> `serverVersion=10.11.0-MariaDB`; (3) release-command machine does use the app egress IP 209.71.114.192; (4) `fly launch --yes` created a stray app when the name existed (delete `10xdevs-home-library-quiet-harborbird-9424`, human). Smoke: /healthz 200 in 0.13 s, http -> https 301, check passing. Not yet done: login-page/https URL check, session survives restart (sessions still file-based), first user, TLS handshake not confirmed.
- 2026-10-21 (later): stray app deleted by user. Custom domain skipped (Phase 6), `*.fly.dev` stays. Local dev switched to `mariadb:10.11` (`compose.yaml`, new volume `mariadb_data`; old `database_data` volume with MySQL 8 files left untouched), `.env` serverVersion=10.11.0-MariaDB, AGENTS.md updated. CI MySQL service in Phase 7 must also be `mariadb:10.11`.

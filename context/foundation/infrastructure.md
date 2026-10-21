---
project: my-home-library
researched_at: 2026-10-20
recommended_platform: Fly.io
runner_up: Railway
context_type: mvp
tech_stack:
  language: PHP 8.4+
  framework: Symfony 8.1 (Twig, Doctrine ORM)
  runtime: php-cgi / FrankenPHP in a Docker container
---

## Recommendation

**Deploy on Fly.io.**

Fly.io scored highest (9/10) on the agent-friendly criteria and is the cheapest realistic option (about $2-5/month for the app machine, no free tier). It runs any Dockerfile as a normal VM, so PHP 8.4 and Symfony 8.1 need no wrapper or beta runtime, and it has a complete CLI loop (deploy, logs, secrets, releases). Interview answers that drove the choice: no persistent connections needed, minimise cost over DX, single region is enough, external database providers are acceptable. The main gap is that Fly offers no managed MySQL, so the database is the open decision (see Risk Register).

## Platform Comparison

Scale: 2 = Pass, 1 = Partial, 0 = Fail. Netlify was removed by the hard filter (no PHP at request time).

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP | Total |
|---|---|---|---|---|---|---|
| Fly.io | Pass | Pass | Pass | Pass | Partial | 9 |
| Railway | Partial | Pass | Pass | Partial | Pass | 8 |
| Render | Partial | Pass | Pass | Pass | Partial | 8 |
| Cloudflare | Pass | Partial | Pass | Partial | Pass | 8 |
| Vercel | Pass | Partial | Pass | Partial | Partial | 7 |
| AWS (Lightsail) | Partial | Partial | Pass | Pass | Partial | 7 |
| Netlify | removed | | | | | |

- **Fly.io**: Docker VMs, `fly deploy`, `fly logs`, `fly releases`, `fly secrets`; docs as `llms.txt`. MCP (`fly mcp`) is experimental, hence Partial.
- **Railway**: Railpack detects Symfony and serves with FrankenPHP; official local and remote MCP. Rollback is dashboard-only and there is no managed MySQL (community template container). Partial on CLI and API.
- **Render**: PHP only via Docker; deploy hooks and an API rollback endpoint, but no CLI rollback. Free tier spins down after 15 minutes (about 1 minute wake-up), which fails the 2 s page-load goal. Always-on app plus self-run MySQL plus disk is roughly $14-20/month.
- **Cloudflare**: PHP only through Containers (GA since 2026-04-13) behind a Worker wrapper; Workers Paid $5/month; ephemeral disk; no managed MySQL. Partial on managed and API.
- **Vercel**: no official PHP runtime; community `vercel-php` (single maintainer) or Container Runtime (BETA). Hobby plan is non-commercial only; Pro is $20/user/month.
- **AWS** (researched 2026-10-20; scored via Lightsail, the cheapest realistic path):
  - **Lightsail:** instance from $5/month (IPv6-only $3.50), container service Nano $7 / Micro $10 / Small $15, managed database from $15/month, optional load balancer $18/month. `aws lightsail push-container-image` plus `create-container-service-deployment` needs AWS CLI v2 and the `lightsailctl` plugin; a failed deployment keeps the previous one running. Container disk is ephemeral. The log command (`get-container-log`) and TLS/custom-domain support for container services were not verified.
  - **App Runner:** closed to new customers since 2026-04-30 (AWS points to ECS Express Mode). Not an option.
  - **ECS Fargate / Express Mode:** no surcharge for Express Mode, but you pay for Fargate, an ALB (about $18-19/month) and CloudWatch; with a database expect $45+/month (third-party estimate, unverified).
  - **Elastic Beanstalk:** supports PHP 8.4 on Amazon Linux 2023 but provisions EC2 and usually an ALB; its 2026 status was not verified.
  - **Databases:** RDS MySQL free tier (12 months) applies only to accounts created before 2025-07-15; Frankfurt RDS prices were not retrievable. Aurora Serverless v2 can scale to 0 ACU (about 15 s resume per a third-party source).
  - **Agent integration:** AWS docs pages are available as `.md`; `llms.txt` unverified. A managed AWS MCP Server is reported GA from 2026-05-06 (secondary sources, unverified).
  - **Free plan (secondary sources, unverified):** accounts created after 2025-07-15 get up to $200 in credits (6 months or until credits run out).
  - **Beginner penalty:** IAM, MFA and a Budget alert must be set up first; public IPv4, NAT gateways and ALBs cause billing surprises. This lowers the CLI and MCP scores to Partial.
- **Netlify**: PHP runs only at build time; no custom containers.

Cost is weighted heavily (interview: minimise cost); prior platform familiarity was a non-factor ("no strong familiarity").

### Shortlisted Platforms

#### 1. Fly.io (Recommended)

Highest score, cheapest, full CLI loop, plain Dockerfile. Gap: no managed MySQL and no free tier.

#### 2. Railway

About $5-10/month including MySQL from a community template in the same project, official MCP, EU region (Amsterdam). Gap: rollback only via dashboard, MySQL is a self-maintained container, trial limits weaken rollback.

#### 3. Cloudflare (Containers)

About $5/month plus external MySQL. Gap: JS Worker wrapper, ephemeral disk and sleeping container, 1-3 s cold starts. Chosen over Render because Render is two to three times more expensive for this stack.

## Anti-Bias Cross-Check: Fly.io

### Devil's Advocate — Weaknesses

1. No managed MySQL: either self-run on a volume (single node, no failover, manual backups) or an external provider with extra cost and network latency against the 2 s page-load goal.
2. No free tier; a card is required. Scale-to-zero cold starts of PHP boot can exceed 2 s.
3. Symfony is not first-class: the official guide uses the Laravel scanner (`touch artisan`), so a hand-written Dockerfile is the safer route. PHP 8.4 / Symfony 8.1 on Fly was not verified.
4. Volumes are tied to one host: a single machine, no zero-downtime deploys, risk of data loss.
5. Rollback is only `fly deploy --image <ref>`; migrations do not roll back with the image. MCP is experimental.

### Pre-Mortem — How This Could Fail

The team deployed Symfony on Fly with MySQL on a volume because it was the cheapest option. Months later the host failed and the volume was lost; nobody had automated `mysqldump`, so household ratings and read-status vanished. Earlier, the home page had been slow after the machine slept overnight: cold start plus `var/cache` warm-up took several seconds, against the 2 s requirement. Behind Fly's proxy, Symfony generated `http://` links because `trusted_proxies` was never set, and sessions were lost on restart because they lived on the ephemeral disk. A migration that had succeeded could not be reverted together with the image.

### Unknown Unknowns

- Symfony needs `framework.trusted_proxies` set behind Fly's proxy, otherwise HTTPS and generated URLs are wrong.
- Machine disk is ephemeral: store sessions in the database and warm `var/cache` at image build time.
- `fly deploy` has `--ha` enabled by default and creates spare machines; use `--ha=false` to avoid doubling the bill.
- Migrations run through `release_command` in a temporary machine; a non-zero exit stops the deploy.
- Symfony 8.1 is not an LTS release; maintenance ends 01/2027, right after the 2027-01-10 deadline.

## Operational Story

- **Preview deploys**: not provided by Fly out of the box. Options: a second app (`my-home-library-staging`) deployed from pull requests via GitHub Actions, or none for the MVP (2-4 users). Decision deferred to the CI step.
- **Secrets**: `fly secrets set KEY=value` (platform vault; not readable back by value); `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN` live there. Local development uses `.env.local` (git-ignored). The GitHub Actions deploy token is stored in GitHub Secrets. Rotation: set a new value with `fly secrets set` (triggers a redeploy).
- **Rollback**: `fly releases --image` lists image references; redeploy a previous one with `fly deploy --image <ref>`. Time-to-revert is about the length of a deploy. Database migrations do not roll back automatically; write reversible migrations or take a `mysqldump` first.
- **Approval**: a human approves production deploys (merge to main, per the chosen auto-deploy flow), rotating `APP_SECRET`, dropping a database or deleting a volume. An agent may deploy (`fly deploy`), read logs, and list releases unattended.
- **Logs**: `fly logs --app my-home-library --no-tail` (buffered logs, read-only); without `--no-tail` it streams. Application logs also go to stderr via Monolog.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| MySQL on a single Fly volume is lost or corrupted | Devil's advocate / Pre-mortem | M | H | Prefer a managed external MySQL; if self-run, schedule `mysqldump` to off-platform storage and test a restore |
| External MySQL adds latency and cost (only one price anchor found: Lightsail managed database from $15/month, plan engine naming unverified) | Devil's advocate / Research finding | M | M | Pick a provider in the same region as the Fly app; compare provider prices before committing; Lightsail in eu-central-1 is one candidate, but adds an AWS account to manage |
| Cold start after auto-stop breaks the 2 s page-load goal | Devil's advocate / Pre-mortem | M | M | Set `min_machines_running = 1` (small always-on machine) and warm `var/cache` in the image |
| Wrong URLs and scheme behind Fly proxy | Unknown unknowns | H | M | Set `framework.trusted_proxies` and `trusted_headers` before first deploy |
| Sessions or cache lost on restart | Pre-mortem | H | M | Store sessions in the database; build the image with `cache:warmup --env=prod` |
| Bill doubles from default high-availability machines | Research finding | H | L | Deploy with `--ha=false` and set the machine count to 1 in `fly.toml` |
| Irreversible migration after rollback | Devil's advocate | M | H | Reversible migrations; run `release_command = "bin/console doctrine:migrations:migrate --no-interaction"`; dump before risky migrations |
| Symfony 8.1 loses maintenance in 01/2027 | Unknown unknowns | H | L | Plan an upgrade to the next LTS or 8.x minor after the deadline |
| `fly mcp` is experimental | Research finding | H | L | Use the CLI, not MCP, for deploy automation |
| PHP 8.4 / Symfony 8.1 on Fly not verified end to end | Devil's advocate | M | M | Smoke-test with a minimal Dockerfile before building features |

## Getting Started

Commands below were checked against Fly's current docs (`docs.fly.io`, 2026-10-20) for `fly deploy`, `fly logs`, `fly releases` and the `fly.toml` reference. The `fly secrets`, `fly launch` and `fly volumes` commands were not fetched; check `fly <command> --help` before use.

1. Install the Fly CLI and sign in (`fly auth login`); a payment card is required (no free tier).
2. Write a Dockerfile for PHP 8.4 + Symfony 8.1 (e.g. FrankenPHP or php-fpm with a web server), with `composer install --no-dev --optimize-autoloader` and `bin/console cache:warmup --env=prod` at build time. Do not rely on the Laravel scanner trick.
3. Create the app with `fly launch --no-deploy` and edit `fly.toml`: `[http_service]` with `auto_stop_machines = "stop"`, `auto_start_machines = true`, `min_machines_running = 1`; `[deploy]` with `release_command = "bin/console doctrine:migrations:migrate --no-interaction"`.
4. Decide the MySQL option (external managed provider in the same region, or a self-run container with a volume and automated dumps), then set secrets: `fly secrets set APP_SECRET=... DATABASE_URL=...`.
5. Deploy with `fly deploy --ha=false`, then check `fly logs --no-tail` and `fly releases --image`.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration
- CI/CD pipeline setup
- Production-scale architecture (multi-region, HA, DR)
- Cost of an external managed MySQL provider (beyond the Lightsail anchor above)
- Frankfurt RDS/Aurora prices and exact ECS Express Mode per-service cost (not retrievable during the AWS research)

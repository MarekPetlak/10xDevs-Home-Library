// Shared helpers for the agent hooks (Node: this repo is developed on Windows without jq).
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, appendFileSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { isAbsolute, join, resolve } from 'node:path';

const CLEAN_ENV = { ...process.env, NO_COLOR: '1', FORCE_COLOR: '0', TERM: 'dumb' };
delete CLEAN_ENV.GREP_OPTIONS;

export function readPayload() {
  let raw = '';
  try {
    raw = readFileSync(0, 'utf8');
  } catch {
    return {};
  }
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {}; // unparsable payload = nothing to check, never a hook failure
  }
}

export function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, {
    encoding: 'utf8',
    env: CLEAN_ENV,
    stdio: ['ignore', 'pipe', 'pipe'],
    maxBuffer: 32 * 1024 * 1024,
    ...opts,
  });
  const out = `${r.stdout ?? ''}${r.stderr ?? ''}`.trim();
  return { ok: r.status === 0 && !r.error, out, error: r.error, signal: r.signal };
}

export function git(cwd, ...args) {
  const r = run('git', ['-C', cwd, ...args]);
  return r.ok ? r.out : null;
}

const norm = (p) => (process.platform === 'win32' ? resolve(p).toLowerCase() : resolve(p));

export function sameRepo(a, b) {
  const ca = git(a, 'rev-parse', '--path-format=absolute', '--git-common-dir');
  const cb = git(b, 'rev-parse', '--path-format=absolute', '--git-common-dir');
  return Boolean(ca && cb && norm(ca) === norm(cb));
}

export function absolutize(file, cwd) {
  return isAbsolute(file) ? file : resolve(cwd || process.cwd(), file);
}

export function registryPath(sessionId) {
  const sid = String(sessionId ?? '').replace(/[^A-Za-z0-9_-]/g, '');
  return sid ? join(process.env.TMPDIR || tmpdir(), 'claude-hooks', `${sid}.roots`) : null;
}

export function registerRoot(sessionId, root) {
  const p = registryPath(sessionId);
  if (!p) return;
  try {
    mkdirSync(join(p, '..'), { recursive: true });
    appendFileSync(p, `${root}\n`);
  } catch {
    /* registry is best effort; the Stop hook still sweeps the session cwd */
  }
}

export function readRoots(sessionId) {
  const p = registryPath(sessionId);
  if (!p || !existsSync(p)) return [];
  return readFileSync(p, 'utf8').split('\n').filter(Boolean);
}

export function clearRoots(sessionId) {
  const p = registryPath(sessionId);
  if (p && existsSync(p)) writeFileSync(p, '');
}

// PHPStan covers what phpstan.neon lists under parameters.paths (src, tests).
export const isPhpstanFile = (rel) => /^(src|tests)\/.+\.php$/i.test(rel);

// What a file of this type needs. null = not covered by any check.
export function lintCommandFor(rel) {
  if (isPhpstanFile(rel)) return { label: 'phpstan', phpstan: true };
  if (/\.php$/i.test(rel)) return { label: 'php -l', cmd: 'php', args: ['-l', rel] }; // migrations/, config/, public/
  if (/\.twig$/i.test(rel)) return { label: 'lint:twig', cmd: 'php', args: ['bin/console', 'lint:twig', rel, '--no-interaction'] };
  if (/\.ya?ml$/i.test(rel) && /^(config|translations)\//.test(rel)) {
    return { label: 'lint:yaml', cmd: 'php', args: ['bin/console', 'lint:yaml', rel, '--no-interaction'] };
  }
  return null;
}

// Static analysis for src/ and tests/ files in one PHPStan run (phpstan.neon + baseline apply).
export function phpstanCheck(root, rels) {
  if (rels.length === 0) return null;
  if (!existsSync(join(root, 'vendor/bin/phpstan'))) {
    return 'phpstan is not installed (vendor/bin/phpstan missing): run `composer install`.';
  }
  const r = run('php', ['vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=raw', '--memory-limit=1G', '--no-interaction', ...rels], { cwd: root, timeout: 80_000 });
  if (r.error || r.signal) return `phpstan did not finish (${r.error?.code ?? r.signal}).`;
  if (r.ok) return null;
  // Drop PHPStan's generic advice banner; keep the error lines.
  const lines = r.out.split('\n').filter((l) => /:\d+:/.test(l) || /parse error|could not|not found/i.test(l));
  return `phpstan reported errors:\n${(lines.length ? lines.join('\n') : r.out).slice(0, 6000)}`;
}

export function lintFile(root, rel) {
  const c = lintCommandFor(rel);
  if (!c) return null;
  if (c.phpstan) return phpstanCheck(root, [rel]);
  const r = run(c.cmd, c.args, { cwd: root, timeout: 25_000 });
  if (r.error) return `${c.label} could not run (${r.error.code ?? r.error.message}): is php on PATH?`;
  if (!r.ok) return `${c.label} failed for ${rel}:\n${r.out}`;
  return null;
}

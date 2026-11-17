#!/usr/bin/env node
// Stop: sweep everything this turn changed before the agent finishes. One retry only.
//  1. PHPStan on changed src/tests php files; php -l / lint:twig / lint:yaml on the rest (also files rewritten via shell, which skip the per-edit hook)
//  2. container wiring check (lint:container) when src/ or config/ changed
//  3. PHPUnit for changed tests + tests that mention a changed src/ class
//     (needs MariaDB from compose on the port in .env.test; if it is down, that part is
//      SKIPPED and the skip is named in the output, never silent)
import { existsSync, readFileSync } from 'node:fs';
import net from 'node:net';
import { join } from 'node:path';
import { clearRoots, git, isPhpstanFile, lintFile, phpstanCheck, readPayload, readRoots, run } from './lib.mjs';

const TEST_TIMEOUT_MS = 90_000;

function dbTarget(root) {
  try {
    const env = readFileSync(join(root, '.env.test'), 'utf8');
    const m = env.match(/^DATABASE_URL=["']?[a-z0-9+]+:\/\/[^@]*@([^:/?"']+):?(\d+)?/im);
    if (m) return { host: m[1], port: Number(m[2] || 3306) };
  } catch {
    /* fall through */
  }
  return null;
}

function reachable({ host, port }) {
  return new Promise((done) => {
    const s = net.connect({ host, port });
    const finish = (ok) => { s.destroy(); done(ok); };
    s.setTimeout(1500, () => finish(false));
    s.once('connect', () => finish(true));
    s.once('error', () => finish(false));
  });
}

function changedFiles(root) {
  const a = git(root, 'diff', '--name-only', 'HEAD') ?? '';
  const b = git(root, 'ls-files', '-o', '--exclude-standard') ?? '';
  return [...new Set(`${a}\n${b}`.split('\n').map((s) => s.trim()).filter(Boolean))].filter((f) => existsSync(join(root, f)));
}

function testsFor(root, files) {
  const tests = new Set();
  for (const f of files) {
    if (/^tests\/.*Test\.php$/.test(f)) { tests.add(f); continue; }
    const m = f.match(/^src\/.*?([A-Za-z0-9_]+)\.php$/);
    if (!m) continue;
    // Tests that mention the class name (one level; the CI run covers the rest).
    const r = run('git', ['-C', root, 'grep', '--untracked', '-lw', '--color=never', m[1], '--', 'tests/*Test.php']);
    for (const t of r.out.split('\n').map((s) => s.trim()).filter(Boolean)) tests.add(t);
  }
  return [...tests];
}

async function main() {
  const payload = readPayload();
  // Already sent back once by this hook (loop_count covers Cursor importing it): let it finish.
  if (payload.stop_hook_active === true || (payload.loop_count ?? 0) !== 0) return 0;

  const cwd = payload.cwd || process.cwd();
  const roots = [...new Set([git(cwd, 'rev-parse', '--show-toplevel'), ...readRoots(payload.session_id)].filter(Boolean))]
    .filter((r) => existsSync(r));
  if (roots.length === 0) {
    process.stdout.write(JSON.stringify({ systemMessage: `end-of-turn.mjs: no git checkout at ${cwd}, nothing checked.` }));
    return 0;
  }

  let report = '';
  const skipped = [];

  for (const root of roots) {
    const reportBefore = report.length;
    const files = changedFiles(root);
    if (files.length === 0) continue; // Q&A turn: nothing to check

    // One PHPStan run for all changed src/tests files; the other linters per file.
    const stan = phpstanCheck(root, files.filter(isPhpstanFile));
    if (stan) report += `\n${stan}\n`;
    for (const f of files.filter((x) => !isPhpstanFile(x))) {
      const problem = lintFile(root, f);
      if (problem) report += `\n${problem}\n`;
    }

    // Skip when a file already failed its own lint: the container error would only repeat it.
    if (report.length === reportBefore && files.some((f) => /^(src|config)\//.test(f))) {
      const r = run('php', ['bin/console', 'lint:container', '--no-interaction'], { cwd: root, timeout: 60_000 });
      if (!r.ok) report += `\nlint:container failed (${root}):\n${r.error ? `php could not run: ${r.error.message}` : r.out.slice(0, 3000)}\n`;
    }

    const tests = testsFor(root, files);
    if (tests.length > 0) {
      const db = dbTarget(root);
      if (db && !(await reachable(db))) {
        skipped.push(` PHPUnit (${tests.length} related test file(s)): database ${db.host}:${db.port} unreachable, run \`docker compose up -d database\`;`);
      } else {
        const r = run('php', ['bin/phpunit', '--no-progress', '--colors=never', ...tests], { cwd: root, timeout: TEST_TIMEOUT_MS });
        if (r.error || r.signal) {
          report += `\nPHPUnit did not finish (${r.error?.code ?? r.signal}) for: ${tests.join(', ')}\n${r.out.slice(-2000)}\n`;
        } else if (!r.ok) {
          report += `\nTests related to changed files fail (${root}):\n${r.out.slice(-6000)}\n`;
        }
      }
    }
  }

  if (report) {
    if (skipped.length) report += `\nSkipped on this host:${skipped.join('')}\n`;
    console.error(`Fix these before you finish:${report}`);
    return 2;
  }
  clearRoots(payload.session_id);
  if (skipped.length) {
    process.stdout.write(JSON.stringify({ systemMessage: `end-of-turn.mjs passed, but skipped on this host:${skipped.join('')}` }));
  }
  return 0;
}

try {
  process.exit(await main());
} catch (e) {
  console.error(`end-of-turn.mjs crashed: ${e?.stack ?? e}`);
  process.exit(2);
}

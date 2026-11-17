// Proof for the Claude Code agent hooks in .claude/hooks (run: npm run test:hooks).
// Every case pipes a real payload into the real script, inside a throw-away git repo.
// Only slow/absent things are stubbed: bin/console (lint:twig, lint:yaml, lint:container)
// vendor/bin/phpstan and bin/phpunit (needs MariaDB). php -l and git are the real ones.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, rmSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HOOKS = resolve(dirname(fileURLToPath(import.meta.url)), '../../.claude/hooks');
const settings = JSON.parse(readFileSync(resolve(HOOKS, '../settings.json'), 'utf8'));

const sh = (cwd, cmd, args, env = {}) =>
  spawnSync(cmd, args, { cwd, encoding: 'utf8', env: { ...process.env, ...env } });

// Stub console: fails (exit 1) when the linted file contains BROKEN; lint:container fails when
// src/ContainerBreaker.php exists.
const CONSOLE = `<?php
$cmd = $argv[1] ?? '';
if ($cmd === 'lint:container') {
    if (file_exists(__DIR__ . '/../src/ContainerBreaker.php')) { fwrite(STDERR, "container broken\\n"); exit(1); }
    exit(0);
}
$f = $argv[2] ?? '';
if ($f !== '' && str_contains(file_get_contents(__DIR__ . '/../' . $f), 'BROKEN')) { echo "$cmd: BROKEN in $f\\n"; exit(1); }
exit(0);
`;
// Stub phpunit: records its args, fails when a given test file contains FAILING.
const PHPUNIT = `<?php
file_put_contents(__DIR__ . '/../phpunit-args.txt', implode(' ', array_slice($argv, 1)));
foreach (array_slice($argv, 1) as $a) {
    if (str_ends_with($a, 'Test.php') && str_contains(file_get_contents(__DIR__ . '/../' . $a), 'FAILING')) {
        echo "FAILED: $a\\n"; exit(1);
    }
}
exit(0);
`;

// Stub phpstan: reports an error (exit 1) for any analysed file containing STANERR or a syntax slip.
const PHPSTAN = `<?php
$bad = 0;
foreach (array_slice($argv, 2) as $a) {
    if ($a === '' || $a[0] === '-' || $a === 'analyse') continue;
    $src = @file_get_contents(__DIR__ . '/../../' . $a);
    if ($src !== false && (str_contains($src, 'STANERR') || str_contains($src, '= ;'))) { echo "$a:3:phpstan error in $a [identifier=stub]\n"; $bad = 1; }
}
exit($bad);
`;

function makeRepo(dbPort = 1) {
  const root = mkdtempSync(join(tmpdir(), 'agent-hooks-'));
  sh(root, 'git', ['init', '-q']);
  sh(root, 'git', ['config', 'user.email', 't@t']);
  sh(root, 'git', ['config', 'user.name', 't']);
  mkdirSync(join(root, '.claude'), { recursive: true });
  cpSync(HOOKS, join(root, '.claude/hooks'), { recursive: true });
  mkdirSync(join(root, 'bin'));
  mkdirSync(join(root, 'src'));
  mkdirSync(join(root, 'tests'));
  mkdirSync(join(root, 'templates'));
  mkdirSync(join(root, 'config'));
  writeFileSync(join(root, 'bin/console'), CONSOLE);
  writeFileSync(join(root, 'bin/phpunit'), PHPUNIT);
  mkdirSync(join(root, 'vendor/bin'), { recursive: true });
  writeFileSync(join(root, 'vendor/bin/phpstan'), PHPSTAN);
  mkdirSync(join(root, 'migrations'));
  writeFileSync(join(root, 'migrations/Version1.php'), '<?php\n');
  writeFileSync(join(root, '.env.test'), `DATABASE_URL="mysql://root:pw@127.0.0.1:${dbPort}/app"\n`);
  writeFileSync(join(root, 'src/Clean.php'), '<?php\nclass Clean {}\n');
  writeFileSync(join(root, 'tests/CleanTest.php'), '<?php\n// uses Clean\n');
  writeFileSync(join(root, 'README.md'), '# x\n');
  sh(root, 'git', ['add', '-A']);
  sh(root, 'git', ['commit', '-qm', 'init']);
  return root;
}

const hook = (root, name, payload, env = {}) =>
  spawnSync('node', [join(root, '.claude/hooks', name)], {
    cwd: root,
    input: typeof payload === 'string' ? payload : JSON.stringify(payload),
    encoding: 'utf8',
    env: { ...process.env, TMPDIR: join(root, '.tmp'), ...env },
  });
const edit = (root, file, extra = {}) => ({ cwd: root, session_id: 's1', tool_name: 'Edit', tool_input: { file_path: file }, ...extra });
const stop = (root, extra = {}) => ({ cwd: root, session_id: 's1', hook_event_name: 'Stop', ...extra });
const write = (root, rel, body) => {
  mkdirSync(dirname(join(root, rel)), { recursive: true });
  writeFileSync(join(root, rel), body);
  return join(root, rel);
};
const cleanup = (root) => rmSync(root, { recursive: true, force: true });

async function withDb(fn) {
  const srv = net.createServer().listen(0, '127.0.0.1');
  await new Promise((r) => srv.once('listening', r));
  try { return await fn(srv.address().port); } finally { srv.close(); }
}

test('settings.json: both commands reach an executable script even with a stale CLAUDE_PROJECT_DIR', () => {
  const root = makeRepo();
  try {
    cpSync(resolve(HOOKS, '../settings.json'), join(root, '.claude/settings.json'));
    const cmds = [...settings.hooks.PostToolUse, ...settings.hooks.Stop].flatMap((g) => g.hooks.map((h) => h.command));
    assert.equal(cmds.length, 2);
    for (const c of cmds) {
      const r = spawnSync('bash', ['-c', c], { cwd: root, input: '{}', encoding: 'utf8', env: { ...process.env, CLAUDE_PROJECT_DIR: '/does/not/exist' } });
      assert.equal(r.status, 0, `${c}\n${r.stderr}`);
    }
    for (const g of [...settings.hooks.PostToolUse, ...settings.hooks.Stop]) for (const h of g.hooks) assert.ok(h.timeout <= 120, 'timeout is in seconds');
  } finally { cleanup(root); }
});

test('per-edit: broken php blocks with exit 2 and names the file', () => {
  const root = makeRepo();
  try {
    const f = write(root, 'src/Bad.php', '<?php\n$x = ;\n');
    const r = hook(root, 'lint-edited-file.mjs', edit(root, f));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /src\/Bad\.php/);
  } finally { cleanup(root); }
});

test('per-edit: clean php passes', () => {
  const root = makeRepo();
  try {
    const r = hook(root, 'lint-edited-file.mjs', edit(root, join(root, 'src/Clean.php')));
    assert.equal(r.status, 0, r.stderr);
  } finally { cleanup(root); }
});

test('per-edit: phpstan error in src/ and tests/ blocks, naming file and identifier', () => {
  const root = makeRepo();
  try {
    for (const rel of ['src/Typed.php', 'tests/TypedTest.php']) {
      const f = write(root, rel, '<?php\n// STANERR\n');
      const r = hook(root, 'lint-edited-file.mjs', edit(root, f));
      assert.equal(r.status, 2);
      assert.match(r.stderr, new RegExp(rel.replace('.', '\\.')));
      assert.match(r.stderr, /identifier=/);
    }
  } finally { cleanup(root); }
});

test('per-edit: php outside src/tests (migrations) goes through php -l', () => {
  const root = makeRepo();
  try {
    const f = write(root, 'migrations/Version2.php', '<?php\n$x = ;\n');
    const r = hook(root, 'lint-edited-file.mjs', edit(root, f));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /php -l failed/);
  } finally { cleanup(root); }
});

test('per-edit: phpstan not installed fails visibly, not silently', () => {
  const root = makeRepo();
  try {
    rmSync(join(root, 'vendor'), { recursive: true, force: true });
    const r = hook(root, 'lint-edited-file.mjs', edit(root, join(root, 'src/Clean.php')));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /phpstan is not installed/);
  } finally { cleanup(root); }
});

test('per-edit: broken twig and yaml block; clean ones pass', () => {
  const root = makeRepo();
  try {
    const t = write(root, 'templates/a.html.twig', '{{ BROKEN');
    const y = write(root, 'config/a.yaml', 'BROKEN: [');
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, t)).status, 2);
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, y)).status, 2);
    writeFileSync(t, '{{ ok }}');
    writeFileSync(y, 'ok: 1\n');
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, t)).status, 0);
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, y)).status, 0);
  } finally { cleanup(root); }
});

test('per-edit: skipped type, missing file, empty/garbage/pathless payloads all pass', () => {
  const root = makeRepo();
  try {
    const cases = [
      edit(root, join(root, 'README.md')),
      edit(root, join(root, 'src/Nope.php')),
      {},
      'not json',
      '',
      { cwd: root, tool_name: 'Edit', tool_input: {} },
    ];
    for (const c of cases) assert.equal(hook(root, 'lint-edited-file.mjs', c).status, 0, JSON.stringify(c));
  } finally { cleanup(root); }
});

test('per-edit: relative path resolves against cwd', () => {
  const root = makeRepo();
  try {
    write(root, 'src/Bad.php', '<?php\n$x = ;\n');
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, 'src/Bad.php')).status, 2);
  } finally { cleanup(root); }
});

test('per-edit: broken file of another repository is skipped', () => {
  const root = makeRepo();
  const other = makeRepo();
  try {
    const f = write(other, 'src/Bad.php', '<?php\n$x = ;\n');
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, f)).status, 0);
  } finally { cleanup(root); cleanup(other); }
});

test('per-edit: missing php fails visibly, not silently', () => {
  const root = makeRepo();
  try {
    const f = join(root, 'src/Clean.php');
    const r = hook(root, 'lint-edited-file.mjs', edit(root, f), { PATH: dirname(process.execPath) });
    // git is not on that PATH either, so the checkout cannot be resolved: that is also "nothing to check".
    // Keep git reachable, hide only php:
    const gitDir = dirname(spawnSync(process.platform === 'win32' ? 'where' : 'which', ['git'], { encoding: 'utf8' }).stdout.split(/\r?\n/)[0]);
    const r2 = hook(root, 'lint-edited-file.mjs', edit(root, f), { PATH: [dirname(process.execPath), gitDir].join(process.platform === 'win32' ? ';' : ':') });
    void r;
    assert.equal(r2.status, 2);
    assert.match(r2.stderr, /could not run|did not finish/);
  } finally { cleanup(root); }
});

test('end of turn: nothing changed passes without running checks', () => {
  const root = makeRepo();
  try {
    const r = hook(root, 'end-of-turn.mjs', stop(root));
    assert.equal(r.status, 0, r.stderr);
    assert.ok(!existsSync(join(root, 'phpunit-args.txt')));
  } finally { cleanup(root); }
});

test('end of turn: edit that bypassed the per-edit hook (shell write) blocks and names the file', () => {
  const root = makeRepo();
  try {
    write(root, 'src/Sneaky.php', '<?php\n$x = ;\n');
    const r = hook(root, 'end-of-turn.mjs', stop(root));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /src\/Sneaky\.php/);
  } finally { cleanup(root); }
});

test('end of turn: stop_hook_active (and Cursor loop_count) lets the agent finish even with errors', () => {
  const root = makeRepo();
  try {
    write(root, 'src/Sneaky.php', '<?php\n$x = ;\n');
    assert.equal(hook(root, 'end-of-turn.mjs', stop(root, { stop_hook_active: true })).status, 0);
    assert.equal(hook(root, 'end-of-turn.mjs', stop(root, { loop_count: 1 })).status, 0);
  } finally { cleanup(root); }
});

test('end of turn: unparsable payload passes', () => {
  const root = makeRepo();
  try {
    assert.equal(hook(root, 'end-of-turn.mjs', 'not json').status, 0);
  } finally { cleanup(root); }
});

test('end of turn: lint:container failure blocks; related tests are selected by class name; failing test blocks', async () => {
  await withDb(async (port) => {
    const root = makeRepo(port);
    try {
      // clean change + related test passes, test selected by class name
      write(root, 'src/Clean.php', '<?php\nclass Clean { public int $a = 1; }\n');
      let r = hook(root, 'end-of-turn.mjs', stop(root));
      assert.equal(r.status, 0, r.stderr);
      assert.match(readFileSync(join(root, 'phpunit-args.txt'), 'utf8'), /tests\/CleanTest\.php/);

      // behaviourally failing test
      write(root, 'tests/CleanTest.php', '<?php\n// uses Clean FAILING\n');
      r = hook(root, 'end-of-turn.mjs', stop(root));
      assert.equal(r.status, 2);
      assert.match(r.stderr, /FAILED: tests\/CleanTest\.php/);
      write(root, 'tests/CleanTest.php', '<?php\n// uses Clean\n');

      // container wiring broken
      write(root, 'src/ContainerBreaker.php', '<?php\nclass ContainerBreaker {}\n');
      r = hook(root, 'end-of-turn.mjs', stop(root));
      assert.equal(r.status, 2);
      assert.match(r.stderr, /container broken/);
    } finally { cleanup(root); }
  });
});

test('end of turn: database down skips PHPUnit loudly, never silently', () => {
  const root = makeRepo(1); // nothing listens on port 1
  try {
    write(root, 'src/Clean.php', '<?php\nclass Clean { public int $a = 2; }\n');
    let r = hook(root, 'end-of-turn.mjs', stop(root));
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, /skipped on this host.*unreachable/s);
    assert.ok(!existsSync(join(root, 'phpunit-args.txt')));
    // with a real error the block message also names the skip
    write(root, 'src/Bad.php', '<?php\n$x = ;\n');
    r = hook(root, 'end-of-turn.mjs', stop(root));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /Skipped on this host/);
  } finally { cleanup(root); }
});

test('end of turn: sibling worktree edited via per-edit hook is swept', () => {
  const root = makeRepo();
  const wt = `${root}-wt`;
  try {
    assert.equal(sh(root, 'git', ['worktree', 'add', '-q', wt, '-b', 'wt']).status, 0);
    const f = write(wt, 'src/Bad.php', '<?php\n$x = ;\n');
    // edit hook is run from the main checkout (session cwd) against a file in the sibling worktree
    assert.equal(hook(root, 'lint-edited-file.mjs', edit(root, f)).status, 2);
    // Stop: session cwd (root) is clean, but the registered sibling checkout is swept
    const r = hook(root, 'end-of-turn.mjs', stop(root));
    assert.equal(r.status, 2);
    assert.match(r.stderr, /src\/Bad\.php/);
  } finally { cleanup(root); cleanup(wt); }
});

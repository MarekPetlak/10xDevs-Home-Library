#!/usr/bin/env node
// PostToolUse (Write|Edit): lint ONLY the file the agent just edited.
// Exit 2 + stderr is the only combination Claude Code shows to the agent.
import { existsSync } from 'node:fs';
import { dirname, relative } from 'node:path';
import { absolutize, git, lintCommandFor, lintFile, readPayload, registerRoot, sameRepo } from './lib.mjs';

try {
  const payload = readPayload();
  const input = payload.tool_input ?? {};
  const file = input.file_path; // Write|Edit only; there is no notebook editing of PHP
  if (typeof file !== 'string' || !file) process.exit(0);

  const abs = absolutize(file, payload.cwd);
  if (!existsSync(abs)) process.exit(0);

  const root = git(dirname(abs), 'rev-parse', '--show-toplevel');
  if (!root) process.exit(0); // not in a git checkout
  // Files of other repositories (~/.claude, other projects) are not ours to check.
  if (!sameRepo(root, payload.cwd || process.cwd())) process.exit(0);

  const rel = relative(root, abs).replaceAll('\\', '/');
  if (!lintCommandFor(rel)) process.exit(0);

  registerRoot(payload.session_id, root);

  const problem = lintFile(root, rel);
  if (problem) {
    console.error(problem);
    process.exit(2);
  }
  process.exit(0);
} catch (e) {
  // A broken hook must be visible, not green.
  console.error(`lint-edited-file.mjs crashed: ${e?.stack ?? e}`);
  process.exit(2);
}

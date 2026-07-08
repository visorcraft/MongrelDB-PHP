# Contributing to MongrelDB PHP

Thanks for taking the time to help the MongrelDB PHP client. This document
describes how to propose a change, what we expect from a pull request, and
the coding standards that apply to the codebase.

If anything here is unclear or out of date, open an issue or a PR.

## Code of conduct

Be kind, be specific, assume good faith. Disagree about the technical
details, not the person. Public reviews stay focused on the diff.

## How to propose a change

The MongrelDB PHP client uses a standard **fork → branch → pull request**
workflow on GitHub.

1. **Fork** [`visorcraft/MongrelDB-PHP`](https://github.com/visorcraft/MongrelDB-PHP)
   to your GitHub account.
2. **Clone** your fork and add the upstream remote:

   ```sh
   git clone git@github.com:<you>/MongrelDB-PHP.git
   cd MongrelDB-PHP
   git remote add upstream https://github.com/visorcraft/MongrelDB-PHP.git
   ```

3. **Branch** from `master`. Pick a descriptive, kebab-case branch name:
   `fix-query-builder-alias`, `feature/sparse-vector`, `docs/auth-guide`.

   ```sh
   git fetch upstream
   git switch -c my-change upstream/master
   ```

4. **Make focused commits.** One logical change per commit. Run the
   preflight (see below) before pushing.
5. **Open a pull request** against `master` on `visorcraft/MongrelDB-PHP`.
   Fill in the PR template:
   - **What.** One paragraph summary of the change.
   - **Why.** Bug fix? New feature? Doc fix? Link the issue if one
     exists.
   - **How to test.** The exact commands a reviewer should run.
   - **Risk.** What might break? What did you not test?

## Before you push: preflight

Run the full CI preflight locally:

```sh
composer install
composer validate --strict --no-check-publish
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit --testsuite=unit
```

All steps must pass with zero warnings. If a check fails, fix the root
cause — don't silence the linter or skip the test.

To run the live integration suite (requires a running `mongreldb-server`):

```sh
MONGRELDB_URL=http://127.0.0.1:8453 vendor/bin/phpunit --testsuite=live
```

Live tests self-skip when `MONGRELDB_URL` is unset or unreachable.

## What we look for in a review

- The change does one thing and does it well.
- Behavior changes ship with tests. New client behavior: a unit test in
  `tests/`. Query wire-format changes: update
  `QueryBuilderConformanceTest.php` so the exact outgoing JSON keys stay
  covered. Daemon-dependent coverage: a test in `tests/live/` that skips
  cleanly when no server is available.
- The change keeps this repo a thin client over `mongreldb-server`. Don't
  re-implement storage, indexing, WAL, or SQL planning logic here.
- Documentation is updated alongside the code (`docs/`, `README.md`) if the
  change affects users.
- Commits have clear messages (see below).

## Coding standards

### PHP

- **Version.** PHP 8.4+. Don't drop the minimum casually.
- **Strictness.** `declare(strict_types=1);` in every file. Typed
  parameters, typed returns, four-space indentation.
- **Namespacing.** Classes live under the `Visorcraft\MongrelDB\` PSR-4
  namespace matching their path.
- **Classes.** Prefer `final` classes unless extension is intentional.
- **Naming.** `lowerCamelCase` for methods and variables. Test methods use
  descriptive `snake_case` with PHPUnit attributes such as `#[Test]`.
- **Transport.** Keep transport-specific behavior behind
  `TransportInterface`, and raise the existing typed exceptions instead of
  generic exceptions when mapping server or network errors.
- **Dependencies.** Prefer packages already in `composer.json`. New
  dependencies must be MIT or Apache-2.0 licensed.

### Commit messages

- Conventional Commit-style subjects: `fix(query): ...`, `test: ...`,
  `ci: ...`. Keep subjects concise and imperative.
- Subject line ≤ 72 characters, no trailing period.
- Body: wrap at 72 characters. Explain *why*, not *what* (the diff
  shows the what).
- Reference issues with `Fixes #123` / `Refs #123` on a final line
  when applicable.
- **Never** add AI/assistant attribution (no `Co-Authored-By`, no
  `Generated with`, no tool names).

## Issue reports

A useful bug report includes:

- The MongrelDB PHP client version (from `composer.json`).
- Your PHP version (`php --version`) and OS.
- The `mongreldb-server` version if the issue involves live requests.
- The exact code or commands that reproduce the issue.
- The expected result and the actual result.
- Any error output or stack trace.

Feature requests are welcome. Please describe the problem you're trying
to solve before proposing the solution.

## Security

If you find a vulnerability, **do not** open a public GitHub issue.
Report it privately through GitHub's private vulnerability reporting —
the repository's **Security** tab → **Report a vulnerability**. The full
policy is in [`SECURITY.md`](SECURITY.md).

## Licensing

The MongrelDB PHP client is dual-licensed under MIT OR Apache-2.0. By
contributing, you agree that your changes are made available under the
same license.

- Do **not** paste code from other database clients unless you have done
  a license review first.
- New third-party dependencies must be MIT or Apache-2.0 licensed.

Thanks again — looking forward to your PR.

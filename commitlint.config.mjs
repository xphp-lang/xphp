// Commit-message rules: Conventional Commits (https://www.conventionalcommits.org).
// One rule set, one tool, two entry points:
//   - locally:  .githooks/commit-msg runs commitlint through the docker compose
//               `node` service (installed by `composer install`)
//   - in CI:    .github/workflows/commitlint.yml runs the same lockfile-pinned
//               commitlint over every PR commit
//
// The stock preset already matches this repo's history:
//   type(scope): lowercase subject
// with types build/chore/ci/docs/feat/fix/perf/refactor/revert/style/test, an
// optional free-form scope (monomorphize, specializer, parser, cli, ...), a
// 100-char header cap, and merge/revert/fixup subjects ignored.
export default {
    extends: ['@commitlint/config-conventional'],
};

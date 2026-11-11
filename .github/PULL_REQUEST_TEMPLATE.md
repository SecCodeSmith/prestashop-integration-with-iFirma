## Summary

<!-- What changes for the shop, the customer or the iFirma API, and why. -->

## Related OpenSpec change

<!-- openspec/changes/<change-name> -->

## Definition of done

- [ ] Scenarios are written as Given / When / Then in the OpenSpec change, including the negative and edge cases
- [ ] A failing test was shown first, for each behaviour in this change (bug fixes start with a reproducing test)
- [ ] `composer test` is green
- [ ] Diff coverage is at least 95% of changed lines (`composer coverage` and the diff-cover gate); every line that can run under PHPUnit is covered
- [ ] `docs/` and the OpenSpec change (tasks, specs) are updated
- [ ] Smoke scenario run, if the change crosses the shop, API, mail or admin boundary
- [ ] No secrets, API keys, tokens, passwords or customer data in the diff, tests, fixtures or docs
- [ ] Security changes are in a separate `fix-vuln:` commit, not mixed into this feature or refactor
- [ ] `graphify update .` was run after the code change

## Test evidence

<!-- Paste the failing run (red) and the passing run (green), or the relevant excerpt. -->

# Agent guidelines

## Tests

- A test that expects an exception also checks its message: pair `expectException()` with `expectExceptionMessage()`, so the test fails when another exception of the same class is thrown.

## Pull requests

- The description is short. It starts with the problem, or with what a user can now do, not with the implementation.
- A longer one has `## Why` and `## Change` sections, and `## Deploy` when production needs something, with a short bullet per point.
- A pull request stacked on another one starts with "Stacked on #N."
- No list of the checks that ran (CI shows them) and no "Generated with Claude Code" line.

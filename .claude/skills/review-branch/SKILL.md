---
name: review-branch
description: Merge-gate review of the current branch against main — only the problems you would block the merge for, each with file:line, why it is wrong, and how to show it fails. Use before asking a human to review, or when asked "is this branch ready".
---

Review the diff on this branch against `main` (`git diff main...HEAD`, plus
`git log main..HEAD` for intent). List **only problems you would block the merge
for**. Style nits, naming taste and "could be simpler" are not findings.

For each finding give:

- **Where** — `file:line`.
- **Why it is wrong** — one or two sentences.
- **How to show it fails** — the input, test or screen that exposes it. A finding
  you cannot show failing is marked unconfirmed, with where you looked.

What counts as blocking here, beyond plain bugs:

- A breach of a **Hard rule** in `CLAUDE.md`.
- Re-opening a question listed under **Settled** in `CLAUDE.md` /
  `docs/internals/decisions.md` without new evidence.
- A test that would stay green if the fix were reverted — an assertion no
  mutation kills, an await on the flash instead of the effect, a negative
  assertion with no barrier. `docs/internals/testing.md` has the doctrine.
- A defect with an obvious second site the diff did not touch.
- An edge added or removed without the matching allowlist change (`composer arch`),
  or a constructor that gained a dependency while test construction sites did not.

You may read, grep and run the read-only gates (`composer analyze`, `lint`,
`arch`, `duplication`, `format:check`, `rector:check`), capturing output to a
file. **Never run a test suite** and never edit the tree — another session is
likely running one on this checkout.

End with one line: **merge** or **block (N findings)**.

---
description: Stage the right changes and create a git commit, using the commit-message-storyteller skill to write a Conventional Commits message that explains why.
---

Load the `commit-message-storyteller` skill (use the `skill` tool) and follow it to create a commit.

User input: $ARGUMENTS

Steps:
1. Run `git status` and `git diff --staged` to see what is already staged.
   - If nothing is staged, run `git diff` to inspect the working tree, then stage the relevant files with `git add <files>` before continuing.
   - If the user input above names files, a path, or a scope, restrict the commit to exactly that set of changes.
2. Using the skill, analyze the diff to determine what changed, why it changed, and what triggered it.
3. Draft the commit message in Conventional Commits format: an imperative subject (type + optional scope, ≤72 chars, no trailing period), a body explaining the *why* (not the *what*), and a footer for issue refs or `BREAKING CHANGE` notices.
4. Create the commit, passing each paragraph as its own `-m` flag (e.g. `git commit -m "<subject>" -m "<body>" -m "<footer>"`).
5. Run `git log -1 --stat` to confirm the commit landed, then show me the final message.

If the diff contains logically separate changes, stop before committing and ask whether to split them into multiple commits.

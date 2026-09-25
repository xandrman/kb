---
description: Find a skill for a given task and install it at project level.
---

Find and install a skill for the request: $ARGUMENTS

Steps:
1. Run `npx skills find <query>` using the user input as the search query.
2. Review the `npx skills find` results and pick the most relevant skill(s):
   - Prefer skills with higher install counts and reputable sources (official orgs like `qdrant/skills`, `vercel-labs`, `anthropics`).
   - If the leaderboard at skills.sh already covers the need, recommend that skill directly.
3. Install the chosen skill at project level (not global):
   ```
   npx skills add <owner/repo@skill> -y
   ```
   When the CLI asks about installation scope, choose **Project**.
4. After installation, confirm the install path (e.g. `.agents/skills/<name>`) and tell the user the skill is ready.

If no relevant skill is found, say so, offer to help with the task directly, and suggest `npx skills init` for a custom skill.
# ctnlist documentation

Project documentation lives here. Three kinds of file stay where their tools expect them: `README.md` (features, configuration, deployment, operations) and `CHANGELOG.md` (release history) at the top of the repository, and the `CLAUDE.md` files (working notes for Claude Code) at the top and beside the code they describe.

| File | What it is |
|---|---|
| [claude-project-instructions.md](claude-project-instructions.md) | Instructions for a Claude project (the Claude web or desktop app) about ctnlist. |
| [project-status.md](project-status.md) | Where the project stands: what is released, what is waiting for a decision, what to check at deployment. Dated; update it with each release. |
| [RESTORED-FUNCTIONALITY.md](RESTORED-FUNCTIONALITY.md) | The v5 workflows that are the behavioural baseline, and the deliberate changes from v5. |

## Setting up the Claude project

1. Create a project in the Claude app and paste the whole of `claude-project-instructions.md` into its instructions.
2. Add these files to the project's knowledge:
   - `docs/project-status.md`
   - `README.md`
   - `CHANGELOG.md`
   - `docs/RESTORED-FUNCTIONALITY.md`
   - `CLAUDE.md`, and the nested `src/CattoMail/CLAUDE.md`, `src/Config/CLAUDE.md`, `templates/CLAUDE.md` and `tests/CLAUDE.md`
   - `.env.example` and the templates in `deploy/`, for deployment planning
3. Five of those files are called `CLAUDE.md`. If they are uploaded by hand, rename the nested copies first (for example `CLAUDE-cattomail.md`, `CLAUDE-config.md`, `CLAUDE-templates.md`, `CLAUDE-tests.md`) so they can be told apart. Adding them from the GitHub repository, where the app offers that, keeps their paths.
4. The knowledge files are copies. After a release, or after `project-status.md` changes, replace them, or the project will advise from an older state than the code.

The instructions tell Claude that it cannot see the repository, that Claude Code makes all changes, and that anything it cannot confirm from the knowledge files should be checked by Claude Code and not guessed.

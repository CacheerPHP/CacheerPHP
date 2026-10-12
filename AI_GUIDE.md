# Using AI assistants with CacheerPHP

[AGENTS.md](AGENTS.md) is the shared project guide. It covers CacheerPHP 6.x's
architecture, public API, storage guarantees, refresh behavior, security, Monitor
integration, test commands, review rules, and the maintainer's working preferences.
Keep project facts and policies there rather than maintaining separate copies for
each model. The short tool entry files only load or point to that guide.

## Instruction entry points

| Assistant/tool | Repository entry point | Setup |
| --- | --- | --- |
| Codex | `AGENTS.md` | Start the session in this repository so its project instructions are discovered. |
| Claude Code | `CLAUDE.md` → `@AGENTS.md` | The local import loads the shared guide. |
| Gemini CLI | `GEMINI.md` → `@./AGENTS.md` | The local import loads the shared guide. |
| GitHub Copilot | `.github/copilot-instructions.md`, plus `AGENTS.md` where supported | Keep custom instructions enabled; support differs between Copilot features/IDEs. |
| Cursor Agent | `AGENTS.md` | Use the repository root; no duplicate `.cursorrules` file is needed. |
| Windsurf/Cascade | `AGENTS.md` | Root agent instructions provide project guidance. |
| Aider, Continue, Cline, Roo Code, chat assistants, or another model/tool | Explicitly supply `AGENTS.md` | Add it to the tool's context or ask the assistant to read it before working. |

The coding tool controls instruction loading. A model name alone does not ensure
files are read. For a tool without filesystem access, attach/paste the guide,
relevant source/tests, and your task. Never include `.env`, credentials, or real
cache contents merely to provide context.

For GPT, Claude, Gemini, DeepSeek, Qwen, Grok, or other models used in a chat or
custom agent, use the explicit-context workflow when the host does not discover
repository instructions.

## Starting a task

Use this prompt when instructions are not loaded automatically:

> Read the repository's AGENTS.md in full, then inspect the current branch,
> working-tree changes, and source/tests relevant to this task. Work on CacheerPHP
> 6.x, preserve unrelated edits, and make the smallest complete change that solves
> the problem. Follow the documented verification commands. Do not add AI author
> notes or AI co-author trailers. Task: [describe the change and expected behavior].

Before editing, ask the assistant to identify the loaded instruction files,
target branch, and relevant verification. If the tool truncates context, have it
read the complete guide through file tools. After changing instruction files,
start a fresh session or use the tool's supported context reload mechanism.
Check imports/references rather than assuming a Markdown link expands automatically.

## Maintaining these instructions

- Update `AGENTS.md` when public behavior, repository structure, or checks change.
- Keep `CLAUDE.md`, `GEMINI.md`, and Copilot's entry point consistent with it.
- Preserve tool-specific import syntax; do not replace it with a code-fenced path.
- Verify referenced paths and Composer commands, examples, and `git diff --check`.
- Read Monitor's own instructions when a task crosses into that repository.
- Treat these files as project context, not a replacement for source inspection,
  explicit task scope, tool permissions, or human review.

## Official instruction-file documentation

Loading conventions were checked against the tool providers' documentation:
[Codex](https://learn.chatgpt.com/docs/agent-configuration/agents-md),
[Claude Code](https://code.claude.com/docs/en/memory),
[Gemini CLI](https://geminicli.com/docs/cli/gemini-md/),
[GitHub Copilot](https://docs.github.com/en/copilot/reference/custom-instructions-support),
[Cursor](https://cursor.com/docs/rules), and
[Windsurf/Cascade](https://docs.windsurf.com/windsurf/cascade/memories).

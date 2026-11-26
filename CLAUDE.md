# CLAUDE.md

@AGENTS.md

## Claude Code specifics

- The project guide above (`AGENTS.md`) and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) are the
  source of truth. Read `docs/ARCHITECTURE.md` before changing a flow you have not touched yet.
- A **Stop hook** (`.claude/settings.json`) runs `scripts/check-agent-docs.sh`. If it blocks you, update
  `AGENTS.md` and/or `docs/ARCHITECTURE.md` to describe what you just added, removed or renamed,
  then finish. Do not work around it.
- The containers have no bind mount: after editing PHP code run `make restart` (or
  `docker compose up -d --build app worker scheduler reverb`) before testing against the running stack,
  and `make test` for the suite.
- Use `make up-mock` for anything that sends more than a few notifications; webhook.site's free URL
  allows about 50 requests in total.

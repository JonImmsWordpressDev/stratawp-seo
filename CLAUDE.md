# CLAUDE.md

Guidance for AI agents working in this repository.

## Attribution — REQUIRED

All commits, pull requests, and issue comments are authored by the repo
owner, with no AI attribution of any kind:

- Commit author AND committer: `Jon Imms <60996163+JonImmsWordpressDev@users.noreply.github.com>`
- NEVER add `Co-Authored-By: Claude ...` or any other co-author trailer.
- NEVER add `Claude-Session: ...` trailers or session links.
- NEVER add "Generated with Claude Code" (or similar) footers/badges to
  commit messages, PR titles, PR bodies, or comments.

## Releases

- The version lives in three places and must stay in sync: the
  `Version:` header and `SWPS_VERSION` define in `stratawp-seo.php`,
  and `Stable tag:` in `readme.txt` (plus a changelog entry).
- Merging a version bump to `main` triggers `.github/workflows/release.yml`,
  which builds the plugin zip and publishes a GitHub release automatically.

# Changelog

All notable changes to this project are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-07

Initial public release.

- Backend (admin panel) page: enter a forum `fid`, paste a `{A}`–`{K}` / `{N}` placeholder template, preview,
  and download the whole forum's thread list as a UTF-8 `.txt` (newest first).
- Filters: single forum (no subforums), normally-displayed threads only, optional recycle-bin, optional
  posting-date range. Redirect/sticky/pending/draft/ignored threads are excluded.
- Output options: UTF-8 BOM (optional), CRLF/LF line endings, optional blank line between records.
- Large forums stream in keyset-paginated batches; only one batch is held in memory at any time.
- Read-only: `SELECT`-only, no data tables, no changes to forum data, no front-end hooks.
- Permissions: founder, plus an optional comma-separated `allow_uids` list.

[1.0.0]: https://github.com/Carinoasd/discuz-threadexport/releases/tag/v1.0.0

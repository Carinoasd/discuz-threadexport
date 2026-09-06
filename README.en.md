# threadexport — Discuz! Forum Thread-List TXT Exporter

> [繁體中文](README.md) | English

A **read-only** admin tool for Discuz! X5.0. In the admin panel you enter a forum `fid` and paste a
*template*; the plugin renders **every thread** of that forum (newest first) through the template — one
thread per record — into a single UTF-8 plain-text file for download.

Think of it as **mail merge**: the template is the letterhead, the thread list is the mailing list. When you
want the whole forum's titles / links / authors as one list (for an announcement, an index, or a spreadsheet),
you set the conditions once and export — no page-by-page copy-paste.

- Platform: Discuz! X5.0 (X5.0.x)
- License: GPL-2.0
- Author: Carinoasd

![Admin page](docs/backend.png)

Above: the admin export page (enter fid → paste template → preview → download). Below: the exported `.txt`
opened in Windows Notepad — Chinese renders correctly, line breaks correct, with a UTF-8 BOM.

![Export opened in Notepad](docs/notepad.png)

---

## What it does

- One admin page: enter `fid` → paste template → choose filters → preview → download `.txt`.
- Template uses `{UPPERCASE-LETTER}` placeholders; multi-line, may embed any text and BBCode.
- Filters: a single forum, only normally-displayed threads, optional "include recycle bin", optional
  posting-date range.
- Order: posting time, newest first.
- Output: UTF-8 plain text; optional BOM and CRLF/LF line endings.
- Large forums stream in batches; only one batch is held in memory at any moment (very low peak).
- **Read-only**: the whole plugin only runs `SELECT` — no data tables, no changes to forum data, no
  front-end hooks.

---

## Install

1. Upload the `threadexport/` folder into `source/plugin/` (so it becomes `source/plugin/threadexport/`).
2. Admin → Apps → Plugins → find "主題列表導出" → Install → Enable. Install only writes rows to
   `pre_common_plugin` and `pre_common_pluginvar`; **no data tables are created**.
3. Entry point: Admin → Apps → Plugins → 主題列表導出 → (tab) 主題列表導出.

> The import JSON contains only standard Discuz keys and **no non-standard keys such as `author`**, so it
> imports cleanly on both fresh installs and upgraded sites.

---

## Permissions

Entry is already behind the admin panel. The plugin adds one more check — **either** must hold:

1. You are a **founder** (`$_config['admincp']['founder']` in `config_global.php`); **or**
2. Your uid is in the plugin setting **`allow_uids`** (comma-separated).

Default `allow_uids` is empty → founders only. Users who fail both see the standard Discuz "no permission"
message; **no page is rendered and no query runs**.

Other settings: `batch_size` (500–5000, default 2000), `default_bom`, `default_crlf`.

---

## Template syntax

Use `{UPPERCASE-LETTER}` placeholders; everything else (including BBCode, `[`, `]`) is emitted verbatim.

| PH | Meaning | | PH | Meaning |
|----|---------|-|----|---------|
| `{A}` | thread subject | | `{G}` | views |
| `{B}` | thread tid | | `{H}` | thread class name (empty if none) |
| `{C}` | author name | | `{I}` | full thread URL |
| `{D}` | author uid | | `{J}` | last-reply time |
| `{E}` | post time (`YYYY-MM-DD HH:MM`) | | `{K}` | forum name |
| `{F}` | replies | | `{N}` | running number (from 1, in output order) |

Rules:

- Only `{single uppercase letter}` is recognized; undefined ones (`{Z}`, `{a}`, `{標題}`) stay verbatim.
- Case-sensitive; a placeholder may repeat.
- Multi-line templates emit that many lines per thread; "blank line between records" adds one more.
- HTML entities in subject/author are decoded (`&amp;`→`&`, `&lt;`→`<`, `&gt;`→`>`, `&quot;`→`"`); the
  single quote `'` is never entity-encoded in the first place.
- Time is fixed `YYYY-MM-DD HH:MM` (site timezone); template length ≤ 2000 chars.

### Three complete examples

Suppose the forum contains (newest first): `星空下的旅人` (tid 12345, author 林小雨), `夏日的第一場雨`
(tid 12000, author 陳默).

Template `{A}`:
```
星空下的旅人
夏日的第一場雨
```

Template `{B} {A}`:
```
12345 星空下的旅人
12000 夏日的第一場雨
```

Template `[URL=tid={B}]{A}[/URL]` (`[B]…[/B]`-style tags are safe — the letters inside carry no braces):
```
[URL=tid=12345]星空下的旅人[/URL]
[URL=tid=12000]夏日的第一場雨[/URL]
```

Multi-line `{A}` / `{B}` / `{C}` with "blank line between records":
```
星空下的旅人
12345
林小雨

夏日的第一場雨
12000
陳默
```

`{I}` emits the full thread URL, e.g. `https://example.com/forum.php?mod=viewthread&tid=12345`.

---

## Output options

- **UTF-8 BOM** (on by default): Windows Notepad / Excel open it without mojibake.
- **Line endings**: Windows (CRLF, default) or Linux/Mac (LF).
- **Blank line between records** (off by default): nicer for multi-line templates.
- Filename `threadexport_fid{fid}_{YYYYMMDD-HHMM}.txt` (all ASCII).
- Zero matches still download a file (BOM-only or empty); preview shows "共 0 筆" first.

---

## Which threads are exported

This single `fid` only (no subforums); normally-displayed (plus recycle bin if opted in); not move-redirect
stubs; posting time within range; ordered newest first. Sticky / pending / draft / ignored / redirect threads
are never exported.

---

## Known limitations

1. **No GBK sites**: output is emitted in the site's native encoding assuming UTF-8; GBK sites will produce
   mojibake (no transcoding is done).
2. No subforums, sticky, pending, draft, or ignored threads (unstick first if you must export a sticky).
3. **No escape syntax**: you cannot output a literal `{A}`.
4. Fixed time format `YYYY-MM-DD HH:MM`.
5. **IIS buffering**: on IIS, IIS/FastCGI may buffer the whole response or time out on very large forums
   (hundreds of thousands of threads) — export in date ranges. The plugin holds only one batch in memory, so
   it is only a matter of waiting.
6. **Anonymous posts leak the real name**: `author` stores the real username (Discuz masks only at display
   time), so the export shows the real name — mind this before sharing the file.
7. Subjects containing BBCode / `[` `]` are kept verbatim and may be parsed if pasted back into a post.

---

## Three ways out (how to remove it)

The plugin is read-only, creates no data tables, and changes no forum data:

1. **Disable**: Admin → Apps → Plugins → 主題列表導出 → Disable.
2. **Delete the folder**: remove `source/plugin/threadexport/`.
3. **Delete one DB row** (it has no own tables):
   ```sql
   DELETE v FROM pre_common_pluginvar v
     JOIN pre_common_plugin p ON p.pluginid = v.pluginid
    WHERE p.identifier = 'threadexport';
   DELETE FROM pre_common_plugin WHERE identifier = 'threadexport';
   ```
   Then rebuild caches (Admin → Tools → Update cache).

---

## Download

- Source: <https://github.com/Carinoasd/discuz-threadexport>
- Package: [Releases](https://github.com/Carinoasd/discuz-threadexport/releases) →
  [v1.0.0](https://github.com/Carinoasd/discuz-threadexport/releases/tag/v1.0.0) →
  [`threadexport.zip`](https://github.com/Carinoasd/discuz-threadexport/releases/download/v1.0.0/threadexport.zip)

## License

[GPL-2.0](LICENSE) © 2026 Carinoasd

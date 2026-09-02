# Rakuel Is Back – Card Gallery

Browser-based card viewer for the Rakuel Is Back card game. Fetches card and keyword data from the API and renders them with filtering, sorting, and bulk download.

## Views

| Button | Description |
|---|---|
| Card Gallery | Full card images in a grid; click an image to select it for bulk download |
| Card Art | Art-only crop of each card |
| Data Form | Tabular list of all card data; exportable as CSV |
| Keywords | All keywords with descriptions, usage counts, and which types carry them |
| Macros | Statistical breakdown of cards by type, power, and keyword count |
| Rules | Inline rules reference |

## Filters

| Filter | Behaviour |
|---|---|
| Name | Substring match on card name |
| Keywords | Space/comma-separated tokens; card must contain all of them |
| Type | Rock / Paper / Scissors / Gun |
| Power | Exact power value (or threshold when combined with Power Is) |
| Power Is | Comparison operator for Power — only shown when Power is set |
| Keyword Count | Vanilla (0), 1, 2, or 3 keywords |
| Keyword | Single keyword the card must have |
| Expansion | Set filter; only expansions with status `released` or `hidden` appear here |
| Release Year | Cards released in the selected year |
| Order By / Direction | Sort order |

All active filters are persisted in the URL query string so sharing or refreshing a URL keeps the same view.

## Expansion sets

Cards are grouped into sets of 60 by ID: IDs 1–60 are Set 1, 61–120 are Set 2, and so on.

Visibility of each set is controlled by the `.expansions` file in this directory.

### `.expansions` format

One entry per line: `set<N>=<status>`

```
set1=released
set2=hidden
set3=null
set4=null
```

### Status values

| Status | Alias | Expansion in filter? | Cards shown by default? | Cards shown when selected? |
|---|---|---|---|---|
| `released` | `2` | Yes | Yes | Yes |
| `hidden` | `1` | Yes | No | Yes |
| `null` | `0` | No | No | No |

- **released** — the set is live. Cards appear in all views with no filter needed.
- **hidden** — the set exists but is not yet public. It appears in the Expansion dropdown so staff can preview it by selecting it explicitly, but its cards are invisible in the default unfiltered view.
- **null** (or a set not listed in the file at all) — the set does not exist from the user's perspective. Not in the dropdown, cards never shown.

If the `.expansions` file is missing entirely, all sets are treated as `released` so the gallery continues to work without configuration.

### Controlling releases across servers

Edit `.expansions` on each server independently. To soft-launch a set on staging but keep it hidden on production, set `set2=released` on staging and `set2=hidden` (or `set2=null`) on production. No code changes needed.

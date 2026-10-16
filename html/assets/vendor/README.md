# Vendored front-end libraries

Committed as built; no Node on a server (writing.md decision 1). Each library keeps its own `LICENSE` beside it. **Squire 2 needs DOMPurify
as a global** (it cleans pasted and inserted HTML with it), so the composer loads `purify.min.js` first — the same release whose test suite
proves the mail service's sanitiser (`tests/sanitiser/corpus/dompurify/`). The browser's cleaning is a convenience; what is sent is
cleaned again by the mail service.

| Library | Version | File | Source | sha256 |
|---|---|---|---|---|
| Squire (Fastmail's rich-text editor, MIT) | 2.4.9 | `squire-2.4.9/squire.js` | `https://cdn.jsdelivr.net/npm/squire-rte@2.4.9/dist/squire.js` (2026-10-15) | `2fc7ce78b2e3a73bc81d3d143725fea4e424a778434f5982d53db233599ab981` |
| DOMPurify (Cure53's HTML sanitiser, MPL-2.0 OR Apache-2.0 — taken under Apache-2.0) | 3.4.16 | `dompurify-3.4.16/purify.min.js` | `https://cdn.jsdelivr.net/npm/dompurify@3.4.16/dist/purify.min.js` (2026-10-16) | `2c90a9b46d6463f26038a29b686e82bc91de01fdac9d5229e7cfe3b360134ea2` |

To update: fetch the released `dist/squire.js` of a version at least two weeks old, record its sha256 here, and re-run
`tests/phase3/slice4/run.sh` (the composer's browser proof).

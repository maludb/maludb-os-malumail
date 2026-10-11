# DOMPurify's test cases — where they come from

`expect.mjs` and `LICENSE` are copied **unchanged** from DOMPurify, the HTML sanitiser maintained by Cure53:

| | |
|---|---|
| Repository | https://github.com/cure53/DOMPurify |
| Release | **3.4.16** (published 2026-09-23) — tag object `7858197e2a666b21fd482773fd0157fff059a537`, commit `b9b9d80f7e401771c2ccaef5f45def7eec8f27d7` |
| File | `test/fixtures/expect.mjs` — sha256 `1c8f8dad1874fcd375665070a8e2be8e011b63a6d6b2ce27ab08585e324eef75`, 223 cases |
| Licence | DOMPurify is offered under **MPL-2.0 OR Apache-2.0**; MaluMail takes it under **Apache-2.0**, whose text is `LICENSE` here (sha256 `cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30`). DOMPurify ships no NOTICE file. |
| Chosen by | the owner, 2026-10-11 ("Yes" — a published sanitiser test suite as the attack corpus, research 04 Part A) |

**How it is used.** `tests/sanitiser/read_dompurify.py` reads the file as data — it is never executed: the leading
`export default`, the closing `;` and the trailing commas are dropped outside strings, and the rest is JSON. Each case's `payload`
goes through MaluMail's sanitiser (`services/mailer/sanitise.py`). DOMPurify's own `expected` output is not compared: it follows
DOMPurify's default allow-list (SVG, MathML, forms), and MaluMail's is narrower by design. What is checked is what MaluMail
promises (design §6.4): nothing executable, nothing that loads, nothing that re-parses differently — statically and in headless
Chromium (`tests/sanitiser/run.sh`).

**Updating.** Replace both files from a newer release at least two weeks old, update the table above, and re-run the proof.

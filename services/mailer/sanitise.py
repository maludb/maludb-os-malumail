"""
A message's HTML cleaned by allow-list — the first of design §6.4's three layers ("Reading safely"). The second (the body on its
own response under a Content-Security-Policy, framed in an opaque-origin sandbox) and the third (the image door) are the reading
slice's; this module never fetches anything.

Three passes over one message:

1. **nh3** (Rust's ammonia over an html5ever parse) with the allow-lists below. `_attribute` decides every attribute: URLs by the
   attribute they sit in (a link: http, https, mailto; an image: cid, http, https, or a raster data URI), CSS by property AND
   value (nh3's own style filter checks property names only — `background:url(…)` passes it), and an element whose style or
   `hidden` attribute hides it is marked `style="display:none"`. Relative URLs are refused: a message has no base.
2. **Ours**, over nh3's serialised output (well-formed, escaped, HTML elements only): marked elements are removed with their
   content and counted; images are rewritten — `cid:` to the part door, a remote image to whatever the caller says (the
   placeholder, or the image door once a person asked), a tracking pixel dropped; links open in a new tab.
3. **nh3 again**, with the same allow-lists, admitting as an image source only the URLs pass 2 wrote, repeated until its output
   is a fixed point (at most four times; an unstable message falls back to its text). What leaves is always nh3's own output
   and re-parses to itself — the parser-differential (mXSS) rule.

No `<style>` element survives (its content is removed with it), so a newsletter's stylesheet is lost and its inline styles kept;
nor does `svg`, `math`, `noscript` or `template`, content and all. Proven by `tests/sanitiser/run.sh`: DOMPurify 3.4.16's 223
cases and a benign corpus, statically and in headless Chromium.
"""

from __future__ import annotations

import html as _html
import re
from dataclasses import dataclass, field
from html.parser import HTMLParser
from typing import Callable, Optional
from urllib.parse import unquote

import nh3

# ---- the allow-lists ------------------------------------------------------------------------------------------------------------

TAGS = frozenset({
    "a", "abbr", "address", "b", "bdi", "bdo", "big", "blockquote", "br", "caption", "center", "cite", "code", "col", "colgroup",
    "dd", "del", "dfn", "div", "dl", "dt", "em", "figcaption", "figure", "font", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i",
    "img", "ins", "kbd", "li", "mark", "ol", "p", "pre", "q", "rp", "rt", "ruby", "s", "samp", "small", "span", "strike",
    "strong", "sub", "sup", "table", "tbody", "td", "tfoot", "th", "thead", "time", "tr", "tt", "u", "ul", "var", "wbr",
})
VOID = frozenset({"br", "col", "hr", "img", "wbr"})
# Removed with everything inside them. Disjoint from TAGS (nh3 refuses an overlap).
CLEAN_CONTENT = frozenset({
    "script", "style", "title", "head", "noscript", "template", "iframe", "frame", "frameset", "object", "embed", "applet",
    "svg", "math", "xmp", "noembed", "noframes", "textarea", "select",
})
GENERIC_ATTRIBUTES = frozenset({"style", "title", "dir", "lang", "align", "valign", "width", "height", "bgcolor", "hidden"})
ATTRIBUTES = {
    "a": {"href"},
    "img": {"src", "alt", "border", "hspace", "vspace"},
    "table": {"border", "cellpadding", "cellspacing", "summary"},
    "td": {"colspan", "rowspan", "nowrap", "scope", "abbr"},
    "th": {"colspan", "rowspan", "nowrap", "scope", "abbr"},
    "col": {"span"},
    "colgroup": {"span"},
    "font": {"color", "face", "size"},
    "ol": {"start", "type", "reversed"},
    "ul": {"type"},
    "li": {"value", "type"},
    "hr": {"noshade", "size", "color"},
    "time": {"datetime"},
    "del": {"datetime"},
    "ins": {"datetime"},
}
LINK_SCHEMES = frozenset({"http", "https", "mailto"})
# CSS properties kept inside a style attribute, each value checked by _css_value_ok. Nothing that positions, loads, animates or
# generates content: no position/top/left/z-index, background-image, list-style-image, border-image, content, cursor, filter,
# transform, behavior, -moz-binding.
CSS_PROPERTIES = frozenset({
    "color", "background", "background-color",
    "font", "font-family", "font-size", "font-style", "font-weight", "font-variant", "line-height", "letter-spacing",
    "word-spacing", "text-align", "text-decoration", "text-decoration-line", "text-decoration-color", "text-decoration-style",
    "text-transform", "text-indent", "text-overflow", "vertical-align", "white-space", "word-break", "word-wrap", "overflow-wrap",
    "direction",
    "margin", "margin-top", "margin-right", "margin-bottom", "margin-left",
    "padding", "padding-top", "padding-right", "padding-bottom", "padding-left",
    "border", "border-top", "border-right", "border-bottom", "border-left", "border-width", "border-style", "border-color",
    "border-top-width", "border-right-width", "border-bottom-width", "border-left-width",
    "border-top-style", "border-right-style", "border-bottom-style", "border-left-style",
    "border-top-color", "border-right-color", "border-bottom-color", "border-left-color",
    "border-radius", "border-collapse", "border-spacing",
    "width", "height", "min-width", "max-width", "min-height", "max-height",
    "display", "visibility", "opacity", "overflow", "overflow-x", "overflow-y", "float", "clear",
    "list-style", "list-style-type", "list-style-position", "table-layout", "caption-side", "empty-cells",
})

# The marker pass 1 puts on an element that hides itself; pass 2 removes any element whose style says display:none.
_HIDDEN = "display:none"
_DATA_IMAGE = re.compile(r"data:image/(?:png|gif|jpeg|jpg|webp)(?:;[a-z0-9=.\-]+)*;base64,[A-Za-z0-9+/=]+", re.I)
_DATA_IMAGE_MAX = 2_000_000
_SCHEME = re.compile(r"([a-zA-Z][a-zA-Z0-9+.\-]*):")
_URL_NOISE = re.compile(r"[\x00-\x1f\x7f]")             # browsers drop tabs and newlines anywhere in a URL; refuse the rest
_CSS_REFUSED = re.compile(r"[\\<>@{}`&\x00-\x1f\x7f]|/\*|\*/", re.S)
_CSS_FUNCTION = re.compile(r"([a-zA-Z\-]+)\(([^()]*)\)")
_CSS_FUNCTIONS = frozenset({"rgb", "rgba", "hsl", "hsla"})
_CSS_PLAIN = re.compile(r"[a-zA-Z0-9#%.,\s'\"!+\-/]*")
_CSS_NUMBER = re.compile(r"([+-]?\d*\.?\d+)\s*([a-z%]*)")
_PLAIN_VALUE = re.compile(r"[\w\s#%.,'\"\-+]{0,200}")
_DIMENSION = re.compile(r"\d{1,5}(?:\.\d+)?%?")


def _url(value: str) -> str:
    return _URL_NOISE.sub("", value).strip(" ")


def _scheme(url: str) -> Optional[str]:
    m = _SCHEME.match(url)
    return m.group(1).lower() if m else None


def _link_ok(url: str) -> bool:
    return url.startswith("#") or _scheme(url) in LINK_SCHEMES


def _declarations(style: str) -> Optional[list[tuple[str, str]]]:
    """The style attribute as (property, value) pairs, or None when any of it is refused outright."""
    if _CSS_REFUSED.search(style):
        return None
    out = []
    for part in style.split(";"):
        if not part.strip():
            continue
        prop, sep, value = part.partition(":")
        prop = prop.strip().lower()
        if not sep or not re.fullmatch(r"-?[a-z][a-z0-9\-]*", prop):
            continue
        out.append((prop, value.strip()))
    return out


def _css_value_ok(value: str) -> bool:
    if len(value) > 300 or value.count("'") % 2 or value.count('"') % 2:
        return False
    for m in _CSS_FUNCTION.finditer(value):
        if m.group(1).lower() not in _CSS_FUNCTIONS or not re.fullmatch(r"[0-9.,%\s/+\-]*", m.group(2)):
            return False
    lowered = value.lower()
    if any(word in lowered for word in ("expression", "javascript", "vbscript", "url", "image")):
        return False
    return _CSS_PLAIN.fullmatch(_CSS_FUNCTION.sub("0", value)) is not None


def _number(value: Optional[str]) -> Optional[tuple[float, str]]:
    if value is None:
        return None
    m = _CSS_NUMBER.fullmatch(value.strip())
    return (float(m.group(1)), m.group(2)) if m else None


def _hides(decls: list[tuple[str, str]]) -> bool:
    d = {p: v.lower().replace("!important", "").strip() for p, v in decls}
    if d.get("display") == "none" or d.get("visibility") in ("hidden", "collapse") or d.get("mso-hide") == "all":
        return True
    op = _number(d.get("opacity"))
    if op is not None and (op[0] < 10 if op[1] == "%" else op[0] < 0.1):
        return True
    fs = _number(d.get("font-size"))
    if fs is not None and (fs[0] == 0 or (fs[1] in ("px", "pt", "") and fs[0] < 2)):
        return True
    clipped = "hidden" in (d.get("overflow"), d.get("overflow-x"), d.get("overflow-y"))
    for dim in ("height", "max-height", "width", "max-width", "line-height"):
        n = _number(d.get(dim))
        if clipped and n is not None and n[0] == 0:
            return True
    color, background = d.get("color"), d.get("background-color") or d.get("background")
    keywords = ("inherit", "initial", "unset", "transparent", "currentcolor")
    return color is not None and color == background and color not in keywords


def _style(style: str) -> Optional[str]:
    decls = _declarations(style)
    if decls is None:
        return None
    if _hides(decls):
        return _HIDDEN
    kept = [f"{p}:{v}" for p, v in decls if p in CSS_PROPERTIES and _css_value_ok(v)]
    return ";".join(kept) or None


def _plain(attr: str, value: str) -> Optional[str]:
    if attr in ("title", "alt", "summary", "abbr"):
        return value[:1000]
    if attr in ("width", "height", "hspace", "vspace", "border", "cellpadding", "cellspacing", "colspan", "rowspan", "span",
                "start", "value", "size"):
        return value if _DIMENSION.fullmatch(value.strip()) else None
    if attr == "dir":
        return value if value.lower() in ("ltr", "rtl", "auto") else None
    if attr == "datetime":
        return value if re.fullmatch(r"[0-9TZ:+\-. ]{1,40}", value) else None
    return value if _PLAIN_VALUE.fullmatch(value) else None


def _attribute_filter(image_ok: Callable[[str], Optional[str]]) -> Callable[[str, str, str], Optional[str]]:
    def decide(tag: str, attr: str, value: str) -> Optional[str]:
        if attr.startswith("on"):
            return None
        if attr == "style":
            return _style(value)
        if attr == "hidden":
            return "hidden"
        if attr == "href":
            url = _url(value)
            return url if _link_ok(url) else None
        if attr == "src":
            return image_ok(_url(value)) if tag == "img" else None
        return _plain(attr, value)
    return decide


def _incoming_image(url: str) -> Optional[str]:
    """Pass 1: an image source as the message gave it — rewritten (or dropped) by pass 2."""
    scheme = _scheme(url)
    if scheme in ("cid", "http", "https"):
        return url
    if scheme == "data" and len(url) <= _DATA_IMAGE_MAX and _DATA_IMAGE.fullmatch(url):
        return url
    return None


def _cleaner(attribute_filter, url_schemes: set[str], url_relative) -> nh3.Cleaner:
    return nh3.Cleaner(
        tags=set(TAGS), clean_content_tags=set(CLEAN_CONTENT),
        attributes={"*": set(GENERIC_ATTRIBUTES), **{t: set(a) for t, a in ATTRIBUTES.items()}},
        attribute_filter=attribute_filter, strip_comments=True, link_rel="noopener noreferrer",
        set_tag_attribute_values={"a": {"target": "_blank"}}, url_schemes=url_schemes, url_relative=url_relative,
        filter_style_properties=set(CSS_PROPERTIES),
    )


_FIRST = _cleaner(_attribute_filter(_incoming_image), {"http", "https", "mailto", "cid", "data"}, "deny")


def final_pass(html: str, image_urls: set[str]) -> str:
    """Pass 3, once: the same allow-lists, admitting as an image source only `image_urls` (what pass 2 wrote) or a raster data
    URI. Public so the proof can show a result is its fixed point."""
    def image(url: str) -> Optional[str]:
        if url in image_urls:
            return url
        return url if _scheme(url) == "data" and len(url) <= _DATA_IMAGE_MAX and _DATA_IMAGE.fullmatch(url) else None
    return _cleaner(_attribute_filter(image), {"http", "https", "mailto", "data"}, "pass_through").clean(html)


# ---- pass 2 ---------------------------------------------------------------------------------------------------------------------

@dataclass
class Sanitised:
    html: str
    hidden_removed: int = 0                                  # elements removed because they hid themselves (with their content)
    remote_images: list[str] = field(default_factory=list)   # the remote images' own URLs, in order — fetched only on request
    pixels_dropped: int = 0                                  # remote images of 2 px or less, dropped
    cid_refs: list[str] = field(default_factory=list)        # the Content-IDs the body shows
    image_urls: set[str] = field(default_factory=set)        # the image sources pass 2 wrote (the part door, placeholders)
    unstable: bool = False                                   # pass 3 never settled: the text alone was kept


def _dimension(attrs: dict, decls: list[tuple[str, str]], name: str) -> Optional[float]:
    for p, v in decls:
        if p == name:
            n = _number(v)
            if n is not None and n[1] in ("px", ""):
                return n[0]
    raw = attrs.get(name)
    n = _number(raw) if raw else None
    return n[0] if n is not None and n[1] in ("px", "") else None


class _Pass2(HTMLParser):
    def __init__(self, cid_url, remote_image_url, result: Sanitised):
        super().__init__(convert_charrefs=True)
        self.cid_url, self.remote_image_url, self.r = cid_url, remote_image_url, result
        self.out: list[str] = []
        self.skip = 0

    def _emit(self, tag: str, attrs: dict) -> None:
        parts = [f"<{tag}"]
        for k, v in attrs.items():
            parts.append(f" {k}" if v is None else f' {k}="{_html.escape(v, quote=True)}"')
        self.out.append("".join(parts) + ">")

    def _image(self, attrs: dict) -> Optional[dict]:
        src = attrs.get("src") or ""
        scheme = _scheme(src)
        if scheme == "cid":
            cid = unquote(src[4:])
            url = self.cid_url(cid)
            if url is None:
                return None
            self.r.cid_refs.append(cid)
        elif scheme in ("http", "https"):
            decls = _declarations(attrs.get("style") or "") or []
            w, h = _dimension(attrs, decls, "width"), _dimension(attrs, decls, "height")
            if (w is not None and w <= 2) or (h is not None and h <= 2):
                self.r.pixels_dropped += 1
                return None
            self.r.remote_images.append(src)
            url = self.remote_image_url(src)
            if url is None:
                return None
        elif scheme == "data":
            return attrs
        else:
            return None
        self.r.image_urls.add(url)
        return {**attrs, "src": url}

    def handle_starttag(self, tag, attrs):
        void = tag in VOID
        if self.skip:
            self.skip += 0 if void else 1
            return
        a = dict(attrs)
        style = (a.get("style") or "").replace(" ", "").lower()
        if "hidden" in a or "display:none" in style.split(";"):
            self.r.hidden_removed += 1
            self.skip = 0 if void else 1
            return
        if tag == "img":
            a = self._image(a)
            if a is None:
                return
        self._emit(tag, a)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        if self.skip:
            self.skip -= 1
            return
        self.out.append(f"</{tag}>")

    def handle_data(self, data):
        if not self.skip:
            self.out.append(_html.escape(data, quote=False))


def sanitise(html: str, *, cid_url: Callable[[str], Optional[str]] = lambda cid: None,
             remote_image_url: Callable[[str], Optional[str]] = lambda url: None) -> Sanitised:
    """Clean one message's HTML. `cid_url(content_id)` names the part door's URL for an inline part (None drops the image);
    `remote_image_url(url)` what a remote image shows — the placeholder, or the image door once a person asked (None drops it)."""
    result = Sanitised(html="")
    p2 = _Pass2(cid_url, remote_image_url, result)
    p2.feed(_FIRST.clean(html))
    p2.close()
    out = "".join(p2.out)
    for _ in range(4):
        again = final_pass(out, result.image_urls)
        if again == out:
            result.html = out
            return result
        out = again
    result.unstable = True
    result.html = nh3.clean(out, tags=set())                 # the text alone, escaped by nh3
    return result

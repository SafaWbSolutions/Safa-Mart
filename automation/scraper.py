#!/usr/bin/env python3
"""Safa Mart bulk product sync.

Scrapes configured EarnKaro-supported category or feed pages, converts affiliate
links, uploads images to the Hostinger WebP endpoint, upserts products into
Supabase, and verifies existing active products for broken links and stock.

Run locally:
    python automation/scraper.py --dry-run --max-products 10
"""

from __future__ import annotations

import argparse
import html as html_lib
import ipaddress
import json
import logging
import os
import random
import re
import socket
import sys
import time
import unicodedata
from dataclasses import dataclass
from datetime import datetime, timezone
from io import BytesIO
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any, Callable, Iterable, Optional
from urllib.parse import parse_qsl, quote_plus, urlencode, urljoin, urlsplit, urlunsplit

import requests
from bs4 import BeautifulSoup
from PIL import Image, ImageOps, UnidentifiedImageError
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

ROOT = Path(__file__).resolve().parent
LOG_FILE = ROOT / "sync.log"

DEFAULT_FEEDS = [
    "https://www.flipkart.com/women-clothing/pr?sid=clo,ash",
    "https://www.flipkart.com/mens-clothing/pr?sid=clo,qvp",
    "https://www.flipkart.com/mobile-accessories/pr?sid=tyy,4mr",
]

USER_AGENT = os.getenv(
    "SCRAPER_USER_AGENT",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
).strip()

PLATFORM_DOMAINS = {
    "flipkart.com": "Flipkart",
    "shopsy.in": "Shopsy",
    "digihaat.in": "DigiHaat",
    "fkrt.it": "Flipkart",
    "fkrt.co": "Flipkart",
    "myntra.com": "Myntra",
    "myntr.it": "Myntra",
    "ajio.com": "Ajio",
    "amazon.in": "Amazon",
    "amazon.com": "Amazon",
    "amzn.in": "Amazon",
    "amzn.to": "Amazon",
    "snapdeal.com": "Snapdeal",
    "tatacliq.com": "Tata CLiQ",
    "nykaa.com": "Nykaa",
    "nykaafashion.com": "Nykaa Fashion",
    "croma.com": "Croma",
    "firstcry.com": "FirstCry",
    "lifestylestores.com": "Lifestyle",
    "shoppersstop.com": "Shoppers Stop",
    "bewakoof.com": "Bewakoof",
    "decathlon.in": "Decathlon",
    "boat-lifestyle.com": "boAt",
    "mamaearth.in": "Mamaearth",
    "wakefit.co": "Wakefit",
    "pepperfry.com": "Pepperfry",
    "ikea.com": "IKEA",
    "jiomart.com": "JioMart",
    "bigbasket.com": "BigBasket",
}

EARNKARO_PLATFORMS = {
    "Flipkart", "Shopsy", "DigiHaat", "Myntra", "Ajio", "Amazon", "Snapdeal", "Tata CLiQ", "Nykaa",
    "Nykaa Fashion", "Croma", "FirstCry", "Lifestyle", "Shoppers Stop",
    "Bewakoof", "Decathlon", "boAt", "Mamaearth", "Wakefit", "Pepperfry",
    "IKEA", "JioMart", "BigBasket",
}

# EarnKaro ka public link-converter endpoint. Purana api.earnkaro.com/v1/convert
# CloudFront par 403 deta tha (wo koi API hi nahi hai).
DEFAULT_EARNKARO_API_URL = "https://ekaro-api.affiliaters.in/api/converter/public"

# Stores that must never be listed or shown. Domain entries match the host and
# its subdomains; entries without a dot match the host as a substring so
# "hamaramall" covers hamaramall.in / .com / .shop.
BLOCKED_PLATFORMS = {"Hamara Mall", "HamaraMall"}
BLOCKED_DOMAINS = ("hamaramall",)

TRACKING_KEYS = re.compile(
    r"^(utm_.*|gclid|fbclid|msclkid|yclid|igshid|si|ref|ref_|affid|affExtParam\d+|"
    r"tag|linkCode|linkId|camp|creative|creativeASIN|ascsubtag|_ga|mc_cid|mc_eid|icid|cid)$",
    re.I,
)

CATEGORY_KEYWORDS = {
    "Footwear": [
        "shoe", "shoes", "sandal", "sandals", "sneaker", "sneakers", "footwear",
        "boot", "boots", "heel", "heels", "slipper", "slippers", "loafer",
        "loafers", "flip flop", "flip-flop", "crocs",
    ],
    "Beauty": [
        "beauty", "makeup", "make-up", "cosmetic", "cosmetics", "skincare",
        "skin care", "serum", "cream", "shampoo", "conditioner", "perfume",
        "fragrance", "lipstick", "hair oil", "haircare", "grooming", "nail",
        "eyeliner", "kajal", "sunscreen", "moisturizer", "face wash",
    ],
    "Electronics": [
        "mobile", "smartphone", "phone", "laptop", "headphone", "headphones",
        "earphone", "earphones", "earbuds", "tv", "television", "smartwatch",
        "smart watch", "camera", "speaker", "charger", "tablet", "electronic",
        "electronics", "power bank", "powerbank", "keyboard", "mouse", "monitor",
        "printer", "router", "trimmer", "bluetooth",
    ],
    "Home & Kitchen": [
        "kitchen", "cookware", "home", "furniture", "bedding", "bedsheet",
        "curtain", "pillow", "cushion", "decor", "storage", "mixer", "fan",
        "lamp", "light", "mattress", "sofa", "chair", "table", "dinner set",
        "pressure cooker", "cooker", "bottle", "flask", "wallpaper", "clock",
        "iron", "vacuum",
    ],
    "Accessories": [
        "watch", "bag", "bags", "wallet", "belt", "jewellery", "jewelry",
        "sunglasses", "eyewear", "accessory", "accessories", "handbag",
        "backpack", "sling", "clutch", "bracelet", "necklace", "earring", "ring",
        "cap", "hat", "scarf", "tie",
    ],
    "Fashion": [
        "shirt", "t-shirt", "tshirt", "tee", "kurta", "kurti", "saree", "sari",
        "dress", "jeans", "top", "tops", "lehenga", "suit", "jacket", "hoodie",
        "clothing", "apparel", "fashion", "shorts", "trouser", "pants",
        "leggings", "blouse", "gown", "sweater", "sweatshirt", "innerwear",
        "lingerie", "nightwear", "ethnic", "western", "dupatta", "salwar",
    ],
}

CATEGORIES = ["Fashion", "Electronics", "Home & Kitchen", "Beauty", "Footwear", "Accessories"]
VALID_CATEGORIES = set(CATEGORIES)
IMAGE_MAX_BYTES = 10 * 1024 * 1024
PAGE_MAX_BYTES = 4 * 1024 * 1024
IMAGE_MAX_DIMENSION = 1400
Image.MAX_IMAGE_PIXELS = 40_000_000
LOGGER = logging.getLogger("safa-sync")


class ScrapeError(RuntimeError):
    """A recoverable page/product parsing error."""


def env(name: str, default: str = "") -> str:
    return (os.getenv(name) or default).strip()


def env_int(name: str, default: int) -> int:
    """Read an integer environment variable without crashing on junk values."""
    raw = env(name)
    if not raw:
        return default
    try:
        return int(raw.replace(",", "").strip())
    except (TypeError, ValueError):
        LOGGER.warning("Ignoring invalid %s value %r; using %d", name, raw, default)
        return default


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def clean_text(value: Any) -> str:
    text = html_lib.unescape(str(value or ""))
    return re.sub(r"\s+", " ", text).strip()


def truncate(value: str, limit: int) -> str:
    value = clean_text(value)
    return value if len(value) <= limit else value[: max(0, limit - 1)].rstrip() + "…"


def normalize_price(value: Any) -> float:
    if isinstance(value, (int, float)):
        number = float(value)
    else:
        raw = re.sub(r"[^\d.,]", "", str(value or ""))
        if not raw:
            return 0.0
        if "," in raw and "." in raw:
            if raw.rfind(",") > raw.rfind("."):
                raw = raw.replace(".", "").replace(",", ".")
            else:
                raw = raw.replace(",", "")
        elif re.fullmatch(r"\d{1,3}(,\d{2,3})+", raw):
            raw = raw.replace(",", "")
        elif raw.count(",") == 1:
            raw = raw.replace(",", ".")
        else:
            raw = raw.replace(",", "")
        try:
            number = float(raw)
        except ValueError:
            return 0.0
    if number < 0 or number > 100_000_000:
        return 0.0
    return round(number, 2)


def discount_percent(mrp: float, price: float) -> int:
    if price <= 0 or mrp <= price:
        return 0
    return max(0, min(99, int(round(((mrp - price) / mrp) * 100))))


def host_of(url: str) -> str:
    try:
        return (urlsplit(url).hostname or "").lower().rstrip(".")
    except ValueError:
        return ""


def detect_platform(url: str) -> str:
    host = host_of(url)
    if not host:
        return "Other"
    for domain, name in PLATFORM_DOMAINS.items():
        if host == domain or host.endswith("." + domain):
            return name
    return "Other"


def host_is_blocked(url_or_host: str) -> bool:
    host = url_or_host
    if "://" in host or "/" in host:
        host = host_of(host)
    host = host.strip().lower().rstrip(".")
    if host.startswith("www."):
        host = host[4:]
    if not host:
        return False
    for domain in BLOCKED_DOMAINS:
        if "." in domain:
            if host == domain or host.endswith("." + domain):
                return True
        elif domain in host:
            return True
    return False


def url_is_blocked(url: str) -> bool:
    if host_is_blocked(url):
        return True
    return detect_platform(url) in BLOCKED_PLATFORMS


def product_row_is_blocked(row: dict[str, Any]) -> bool:
    if str(row.get("source_platform") or "") in BLOCKED_PLATFORMS:
        return True
    for key in ("original_url", "affiliate_url"):
        if url_is_blocked(str(row.get(key) or "")):
            return True
    return False


def canonicalize_url(url: str) -> str:
    try:
        parts = urlsplit(url.strip())
    except ValueError:
        return url.strip()
    if parts.scheme.lower() not in ("http", "https") or not parts.hostname:
        return url.strip()

    host = parts.hostname.lower().rstrip(".")
    # Keep www for Flipkart (flipkart.com without www times out from cloud runners)
    if host.startswith("www."):
        if host == "www.flipkart.com":
            host = "www.flipkart.com"
        else:
            host = host[4:]
    scheme = "https" if detect_platform(url) != "Other" else parts.scheme.lower()
    port = parts.port
    netloc = host
    if port and not ((scheme == "https" and port == 443) or (scheme == "http" and port == 80)):
        netloc += f":{port}"

    query = []
    for key, value in parse_qsl(parts.query, keep_blank_values=True):
        if key and not TRACKING_KEYS.match(key):
            query.append((key, value))

    path = parts.path or "/"
    return urlunsplit((scheme, netloc, path, urlencode(query, doseq=True), ""))


def is_supported_product_url(url: str) -> bool:
    platform = detect_platform(url)
    if platform == "Other":
        return False
    path = (urlsplit(url).path or "").lower()
    if any(token in path for token in ("/cart", "/checkout", "/login", "/account", "/search")):
        return False
    if platform == "Flipkart":
        return "/p/" in path
    if platform == "Amazon":
        return "/dp/" in path or "/gp/product/" in path
    if platform == "Myntra":
        return "/buy" in path or "/p/" in path
    if platform == "Ajio":
        return "/p/" in path
    return any(token in path for token in ("/p/", "/product/", "/dp/"))


def infer_category(text: str, hint: str = "") -> str:
    hint_clean = hint.strip().lower()
    for category in CATEGORIES:
        if hint_clean == category.lower():
            return category
    haystack = f"{text} {hint}".lower()
    for category, words in CATEGORY_KEYWORDS.items():
        for word in words:
            if re.search(rf"(^|[^a-z0-9]){re.escape(word)}([^a-z0-9]|$)", haystack):
                return category
    return "Other"


def make_slug(title: str) -> str:
    normalized = unicodedata.normalize("NFKD", clean_text(title))
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    ascii_text = re.sub(r"[^a-z0-9]+", "-", ascii_text).strip("-")
    ascii_text = re.sub(r"-+", "-", ascii_text)[:70].rstrip("-") or "product"
    return f"{ascii_text}-{os.urandom(4).hex()}"


def json_dumps(data: Any) -> str:
    return json.dumps(data, ensure_ascii=False, separators=(",", ":"))


def setup_logging(verbose: bool = False) -> None:
    level = logging.DEBUG if verbose else logging.INFO
    LOGGER.setLevel(level)
    LOGGER.handlers.clear()
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S")

    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    stream.setLevel(level)
    LOGGER.addHandler(stream)

    try:
        file_handler = RotatingFileHandler(
            LOG_FILE, maxBytes=2 * 1024 * 1024, backupCount=2, encoding="utf-8"
        )
        file_handler.setFormatter(formatter)
        file_handler.setLevel(level)
        LOGGER.addHandler(file_handler)
    except OSError as error:
        LOGGER.warning("Could not open log file %s: %s", LOG_FILE, error)


def is_safe_public_url(url: str) -> bool:
    static_cache: dict[str, bool] = getattr(is_safe_public_url, "_cache", {})
    is_safe_public_url._cache = static_cache  # type: ignore[attr-defined]
    try:
        parts = urlsplit(url)
    except ValueError:
        return False
    if parts.scheme.lower() not in ("http", "https") or not parts.hostname:
        return False
    if parts.username or parts.password:
        return False
    try:
        port = parts.port
    except ValueError:
        return False
    if port and port not in (80, 443):
        return False
    host = parts.hostname.lower().rstrip(".")
    if not host or host == "localhost" or host.endswith((".localhost", ".local", ".internal", ".home.arpa")):
        return False
    if host in static_cache:
        return static_cache[host]
    try:
        ascii_host = host.encode("idna").decode("ascii")
        addresses = socket.getaddrinfo(ascii_host, port or 443, type=socket.SOCK_STREAM)
    except (UnicodeError, socket.gaierror, OSError):
        static_cache[host] = False
        return False
    if not addresses:
        static_cache[host] = False
        return False
    for address in addresses:
        try:
            ip = ipaddress.ip_address(address[4][0])
        except ValueError:
            static_cache[host] = False
            return False
        if not ip.is_global:
            static_cache[host] = False
            return False
    static_cache[host] = True
    return True


def build_session() -> requests.Session:
    session = requests.Session()
    retry = Retry(
        total=3,
        connect=3,
        read=3,
        backoff_factor=1.5,
        status_forcelist=(429, 500, 502, 503, 504),
        allowed_methods=frozenset({"GET", "HEAD", "POST", "PATCH"}),
        respect_retry_after_header=True,
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retry, pool_connections=10, pool_maxsize=10)
    session.mount("https://", adapter)
    session.mount("http://", adapter)

    chrome_major = "131"
    session.headers.update({
        "User-Agent": USER_AGENT,
        "Accept-Language": "en-IN,en;q=0.9",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
        # Browser fingerprint headers (prevent Akamai/Cloudflare 403/timeout)
        "sec-ch-ua": f'"Chromium";v="{chrome_major}", "Not_A Brand";v="24", "Google Chrome";v="{chrome_major}"',
        "sec-ch-ua-mobile": "?0",
        "sec-ch-ua-platform": '"Windows"',
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "none",
        "Sec-Fetch-User": "?1",
        "Upgrade-Insecure-Requests": "1",
    })
    return session


def safe_get(
    session: requests.Session,
    url: str,
    *,
    max_redirects: int = 5,
    timeout: int = 30,
    referer: str = "",
) -> requests.Response:
    current = url
    extra_headers = {}
    if referer:
        extra_headers["Referer"] = referer
        extra_headers["Sec-Fetch-Site"] = "same-origin"
    for _ in range(max_redirects + 1):
        if not is_safe_public_url(current):
            raise ScrapeError(f"Unsafe or non-public URL: {current}")
        try:
            response = session.get(
                current,
                allow_redirects=False,
                stream=True,
                timeout=(10, timeout),
                headers=extra_headers or None,
            )
        except requests.RequestException as error:
            raise ScrapeError(f"Network error: {error}") from error

        if response.is_redirect:
            location = response.headers.get("Location", "")
            response.close()
            if not location:
                raise ScrapeError("Redirect response without a Location header.")
            current = urljoin(current, location)
            # Update referer for redirects
            extra_headers["Referer"] = current
            continue
        return response
    raise ScrapeError("Too many redirects.")


def read_limited(response: requests.Response, limit: int) -> bytes:
    chunks: list[bytes] = []
    total = 0
    try:
        for chunk in response.iter_content(chunk_size=65536):
            if not chunk:
                continue
            total += len(chunk)
            if total > limit:
                raise ScrapeError("Response exceeded the size limit.")
            chunks.append(chunk)
    finally:
        response.close()
    return b"".join(chunks)


FETCH_PROXY_URL = os.getenv("FETCH_PROXY_URL", "").strip()
FETCH_PROXY_SECRET = (
    os.getenv("FETCH_PROXY_SECRET") or os.getenv("UPLOAD_API_SECRET") or ""
).strip()


def fetch_html_via_proxy(url: str) -> Optional[tuple[str, int, str]]:
    """
    Fetch merchant HTML through the web server (Indian IP) when configured.

    GitHub Actions runners are outside India and Myntra serves them a stripped
    page with no product links. Routing the request through safamart.bis24.in
    makes the response identical to what the bot itself sees.
    """
    if not FETCH_PROXY_URL or not FETCH_PROXY_SECRET:
        return None
    try:
        response = requests.post(
            FETCH_PROXY_URL,
            json={"url": url},
            headers={
                "X-Safa-Secret": FETCH_PROXY_SECRET,
                "Accept": "application/json",
            },
            timeout=(10, 60),
        )
    except requests.RequestException as error:
        LOGGER.warning("Fetch proxy request failed for %s: %s", url, error)
        return None
    if response.status_code >= 400:
        LOGGER.warning("Fetch proxy rejected %s with HTTP %s", url, response.status_code)
        return None
    try:
        payload = response.json()
    except ValueError:
        LOGGER.warning("Fetch proxy returned invalid JSON for %s", url)
        return None

    status = int(payload.get("status") or 0)
    body = payload.get("body") or ""
    effective = payload.get("effective_url") or url
    if not payload.get("ok") or status >= 400 or not body:
        LOGGER.warning("Fetch proxy could not load %s (HTTP %s)", url, status)
        return "", status, effective
    return str(body), status, str(effective)


def fetch_html(session: requests.Session, url: str) -> tuple[str, int, str]:
    proxied = fetch_html_via_proxy(url)
    if proxied is not None:
        return proxied

    response = safe_get(session, url)
    status = response.status_code
    final_url = response.url
    if status in (301, 302, 303, 307, 308):
        response.close()
        return "", status, final_url
    if status >= 400:
        response.close()
        return "", status, final_url
    content_type = response.headers.get("Content-Type", "").lower()
    if "html" not in content_type and content_type:
        response.close()
        raise ScrapeError(f"Expected HTML, received {content_type}.")
    raw = read_limited(response, PAGE_MAX_BYTES)
    encoding = response.encoding or "utf-8"
    try:
        text = raw.decode(encoding, errors="replace")
    except (LookupError, UnicodeDecodeError):
        text = raw.decode("utf-8", errors="replace")

    head = text[:15000].lower()
    if re.search(r"access denied|captcha|unusual traffic|robot check|verify you are (a )?human", head) \
            and "og:title" not in text.lower():
        raise ScrapeError("The merchant blocked the request (captcha/access denied).")
    return text, status, final_url


def first_meta(meta: dict[str, list[str]], names: Iterable[str]) -> str:
    for name in names:
        values = meta.get(name.lower(), [])
        for value in values:
            cleaned = clean_text(value)
            if cleaned:
                return cleaned
    return ""


def parse_metadata(html: str, base_url: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "html.parser")
    meta: dict[str, list[str]] = {}
    for tag in soup.find_all("meta"):
        key = tag.get("property") or tag.get("name") or tag.get("itemprop") or ""
        content = tag.get("content")
        if key and content is not None:
            meta.setdefault(str(key).lower(), []).append(str(content))

    json_products: list[dict[str, Any]] = []

    def walk(node: Any, depth: int = 0) -> None:
        if depth > 12 or not isinstance(node, (dict, list)):
            return
        if isinstance(node, dict):
            types = node.get("@type", [])
            types = types if isinstance(types, list) else [types]
            if any(isinstance(item, str) and item.lower() == "product" for item in types):
                json_products.append(node)
            for value in node.values():
                walk(value, depth + 1)
        else:
            for value in node:
                walk(value, depth + 1)

    for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
        raw = script.string or script.get_text() or ""
        raw = raw.strip().strip("<!--").strip("-->").strip()
        if not raw:
            continue
        try:
            walk(json.loads(raw))
        except (json.JSONDecodeError, TypeError):
            continue

    product_ld = json_products[0] if json_products else {}
    offer: dict[str, Any] = {}
    offers = product_ld.get("offers", {})
    if isinstance(offers, dict):
        offers = [offers]
    if isinstance(offers, list):
        best_price: Optional[float] = None
        for item in offers:
            if not isinstance(item, dict):
                continue
            item_price = normalize_price(item.get("price", item.get("lowPrice", 0)))
            if item_price > 0 and (best_price is None or item_price < best_price):
                offer = item
                best_price = item_price
        if not offer:
            offer = next((item for item in offers if isinstance(item, dict)), {})

    title = first_meta(meta, ["og:title", "twitter:title", "title"])
    if not title and isinstance(product_ld.get("name"), str):
        title = clean_text(product_ld["name"])
    if not title:
        h1 = soup.find("h1")
        title = clean_text(h1.get_text(" ")) if h1 else ""
    if not title and soup.title:
        title = clean_text(soup.title.get_text(" "))
    if not title:
        raise ScrapeError("No product title found.")

    description = first_meta(meta, ["og:description", "twitter:description", "description"])
    if not description and isinstance(product_ld.get("description"), str):
        description = clean_text(product_ld["description"])

    image_candidates: list[str] = []
    for key in ("og:image:secure_url", "og:image:url", "og:image", "twitter:image", "twitter:image:src"):
        image_candidates.extend(meta.get(key, []))
    ld_image = product_ld.get("image")
    if isinstance(ld_image, str):
        image_candidates.append(ld_image)
    elif isinstance(ld_image, list):
        for item in ld_image:
            if isinstance(item, str):
                image_candidates.append(item)
            elif isinstance(item, dict):
                for key in ("url", "contentUrl", "thumbnailUrl"):
                    if isinstance(item.get(key), str):
                        image_candidates.append(item[key])

    images: list[str] = []
    for candidate in image_candidates:
        resolved = urljoin(base_url, clean_text(candidate))
        if resolved.startswith(("http://", "https://")) and resolved not in images:
            images.append(resolved)
    images = images[:8]

    currency = str(offer.get("priceCurrency") or "INR").upper()
    price_candidates = [
        offer.get("price"),
        offer.get("lowPrice"),
        first_meta(meta, ["product:price:amount", "og:price:amount", "product:sale_price:amount", "twitter:data1"]),
    ]
    price = 0.0
    for candidate in price_candidates:
        candidate_price = normalize_price(candidate)
        if candidate_price > 0:
            price = candidate_price
            break
    if price <= 0:
        rom_match = re.search(r"(?:₹|Rs\.?|INR)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)", html[:500000], re.I)
        if rom_match:
            price = normalize_price(rom_match.group(1))
    if currency != "INR" and price > 0:
        raise ScrapeError(f"Product currency is {currency}, not INR.")
    currency = "INR"

    mrp_candidates = [
        offer.get("highPrice"),
        first_meta(meta, [
            "product:original_price:amount", "og:price:standard_amount",
            "product:list_price:amount", "product:mrp:amount",
        ]),
    ]
    mrp = 0.0
    for candidate in mrp_candidates:
        candidate_mrp = normalize_price(candidate)
        if candidate_mrp > 0:
            mrp = candidate_mrp
            break
    if mrp <= 0:
        mrp_match = re.search(
            r"(?:M\.?\s*R\.?\s*P\.?|originalPrice|listPrice|strikePrice|markedPrice)"
            r"[^0-9]{0,60}(?:₹|Rs\.?|INR)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)",
            html[:500000],
            re.I,
        )
        if mrp_match:
            mrp = normalize_price(mrp_match.group(1))

    # Myntra (and a few others) expose MRP only as a JSON key, sometimes
    # escaped inside a Next.js payload (\"mrp\":499).
    if mrp <= 0:
        json_mrp = re.search(
            r'\\?"mrp\\?"\s*:\s*"?([0-9][0-9,]*(?:\.[0-9]{1,2})?)',
            html[:500000],
            re.I,
        )
        if json_mrp:
            mrp = normalize_price(json_mrp.group(1))

    # Last resort: derive MRP from the "45% OFF" label shown on the page.
    if mrp <= price:
        off_match = re.search(r'(\d{1,2})\s*%\s*OFF', html[:500000], re.I)
        if off_match:
            off = int(off_match.group(1))
            if 0 < off < 90 and price > 0:
                mrp = round(price / (1 - off / 100), 2)

    if mrp < price:
        mrp = price

    availability_raw = str(offer.get("availability") or first_meta(meta, ["product:availability"])).lower()
    availability = "unknown"
    if re.search(r"outofstock|soldout|discontinued", availability_raw):
        availability = "out_of_stock"
    elif re.search(r"instock|limitedavailability", availability_raw):
        availability = "in_stock"
    if availability == "unknown":
        body_text = soup.get_text(" ", strip=True)[:3000].lower()
        if "currently out of stock" in body_text or "this product is out of stock" in body_text:
            availability = "out_of_stock"

    brand = first_meta(meta, ["product:brand", "og:brand"])
    if not brand and isinstance(product_ld.get("brand"), str):
        brand = clean_text(product_ld["brand"])
    if not brand and isinstance(product_ld.get("brand"), dict):
        brand = clean_text(product_ld["brand"].get("name", ""))

    category_hint = first_meta(meta, ["product:category", "article:section", "category"])
    if not category_hint and isinstance(product_ld.get("category"), str):
        category_hint = clean_text(product_ld["category"])

    rating = 0.0
    rating_count = 0
    aggregate = product_ld.get("aggregateRating") if isinstance(product_ld, dict) else None
    if isinstance(aggregate, dict):
        try:
            rating = float(aggregate.get("ratingValue") or 0)
        except (TypeError, ValueError):
            rating = 0.0
        try:
            rating_count = int(float(aggregate.get("ratingCount") or aggregate.get("reviewCount") or 0))
        except (TypeError, ValueError):
            rating_count = 0
    if rating <= 0:
        rating = normalize_price(first_meta(meta, ["product:rating:value"]))
    if rating_count <= 0:
        rating_count = int(normalize_price(first_meta(meta, ["product:rating:count"])))
    rating = max(0.0, min(5.0, round(rating, 2)))

    canonical_tag = soup.find("link", rel=lambda value: value and "canonical" in value)
    canonical = canonical_tag.get("href", "") if canonical_tag else first_meta(meta, ["og:url"])
    canonical = urljoin(base_url, canonical) if canonical else base_url

    return {
        "title": truncate(title, 300),
        "description": truncate(description, 1800),
        "images": images,
        "image": images[0] if images else "",
        "price": price,
        "mrp": mrp,
        "currency": currency,
        "availability": availability,
        "brand": brand,
        "rating": rating,
        "rating_count": rating_count,
        "category_hint": category_hint,
        "canonical_url": canonical,
        "platform": detect_platform(base_url),
    }


@dataclass
class Config:
    supabase_url: str
    service_key: str
    site_url: str
    upload_secret: str
    upload_endpoint: str
    earnkaro_token: str
    earnkaro_url: str
    feeds: list[str]
    max_products: int
    per_feed: int
    stock_check: bool
    stock_limit: int
    min_discount: int
    dry_run: bool

    @classmethod
    def from_env(cls, args: argparse.Namespace) -> "Config":
        supabase_url = env("SUPABASE_URL").rstrip("/")
        if supabase_url.endswith("/rest/v1"):
            supabase_url = supabase_url[: -len("/rest/v1")]
        site_url = env("SITE_URL").rstrip("/")
        upload_endpoint = env("UPLOAD_ENDPOINT") or (f"{site_url}/api/upload.php" if site_url else "")

        feed_env = env("FEED_URLS") or env("MEESHO_FEED_URLS")
        feeds = [item.strip() for item in re.split(r"[,\n]+", feed_env) if item.strip()]
        if not feeds:
            feeds = list(DEFAULT_FEEDS)

        max_products = (
            args.max_products if args.max_products is not None else env_int("MAX_PRODUCTS", 60)
        )
        per_feed = (
            args.per_feed if args.per_feed is not None else env_int("PRODUCTS_PER_FEED", 20)
        )
        skip_stock = args.skip_stock_check or env("SKIP_STOCK_CHECK").lower() in ("1", "true", "yes", "on")
        stock_limit = env_int("STOCK_CHECK_LIMIT", 200)
        min_discount = (
            args.min_discount
            if getattr(args, "min_discount", None) is not None
            else env_int("MIN_DISCOUNT_PERCENT", 40)
        )

        earnkaro_url = env("EARNKARO_API_URL", DEFAULT_EARNKARO_API_URL).strip()
        if not earnkaro_url or "api.earnkaro.com" in earnkaro_url:
            earnkaro_url = DEFAULT_EARNKARO_API_URL

        return cls(
            supabase_url=supabase_url,
            service_key=env("SUPABASE_SERVICE_KEY"),
            site_url=site_url,
            upload_secret=env("UPLOAD_API_SECRET"),
            upload_endpoint=upload_endpoint,
            earnkaro_token=env("EARNKARO_TOKEN"),
            earnkaro_url=earnkaro_url,
            feeds=feeds,
            max_products=max(1, min(500, max_products)),
            per_feed=max(1, min(100, per_feed)),
            stock_check=not skip_stock,
            stock_limit=max(0, min(1000, stock_limit)),
            min_discount=max(0, min(95, min_discount)),
            dry_run=bool(args.dry_run),
        )

    def validate(self) -> list[str]:
        if self.dry_run:
            # Dry runs only fetch and parse pages; no credentials are needed.
            return []
        missing = []
        if not self.supabase_url.startswith(("http://", "https://")):
            missing.append("SUPABASE_URL")
        if not self.service_key:
            missing.append("SUPABASE_SERVICE_KEY")
        if not self.site_url.startswith(("http://", "https://")):
            missing.append("SITE_URL")
        if not self.upload_secret:
            missing.append("UPLOAD_API_SECRET")
        if not self.upload_endpoint.startswith(("http://", "https://")):
            missing.append("UPLOAD_ENDPOINT")
        return missing


class SupabaseClient:
    def __init__(self, config: Config) -> None:
        self.config = config
        self.table = env("SUPABASE_TABLE", "products") or "products"
        if not re.fullmatch(r"[a-z][a-z0-9_]*", self.table):
            raise ScrapeError("Invalid SUPABASE_TABLE name.")
        self.base = f"{config.supabase_url}/rest/v1/"
        self.session = build_session()
        self.session.headers.update({
            "apikey": config.service_key,
            "Authorization": f"Bearer {config.service_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "SafaMartSync/1.0",
        })
        self._price_cache: dict[str, Optional[float]] = {}

    def _request(self, method: str, path: str, **kwargs: Any) -> requests.Response:
        try:
            response = self.session.request(
                method,
                self.base + path,
                timeout=(8, 30),
                **kwargs,
            )
        except requests.RequestException as error:
            raise ScrapeError(f"Supabase network error: {error}") from error
        if response.status_code >= 300:
            raise ScrapeError(
                f"Supabase {method} failed ({response.status_code}): {response.text[:300]}"
            )
        return response

    def upsert(self, rows: list[dict[str, Any]]) -> int:
        if not rows:
            return 0
        saved = 0
        headers = {"Prefer": "resolution=merge-duplicates,return=representation"}
        path = f"{self.table}?on_conflict=original_url&select=id,slug"
        for start in range(0, len(rows), 50):
            chunk = rows[start:start + 50]
            response = self._request("POST", path, json=chunk, headers=headers)
            try:
                data = response.json()
            except ValueError:
                data = []
            if isinstance(data, list):
                saved += len(data)
            else:
                saved += len(chunk)
            LOGGER.info("Upserted batch of %d products", len(chunk))
        return saved

    def patch_metadata(self, preview: dict[str, Any]) -> None:
        """Backfill brand/rating/description on an existing product."""
        original_url = str(preview.get("original_url") or "")
        if not original_url:
            return
        values: dict[str, Any] = {
            "brand": truncate(str(preview.get("brand") or ""), 120),
            "rating": float(preview.get("rating") or 0),
            "rating_count": int(preview.get("rating_count") or 0),
            "last_checked_at": utc_now(),
        }
        description = str(preview.get("description") or "")
        if description:
            values["description"] = truncate(description, 1800)
        query = urlencode({"original_url": f"eq.{original_url}"})
        try:
            self._request("PATCH", f"{self.table}?{query}", json=values)
        except ScrapeError as error:
            LOGGER.info("Metadata backfill skipped for %s: %s", original_url, error)

    def existing_price(self, original_url: str) -> Optional[float]:
        """Price already stored for this product, or None when it is new."""
        if not original_url:
            return None
        if original_url in self._price_cache:
            return self._price_cache[original_url]
        query = urlencode({
            "select": "price",
            "original_url": f"eq.{original_url}",
            "limit": "1",
        })
        try:
            response = self._request("GET", f"{self.table}?{query}")
            data = response.json()
        except (ScrapeError, ValueError):
            self._price_cache[original_url] = None
            return None
        price: Optional[float] = None
        if isinstance(data, list) and data and isinstance(data[0], dict):
            try:
                price = float(data[0].get("price") or 0)
            except (TypeError, ValueError):
                price = None
        self._price_cache[original_url] = price
        return price

    def fetch_active(self, limit: int) -> list[dict[str, Any]]:
        if limit <= 0:
            return []
        path = (
            f"{self.table}?select=id,original_url,title,price,mrp,source_platform,last_checked_at,last_synced_at"
            f"&is_active=eq.true&order=last_checked_at.asc.nullsfirst&limit={limit}"
        )
        response = self._request("GET", path)
        try:
            data = response.json()
        except ValueError:
            return []
        return [row for row in data if isinstance(row, dict)] if isinstance(data, list) else []

    def patch(self, query: str, values: dict[str, Any]) -> None:
        if not query:
            raise ScrapeError("Refusing an unfiltered Supabase update.")
        self._request(
            "PATCH",
            f"{self.table}?{query}",
            json=values,
            headers={"Prefer": "return=minimal"},
        )

    def patch_ids(self, ids: Iterable[int], values: dict[str, Any]) -> None:
        cleaned = sorted({int(item) for item in ids if int(item) > 0})
        for start in range(0, len(cleaned), 80):
            chunk = cleaned[start:start + 80]
            if not chunk:
                continue
            self.patch("id=in.(" + ",".join(str(item) for item in chunk) + ")", values)

    def close(self) -> None:
        self.session.close()


def find_affiliate_url(payload: Any, depth: int = 0) -> Optional[str]:
    if depth > 10:
        return None
    if isinstance(payload, str):
        candidate = payload.strip()
        return candidate if candidate.startswith(("http://", "https://")) else None
    if isinstance(payload, list):
        for item in payload:
            found = find_affiliate_url(item, depth + 1)
            if found:
                return found
        return None
    if not isinstance(payload, dict):
        return None
    for key in (
        "affiliate_url", "affiliateUrl", "affiliate_link", "affiliateLink",
        "converted_url", "convertedUrl", "short_url", "shortUrl",
        "deeplink", "deep_link", "deepLink", "tracking_url", "trackingUrl",
    ):
        value = payload.get(key)
        if isinstance(value, str) and value.strip().startswith(("http://", "https://")):
            return value.strip()
    for key, value in payload.items():
        if str(key).lower() in ("url", "link") and isinstance(value, str) \
                and value.strip().startswith(("http://", "https://")):
            return value.strip()
    for value in payload.values():
        found = find_affiliate_url(value, depth + 1)
        if found:
            return found
    return None


def convert_affiliate(config: Config, session: requests.Session, url: str, platform: str) -> str:
    if platform not in EARNKARO_PLATFORMS:
        return url
    if not config.earnkaro_token:
        LOGGER.warning("EARNKARO_TOKEN is not set; keeping the original %s URL", platform)
        return url
    if not config.earnkaro_url.startswith(("http://", "https://")):
        LOGGER.warning("Invalid EARNKARO_API_URL; keeping original URL")
        return url

    headers = {
        "Authorization": f"Bearer {config.earnkaro_token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }
    # EarnKaro converter payload: {"deal": "<url>", "convert_option": "convert_only"}
    try:
        response = session.post(
            config.earnkaro_url,
            json={"deal": url, "convert_option": "convert_only"},
            headers=headers,
            timeout=(8, 15),
            allow_redirects=False,
        )
    except requests.RequestException as error:
        LOGGER.warning("EarnKaro request failed for %s: %s", platform, error)
        return url

    if response.status_code in (401, 403):
        LOGGER.error(
            "EarnKaro rejected the API token (HTTP %s); update EARNKARO_TOKEN",
            response.status_code,
        )
        return url
    if not 200 <= response.status_code < 300:
        LOGGER.warning(
            "EarnKaro conversion failed (status %s); keeping original URL",
            response.status_code,
        )
        return url

    try:
        payload = response.json()
    except ValueError:
        payload = None

    converted: Optional[str] = None
    api_message = ""
    if isinstance(payload, dict):
        data = payload.get("data")
        if isinstance(data, str):
            candidate = data.strip()
            if candidate.startswith(("http://", "https://")):
                converted = candidate
            else:
                api_message = candidate
        if not api_message:
            message = payload.get("message")
            if isinstance(message, str):
                api_message = message.strip()
    if converted is None and isinstance(payload, (dict, list)):
        # Safety net for any alternate response shape.
        converted = find_affiliate_url(payload)

    if converted:
        LOGGER.info("Converted %s link with EarnKaro", platform)
        return converted

    if api_message:
        LOGGER.warning(
            "EarnKaro could not convert %s link: %s",
            platform,
            api_message,
        )
    else:
        LOGGER.warning("EarnKaro conversion failed; keeping original URL")
    return url


def prepare_jpeg(raw: bytes) -> bytes:
    if len(raw) > IMAGE_MAX_BYTES:
        raise ScrapeError("Image exceeds the 10 MB limit.")
    try:
        with Image.open(BytesIO(raw)) as source:
            image = ImageOps.exif_transpose(source)
            image.load()

            if image.mode in ("RGBA", "LA") or "transparency" in image.info:
                rgba = image.convert("RGBA")
                background = Image.new("RGBA", rgba.size, (255, 255, 255, 255))
                image = Image.alpha_composite(background, rgba).convert("RGB")
            else:
                image = image.convert("RGB")

            resample = getattr(Image, "Resampling", Image).LANCZOS
            image.thumbnail((IMAGE_MAX_DIMENSION, IMAGE_MAX_DIMENSION), resample)

            output = BytesIO()
            image.save(output, format="JPEG", quality=88, optimize=True, progressive=True)
            return output.getvalue()
    except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError) as error:
        raise ScrapeError(f"Invalid product image: {error}") from error


def upload_image(
    config: Config,
    session: requests.Session,
    image_url: str,
    slug: str,
) -> str:
    if not is_safe_public_url(image_url):
        raise ScrapeError("Unsafe image URL.")
    # Use product page as referer for image downloads
    referer = ""
    if "flipkart.com" in image_url:
        referer = "https://www.flipkart.com/"
    elif "myntra.com" in image_url:
        referer = "https://www.myntra.com/"
    elif "amazon.in" in image_url or "amazon.com" in image_url:
        referer = "https://www.amazon.in/"
    response = safe_get(session, image_url, timeout=30, referer=referer)
    try:
        if response.status_code >= 400:
            raise ScrapeError(f"Image download failed with HTTP {response.status_code}.")
        content_type = response.headers.get("Content-Type", "").lower()
        if content_type and not content_type.startswith("image/"):
            raise ScrapeError(f"Image URL returned {content_type}.")
        raw = read_limited(response, IMAGE_MAX_BYTES)
    finally:
        response.close()

    prepared = prepare_jpeg(raw)
    try:
        upload = session.post(
            config.upload_endpoint,
            files={"image": ("product.jpg", prepared, "image/jpeg")},
            data={"slug": slug},
            headers={
                "X-API-SECRET": config.upload_secret,
                "Accept": "application/json",
                "User-Agent": "SafaMartSync/1.0",
            },
            timeout=(8, 45),
            allow_redirects=False,
        )
    except requests.RequestException as error:
        raise ScrapeError(f"Image upload failed: {error}") from error

    if upload.status_code != 200:
        raise ScrapeError(f"Image upload failed ({upload.status_code}): {upload.text[:180]}")
    try:
        payload = upload.json()
    except ValueError as error:
        raise ScrapeError("Image upload returned invalid JSON.") from error
    url = payload.get("url") if isinstance(payload, dict) else None
    if not isinstance(url, str) or not url.startswith(("http://", "https://")):
        raise ScrapeError("Image upload did not return a public URL.")
    LOGGER.info("Uploaded image %s", url)
    return url


def extract_product_links(html: str, base_url: str, limit: int) -> list[str]:
    soup = BeautifulSoup(html, "html.parser")
    candidates: list[str] = []

    for anchor in soup.find_all("a", href=True):
        href = str(anchor.get("href", "")).strip()
        if not href or href.startswith(("#", "javascript:", "mailto:", "tel:")):
            continue
        candidates.append(urljoin(base_url, href))

    for script in soup.find_all("script"):
        script_text = script.string or ""
        if not script_text or len(script_text) > 2_000_000:
            continue
        for match in re.findall(r'https?:(?://|\\/\\/)[^"\'<>\s]+', script_text):
            cleaned = match.replace("\\/", "/").replace("\\u002F", "/").replace("\\u002f", "/")
            candidates.append(html_lib.unescape(cleaned))

    links: list[str] = []
    seen: set[str] = set()
    for candidate in candidates:
        canonical = canonicalize_url(candidate.split("#", 1)[0])
        if canonical in seen or len(canonical) > 2048:
            continue
        if not is_supported_product_url(canonical):
            continue
        seen.add(canonical)
        links.append(canonical)
        if len(links) >= limit:
            break
    return links


def ajio_search_fallback(
    session: requests.Session,
    product_url: str,
) -> Optional[dict[str, Any]]:
    """
    Ajio product pages answer HTTP 403 (even through the reader), but the public
    search page embeds name, price, MRP and images for every result. GitHub
    Actions uses this instead of the blocked product page.
    """
    code_match = re.search(r"/p/(\d{5,})", product_url)
    if not code_match:
        return None
    code = code_match.group(1)

    path = urlsplit(product_url).path or ""
    slug = re.sub(r"/p/.*$", "", path)
    words = re.sub(r"[^a-z0-9]+", " ", slug, flags=re.I).strip()
    if not words:
        return None
    search_url = "https://www.ajio.com/search/?text=" + quote_plus(words[:120])

    try:
        html, status, _ = fetch_html(session, search_url)
    except ScrapeError as error:
        LOGGER.info("Ajio search fallback failed for %s: %s", product_url, error)
        return None
    if status >= 400 or not html:
        return None

    # The code also appears in the page's JSON-LD image names, so pick the
    # occurrence that actually carries the price + gallery data.
    window = ""
    for match in re.finditer(re.escape(code), html):
        candidate = html[max(0, match.start() - 1500): match.start() + 8000]
        if '"price":{"currencyIso":"INR"' in candidate and '"images":[' in candidate:
            window = candidate
            break
    if not window:
        return None

    price = 0.0
    price_match = re.search(
        r'"price":\{"currencyIso":"INR","formattedValue":"[^"]*",'
        r'"displayformattedValue":"[^"]*","value":([0-9.]+)\}',
        window,
    )
    if price_match:
        price = float(price_match.group(1))
    if price <= 0:
        offer_match = re.search(r'"offerPrice":\{[^}]*"value":([0-9.]+)', window)
        if offer_match:
            price = float(offer_match.group(1))

    mrp = 0.0
    was_match = re.search(r'"wasPriceData":\{[^}]*"value":([0-9.]+)', window)
    if was_match:
        mrp = float(was_match.group(1))
    if mrp < price:
        mrp = price

    name = ""
    name_match = re.search(r'"name":"([^"]+)"', window)
    if name_match:
        name = clean_text(name_match.group(1))
    brand = ""
    brand_match = re.search(r'"brandName":"([^"]+)"', window)
    if brand_match:
        brand = clean_text(brand_match.group(1))
    if not name:
        return None
    title = name if not brand or brand.lower() in name.lower() else f"{brand} {name}"

    images: list[str] = []
    images_match = re.search(r'"images":\[(.*?)\]', window, re.S)
    if images_match:
        for image_url in re.findall(r'"url":"(https://assets\.ajio\.com/[^"]+)"', images_match.group(1)):
            image_url = image_url.replace("\\/", "/")
            if image_url not in images:
                images.append(image_url)
    if not images:
        return None

    category_hint = ""
    hint_match = re.search(r'"brickNameText":"([^"]+)"', window)
    if hint_match:
        category_hint = clean_text(hint_match.group(1))

    return {
        "title": title,
        "description": "",
        "image": images[0],
        "images": images[:3],
        "price": price,
        "mrp": mrp,
        "currency": "INR",
        "brand": brand,
        "category_hint": category_hint,
        "availability": "unknown",
        "platform": "Ajio",
        "canonical_url": product_url,
    }


def build_product_payload(
    config: Config,
    session: requests.Session,
    product_url: str,
    feed_hint: str = "",
    deal_check: Optional[Callable[[dict[str, Any]], bool]] = None,
) -> Optional[dict[str, Any]]:
    if not is_safe_public_url(product_url):
        raise ScrapeError("Product URL is unsafe.")
    if not is_supported_product_url(product_url):
        raise ScrapeError("Product URL is not from a supported store.")

    html, status, final_url = fetch_html(session, product_url)
    metadata: Optional[dict[str, Any]] = None

    if status < 400 and html:
        metadata = parse_metadata(html, final_url)
        if (metadata["price"] <= 0 or not metadata["images"]) and detect_platform(product_url) == "Ajio":
            fallback = ajio_search_fallback(session, product_url)
            if fallback is not None:
                metadata = fallback
    elif detect_platform(product_url) == "Ajio":
        metadata = ajio_search_fallback(session, product_url)

    if metadata is None:
        raise ScrapeError(f"Product page returned HTTP {status}.")

    if metadata["availability"] == "out_of_stock":
        raise ScrapeError("Product is out of stock.")
    if metadata["price"] <= 0:
        raise ScrapeError("No INR price found on the product page.")
    if not metadata["images"]:
        raise ScrapeError("No product image found.")

    canonical = canonicalize_url(metadata.get("canonical_url") or product_url)
    if not is_supported_product_url(canonical) or detect_platform(canonical) != detect_platform(product_url):
        canonical = canonicalize_url(product_url)
    platform = detect_platform(canonical) or metadata.get("platform") or "Other"
    if platform == "Other":
        raise ScrapeError("Could not detect the source platform.")

    affiliate_url = convert_affiliate(config, session, canonical, platform)
    if not affiliate_url or affiliate_url == canonical:
        # Storefront sirf affiliate products dikhata hai, isliye bina link wale
        # product ko DB me daalne ka koi fayda nahi.
        raise ScrapeError("EarnKaro did not create an affiliate link; product skipped.")

    price = float(metadata["price"])
    mrp = float(metadata["mrp"] if metadata["mrp"] >= price else price)
    discount = discount_percent(mrp, price)

    # Decide before downloading/uploading images, otherwise skipped deals leave
    # orphan WebP files behind and waste time.
    if deal_check is not None and not deal_check({
        "original_url": canonical,
        "title": metadata["title"],
        "description": metadata.get("description", ""),
        "price": price,
        "mrp": mrp,
        "discount_percent": discount,
        "brand": metadata.get("brand", ""),
        "rating": metadata.get("rating", 0),
        "rating_count": metadata.get("rating_count", 0),
        "source_platform": platform,
    }):
        return None

    slug = make_slug(metadata["title"])

    gallery: list[str] = []
    for index, image_url in enumerate(metadata["images"][:3]):
        try:
            uploaded = upload_image(config, session, image_url, slug)
            if uploaded not in gallery:
                gallery.append(uploaded)
        except ScrapeError as error:
            if index == 0:
                raise
            LOGGER.info("Extra gallery image skipped: %s", error)
    if not gallery:
        raise ScrapeError("No image could be stored locally.")

    category = infer_category(
        f"{metadata['title']} {metadata['description']} {metadata['brand']}",
        metadata["category_hint"] or feed_hint,
    )
    if category == "Other" and feed_hint in VALID_CATEGORIES:
        category = feed_hint

    return {
        "slug": slug,
        "title": truncate(metadata["title"], 300),
        "description": truncate(metadata["description"], 1800),
        "price": price,
        "mrp": mrp,
        "discount_percent": discount,
        "category": category,
        "source_platform": platform,
        "brand": truncate(metadata.get("brand", ""), 120),
        "rating": float(metadata.get("rating") or 0),
        "rating_count": int(metadata.get("rating_count") or 0),
        "thumbnail_url": gallery[0],
        "gallery_urls": gallery,
        "affiliate_url": affiliate_url,
        "original_url": canonical,
        "is_active": True,
        "last_checked_at": utc_now(),
        "last_synced_at": utc_now(),
    }


def feed_category_hint(feed_url: str) -> str:
    path = (urlsplit(feed_url).path or "").replace("-", " ").replace("/", " ")
    return infer_category(path)


def preview_product(config: Config, session: requests.Session, product_url: str) -> None:
    """Dry-run preview without converting links or uploading images."""
    html, status, final_url = fetch_html(session, product_url)
    if status >= 400:
        raise ScrapeError(f"Product page returned HTTP {status}.")
    metadata = parse_metadata(html, final_url)
    LOGGER.info(
        "[dry-run] %s | ₹%s | MRP ₹%s | %s | %d image(s) | %s",
        truncate(metadata["title"], 70),
        metadata["price"],
        metadata["mrp"],
        metadata["availability"],
        len(metadata["images"]),
        product_url,
    )


def check_existing_products(
    config: Config,
    client: SupabaseClient,
    session: requests.Session,
) -> tuple[int, int, int]:
    if config.stock_limit <= 0:
        return 0, 0, 0

    rows = client.fetch_active(config.stock_limit)
    if not rows:
        LOGGER.info("Stock check: no active products found")
        return 0, 0, 0

    broken: list[int] = []
    healthy: list[int] = []
    price_updates = 0

    for row in rows:
        product_id = int(row.get("id") or 0)
        product_url = str(row.get("original_url") or "")
        if product_id < 1 or not product_url:
            continue
        if product_row_is_blocked(row):
            LOGGER.info("Stock check: deactivating blocked product %s", product_id)
            broken.append(product_id)
            continue
        if not is_safe_public_url(product_url):
            continue

        try:
            html, status, final_url = fetch_html(session, product_url)
        except ScrapeError as error:
            LOGGER.info("Stock check skipped %s: %s", product_url, error)
            continue

        if status in (404, 410):
            LOGGER.info("Stock check: removing broken product %s (HTTP %s)", product_id, status)
            broken.append(product_id)
            continue
        if status >= 400 or not html:
            LOGGER.info("Stock check: HTTP %s for product %s; leaving unchanged", status, product_id)
            continue

        try:
            metadata = parse_metadata(html, final_url)
        except ScrapeError as error:
            LOGGER.debug("Stock check parse failed for %s: %s", product_id, error)
            continue

        if metadata["availability"] == "out_of_stock":
            LOGGER.info("Stock check: product %s is out of stock", product_id)
            broken.append(product_id)
            continue

        new_price = float(metadata["price"])
        old_price = float(row.get("price") or 0)
        if new_price > 0 and old_price > 0 and abs(new_price - old_price) > max(2.0, old_price * 0.02):
            old_mrp = float(row.get("mrp") or 0)
            new_mrp = float(metadata["mrp"])
            if new_mrp < new_price:
                new_mrp = old_mrp if old_mrp > new_price else new_price
            try:
                client.patch(f"id=eq.{product_id}", {
                    "price": new_price,
                    "mrp": new_mrp,
                    "discount_percent": discount_percent(new_mrp, new_price),
                    "last_synced_at": utc_now(),
                })
                price_updates += 1
                LOGGER.info(
                    "Stock check: updated product %s price ₹%s → ₹%s",
                    product_id,
                    old_price,
                    new_price,
                )
            except ScrapeError as error:
                LOGGER.warning("Price update failed for product %s: %s", product_id, error)

        healthy.append(product_id)
        time.sleep(random.uniform(0.25, 0.6))

    now = utc_now()
    if broken:
        try:
            client.patch_ids(broken, {
                "is_active": False,
                "last_checked_at": now,
                "last_synced_at": now,
            })
        except ScrapeError as error:
            LOGGER.error("Could not deactivate broken products: %s", error)

    if healthy:
        try:
            client.patch_ids(healthy, {"last_checked_at": now})
        except ScrapeError as error:
            LOGGER.warning("Could not update last_checked_at: %s", error)

    LOGGER.info(
        "Stock check complete: %d checked, %d deactivated, %d price update(s)",
        len(rows),
        len(broken),
        price_updates,
    )
    return len(rows), len(broken), price_updates


def deal_decision(
    client: Optional["SupabaseClient"],
    config: Config,
    payload: dict[str, Any],
) -> str:
    """Return new / drop / skip_discount / skip_no_drop for a scraped product."""
    discount = int(payload.get("discount_percent") or 0)
    price = float(payload.get("price") or 0)
    existing: Optional[float] = None
    if client is not None:
        existing = client.existing_price(str(payload.get("original_url") or ""))

    if existing is None:
        return "new" if discount >= config.min_discount else "skip_discount"
    if price > 0 and existing > 0 and price < existing - 0.5:
        return "drop"
    return "skip_no_drop"


def run_sync(config: Config, args: argparse.Namespace) -> int:
    session = build_session()
    client: Optional[SupabaseClient] = None
    total_saved = 0
    collected: dict[str, dict[str, Any]] = {}
    deal_stats = {"new": 0, "drops": 0, "low_discount": 0, "no_drop": 0}

    try:
        if not config.dry_run:
            client = SupabaseClient(config)

        def deal_check(preview: dict[str, Any]) -> bool:
            decision = deal_decision(client, config, preview)
            if decision == "skip_discount":
                deal_stats["low_discount"] += 1
                LOGGER.info(
                    "Skipped by deal filter: %s (only %s%% off, needs %s%%)",
                    truncate(str(preview.get("title") or ""), 60),
                    preview.get("discount_percent", 0),
                    config.min_discount,
                )
                return False
            if decision == "skip_no_drop":
                deal_stats["no_drop"] += 1
                LOGGER.info(
                    "Skipped by deal filter: %s (no price drop, still ₹%s)",
                    truncate(str(preview.get("title") or ""), 60),
                    preview.get("price", 0),
                )
                # Still refresh brand/rating/description for the existing row.
                client.patch_metadata(preview)
                return False
            if decision == "drop":
                deal_stats["drops"] += 1
            else:
                deal_stats["new"] += 1
            return True

        for feed in config.feeds:
            if len(collected) >= config.max_products:
                break
            if not is_safe_public_url(feed):
                LOGGER.warning("Skipping unsafe feed URL: %s", feed)
                continue
            LOGGER.info("Fetching feed: %s", feed)
            try:
                html, status, final_url = fetch_html(session, feed)
            except ScrapeError as error:
                LOGGER.warning("Feed failed: %s (%s)", feed, error)
                continue
            if status >= 400 or not html:
                LOGGER.warning("Feed returned HTTP %s: %s", status, feed)
                continue

            hint = feed_category_hint(feed)
            links = extract_product_links(html, final_url or feed, config.per_feed)
            LOGGER.info("Found %d product link(s) on %s", len(links), feed)

            for link in links:
                if len(collected) >= config.max_products:
                    break
                if link in collected:
                    continue
                if url_is_blocked(link):
                    LOGGER.warning("Skipping blocked store: %s", link)
                    continue
                try:
                    if config.dry_run:
                        preview_product(config, session, link)
                    else:
                        payload = build_product_payload(config, session, link, hint, deal_check=deal_check)
                        if payload:
                            collected[payload["original_url"]] = payload
                            LOGGER.info(
                                "Prepared: %s | ₹%s | %s | %s%% off",
                                truncate(payload["title"], 70),
                                payload["price"],
                                payload["category"],
                                payload["discount_percent"],
                            )
                except ScrapeError as error:
                    LOGGER.warning("Skipped %s: %s", link, error)
                except Exception as error:  # Keep the batch alive on one bad page.
                    LOGGER.exception("Unexpected error for %s: %s", link, error)
                time.sleep(random.uniform(0.5, 1.1))

        if config.dry_run:
            LOGGER.info("[dry-run] No products were written and no images were uploaded")
        else:
            if client is not None and collected:
                total_saved = client.upsert(list(collected.values()))
            LOGGER.info("Upserted %d product(s) out of %d prepared", total_saved, len(collected))
            LOGGER.info(
                "Deal filter: %d new best deals, %d price drops | Skipped by deal filter: %d low discount (<%d%%), %d no price drop",
                deal_stats["new"],
                deal_stats["drops"],
                deal_stats["low_discount"],
                config.min_discount,
                deal_stats["no_drop"],
            )
            if config.stock_check and client is not None:
                check_existing_products(config, client, session)

        LOGGER.info("Sync finished successfully")
        return 0
    finally:
        session.close()
        if client is not None:
            client.close()


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Safa Mart bulk product sync")
    parser.add_argument("--max-products", type=int, default=None, help="Maximum new products per run")
    parser.add_argument("--per-feed", type=int, default=None, help="Maximum product links per feed")
    parser.add_argument("--skip-stock-check", action="store_true", help="Do not verify existing products")
    parser.add_argument("--dry-run", action="store_true", help="Scrape and log only; no writes or uploads")
    parser.add_argument("--verbose", action="store_true", help="Enable DEBUG logging")
    parser.add_argument("--feeds", nargs="+", default=None, help="Override FEED_URLS")
    parser.add_argument("--min-discount", type=int, default=None, help="Minimum discount %% for new products")
    return parser.parse_args(argv)


def main(argv: Optional[list[str]] = None) -> int:
    args = parse_args(argv)
    setup_logging(args.verbose)
    config = Config.from_env(args)
    if args.feeds:
        config.feeds = [item.strip() for item in args.feeds if item.strip()]

    missing = config.validate()
    if missing:
        LOGGER.error("Missing required configuration: %s", ", ".join(missing))
        return 1

    LOGGER.info("Starting Safa Mart sync (%s mode)", "dry-run" if config.dry_run else "live")
    LOGGER.info("Feeds: %d | max products: %d | stock check: %s | min discount: %d%%",
                len(config.feeds), config.max_products, config.stock_check, config.min_discount)
    try:
        return run_sync(config, args)
    except KeyboardInterrupt:
        LOGGER.warning("Interrupted by user")
        return 130
    except ScrapeError as error:
        LOGGER.error("Sync stopped: %s", error)
        return 1
    except Exception as error:
        LOGGER.exception("Unhandled sync failure: %s", error)
        return 1


if __name__ == "__main__":
    sys.exit(main())



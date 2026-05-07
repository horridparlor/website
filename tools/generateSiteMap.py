import os
from pathlib import Path
from bs4 import BeautifulSoup
from urllib.parse import urljoin

# CONFIG
BASE_URL = "https://rakuel.com"
ROOT_DIR = Path(__file__).resolve().parents[1]
INDEX_FILE = ROOT_DIR / "index.html"
OUTPUT_FILE = ROOT_DIR / "sitemap.xml"


def extract_links_from_index():
    """Parse index.html and extract all <a href> links."""
    with open(INDEX_FILE, "r", encoding="utf-8") as f:
        soup = BeautifulSoup(f, "html.parser")

    links = set()

    for a in soup.find_all("a", href=True):
        href = a["href"]

        # ignore external links
        if href.startswith("http"):
            continue

        links.add(href)

    return sorted(links)


def generate_sitemap(urls):
    """Generate XML sitemap content."""
    xml = ['<?xml version="1.0" encoding="UTF-8"?>']
    xml.append('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')

    # Add homepage
    xml.append("  <url>")
    xml.append(f"    <loc>{BASE_URL}/</loc>")
    xml.append("  </url>")

    # Add all pages
    for url in urls:
        full_url = urljoin(BASE_URL + "/", url.lstrip("/"))

        xml.append("  <url>")
        xml.append(f"    <loc>{full_url}</loc>")
        xml.append("  </url>")

    xml.append("</urlset>")

    return "\n".join(xml)


def main():
    print("Generating sitemap...")

    urls = extract_links_from_index()
    sitemap = generate_sitemap(urls)

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        f.write(sitemap)

    print(f"Sitemap generated at: {OUTPUT_FILE}")
    print(f"Total URLs: {len(urls) + 1}")


if __name__ == "__main__":
    main()
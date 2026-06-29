import csv
import time
import requests

TOP_N = 2000
PER_PAGE = 25
DELAY = 1.0

BASE_URL = "https://api.jikan.moe/v4/top/anime"

titles = []
rows = []

for page in range(1, (TOP_N // PER_PAGE) + 2):
    print(f"Fetching page {page}...")

    response = requests.get(
        BASE_URL,
        params={"page": page, "limit": PER_PAGE},
        timeout=30
    )
    response.raise_for_status()

    data = response.json()["data"]

    if not data:
        break

    for anime in data:
        rank = anime.get("rank")
        title = anime.get("title_english") or anime.get("title")

        titles.append(title)
        rows.append({
            "rank": rank,
            "title": title,
            "mal_id": anime.get("mal_id"),
            "score": anime.get("score"),
            "type": anime.get("type"),
            "episodes": anime.get("episodes"),
            "year": anime.get("year"),
            "url": anime.get("url"),
        })

        if len(titles) >= TOP_N:
            break

    if len(titles) >= TOP_N:
        break

    time.sleep(DELAY)

with open("mal_top_2000_titles.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(titles))

with open("mal_top_2000.csv", "w", encoding="utf-8", newline="") as f:
    writer = csv.DictWriter(
        f,
        fieldnames=["rank", "title", "mal_id", "score", "type", "episodes", "year", "url"]
    )
    writer.writeheader()
    writer.writerows(rows)

print(f"Done. Saved {len(titles)} anime.")
print("Files created:")
print("- mal_top_2000_titles.txt")
print("- mal_top_2000.csv")

#!/usr/bin/env python3
"""Filter an anime list .txt file (one title per line) by removing sequels,
variants, and other non-standalone entries."""

import re
import sys
from pathlib import Path

RULES = [
    ("Part / Season / OVA",       re.compile(r'\b(part|season|ova)\b', re.IGNORECASE)),
    ("Trailing number > 0",        re.compile(r'\s+[1-9]\d*$')),
    ("Movie",                      re.compile(r'\bmovies?\b', re.IGNORECASE)),
    ("Film / Films",               re.compile(r'\bfilms?\b', re.IGNORECASE)),
    ("Ends in Roman numeral",      re.compile(r'\s+(?=[MDCLXVI])M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$')),
    ("Ordinal suffix (2nd…)",      re.compile(r'\b\d+(st|nd|rd|th)\b', re.IGNORECASE)),
    ("Season shorthand (S2…)",     re.compile(r'\bS\d+\b')),
    ("Number before colon",        re.compile(r'\d+:')),
    ("Roman numeral in title",     re.compile(r'\bI\.|\b(?:II{1,2}|IV|VI{0,3}|IX|XI{1,3}|XIV|XV|XVI|XIX|XX)\b')),
    ("Special / Specials",         re.compile(r'\bspecials?\s*$', re.IGNORECASE)),
    ("OAD",                        re.compile(r'\boad\b', re.IGNORECASE)),
    ("ONA",                        re.compile(r'\bona\b', re.IGNORECASE)),
    ("Final / Finale",             re.compile(r'\b(final|finale)\b', re.IGNORECASE)),
    ("Ordinal word (first…)",      re.compile(r'\b(first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|eleventh|twelfth)\b', re.IGNORECASE)),
    ("After Story",                re.compile(r'\bafter\s+story\b', re.IGNORECASE)),
    ("Episode",                    re.compile(r'\bepisodes?\b', re.IGNORECASE)),
]


def matching_rule(line):
    for name, pattern in RULES:
        if pattern.search(line):
            return name
    return None


def main():
    if len(sys.argv) < 2:
        print("Usage: python filter_anime.py <file.txt>")
        sys.exit(1)

    path = Path(sys.argv[1])
    if not path.exists():
        print(f"Error: file not found: {path}")
        sys.exit(1)

    lines = path.read_text(encoding="utf-8").strip().split("\n")
    original_count = len(lines)

    kept = []
    removed = []  # list of (title, rule_name)
    for line in lines:
        rule = matching_rule(line)
        if rule:
            removed.append((line, rule))
        else:
            kept.append(line)

    path.write_text("\n".join(kept), encoding="utf-8")

    print(f"Original : {original_count}")
    print(f"Kept     : {len(kept)}")
    print(f"Removed  : {len(removed)}")

    if removed:
        # Group by rule for a tidy summary
        from collections import defaultdict
        by_rule = defaultdict(list)
        for title, rule in removed:
            by_rule[rule].append(title)

        print()
        for rule_name, titles in by_rule.items():
            print(f"[{rule_name}] — {len(titles)} removed")
            for t in titles:
                print(f"  {t}")


if __name__ == "__main__":
    main()
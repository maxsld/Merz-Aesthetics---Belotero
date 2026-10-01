# -*- coding: utf-8 -*-
"""Règles de casse partagées avec le plugin (belotero_titlecase en PHP)."""
import re

SMALL = {"de", "du", "des", "la", "le", "les", "et", "sur", "sous", "en", "aux", "au", "a", "d", "l"}
ROAD = re.compile(r"^[A-Za-z]{1,3}\d+[A-Za-z]?$")  # RN7, RD81, A6
# Sigles d'adresse conservés en capitales : routes et zones d'activité
ACRONYMS = {"RD", "RN", "CD", "VC", "ZA", "ZI", "ZAC", "ZAE"}


def _word(w, first):
    if ROAD.match(w) or w.upper() in ACRONYMS:
        return w.upper()
    if "'" in w or "’" in w:
        apos = "'" if "'" in w else "’"
        pre, rest = w.split(apos, 1)
        pre = pre.capitalize() if first else pre.lower()
        return pre + apos + (rest[:1].upper() + rest[1:].lower())
    lw = w.lower()
    if lw in SMALL and not first:
        return lw
    return lw[:1].upper() + lw[1:]


def titlecase(s):
    """Casse normale, seulement si la chaîne est entièrement en capitales."""
    s = re.sub(r"\s+", " ", (s or "")).strip()
    if not s or re.search(r"[a-zà-ÿ]", s):
        return s
    out, first = [], True
    for tok in re.split(r"(\s+|-)", s):
        if tok.strip() == "" or tok == "-":
            out.append(tok)
            continue
        out.append(_word(tok, first))
        first = False
    return "".join(out)


def clean_name(s):
    return re.sub(r"\s+", " ", (s or "")).strip()

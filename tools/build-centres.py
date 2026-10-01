#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Construit la liste des centres du doc locator à partir du fichier Excel Merz.

    python3 tools/build-centres.py "chemin/vers/Belotero liste doclocator.xlsx"

Produit, dans data/ :
  - belotero-centres.json         lu par la page (et embarqué dans le plugin)
  - belotero-centres-import.csv   à importer dans l'admin WordPress
  - belotero-centres.csv          même liste + précision du géocodage

Colonnes attendues (en-têtes reconnus sans tenir compte de la casse) :
  Sold-To Name / Name, Adresse / Street Name, CP / Postal Code, Ville / Location
Mise en forme : voir tools/casse.py (identique à l'import du plugin).
Géocodage : API Adresse (data.gouv.fr), en trois passes — adresse + code
postal, texte libre (codes CEDEX), puis la commune seule.
"""
import csv
import io
import json
import os
import re
import subprocess
import sys
import tempfile
import unicodedata
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from casse import clean_name, titlecase  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NUM = re.compile(r"^\s*(\d+\s*(?:BIS|TER|QUATER|[A-Z])?)\s+(.*)$", re.I)
ALIASES = {
    "name":   {"name", "soldtoname", "soldto", "nom", "namedocloc"},
    "street": {"adresse", "streetname", "street", "address", "rue"},
    "zip":    {"cp", "postalcode", "codepostal", "zip"},
    "city":   {"ville", "location", "city", "commune"},
}


def norm(h):
    h = unicodedata.normalize("NFKD", str(h or "")).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]", "", h.lower())


def read_xlsx(path):
    import openpyxl
    wb = openpyxl.load_workbook(path, read_only=True, data_only=True)
    for ws in wb.worksheets:
        rows = [[("" if c is None else str(c).strip()) for c in r] for r in ws.iter_rows(values_only=True)]
        for h, header in enumerate(rows[:10]):
            cols = {}
            for i, cell in enumerate(header):
                for field, names in ALIASES.items():
                    if norm(cell) in names and field not in cols:
                        cols[field] = i
            if {"name", "zip", "city"} <= cols.keys():
                print(f"onglet « {ws.title} », en-têtes ligne {h + 1} : {[header[i] for i in cols.values()]}")
                return [{f: (r[i] if i < len(r) else "") for f, i in cols.items()} for r in rows[h + 1:]]
    sys.exit("ERREUR : aucun onglet avec les colonnes nom / code postal / ville.")


def ban(rows, cols, extra):
    tmp = tempfile.NamedTemporaryFile("w", suffix=".csv", delete=False, encoding="utf-8", newline="")
    w = csv.writer(tmp)
    w.writerow(cols)
    w.writerows(rows)
    tmp.close()
    out = subprocess.run(["curl", "-s", "-X", "POST", "https://api-adresse.data.gouv.fr/search/csv/",
                          "-F", f"data=@{tmp.name}"] + extra, capture_output=True, text=True).stdout
    os.unlink(tmp.name)
    return list(csv.DictReader(io.StringIO(out)))


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    raw = read_xlsx(sys.argv[1])

    centres, seen = [], set()
    for r in raw:
        name = clean_name(r.get("name"))
        if not name:
            continue
        addr = re.sub(r"\s+", " ", r.get("street", "")).strip()
        m = NUM.match(addr)
        num, street = (m.group(1).strip().upper(), m.group(2).strip()) if m else ("", addr)
        m2 = re.match(r"^(\d+)\s+(.*)$", street)
        if m2 and m2.group(1) == num:          # « 23 23 PLACE … »
            street = m2.group(2)
        zipc = re.sub(r"\D", "", r.get("zip", "")).zfill(5) if r.get("zip") else ""
        key = (name.upper(), num, street.upper(), zipc)
        if key in seen:
            continue
        seen.add(key)
        centres.append({"name": name, "streetNumber": num, "street": titlecase(street),
                        "zip": zipc, "city": titlecase(r.get("city", "")), "country": "France",
                        "lat": "", "lng": "", "geo_score": "", "geo_type": ""})
    print(f"{len(centres)} centres après dédoublonnage")

    def apply(targets, res, typ=None):
        for c, g in zip(targets, res):
            if g.get("latitude"):
                c.update(lat=f"{float(g['latitude']):.6f}", lng=f"{float(g['longitude']):.6f}",
                         geo_score=g.get("result_score", ""), geo_type=typ or g.get("result_type", ""))

    nocedex = lambda c: re.sub(r"(?i)\s*cedex.*$", "", c["city"])
    apply(centres, ban([[f"{c['streetNumber']} {c['street']}".strip(), c["zip"], c["city"]] for c in centres],
                       ["adresse", "cp", "ville"], ["-F", "columns=adresse", "-F", "columns=ville", "-F", "postcode=cp"]))
    miss = [c for c in centres if not c["lat"]]
    if miss:
        apply(miss, ban([[f"{c['streetNumber']} {c['street']} {nocedex(c)}".strip()] for c in miss],
                        ["q"], ["-F", "columns=q"]))
    miss = [c for c in centres if not c["lat"]]
    if miss:
        apply(miss, ban([[nocedex(c), c["zip"]] for c in miss], ["ville", "cp"],
                        ["-F", "columns=ville", "-F", "postcode=cp"]), "municipality")
    miss = [c for c in centres if not c["lat"]]
    print("précision :", dict(Counter(c["geo_type"] for c in centres)))
    for c in miss:
        print("   NON LOCALISÉ :", c["name"], c["zip"], c["city"])

    centres.sort(key=lambda c: (c["zip"], c["name"]))
    d = os.path.join(ROOT, "data")
    cols = ["name", "streetNumber", "street", "zip", "city", "country", "lat", "lng", "geo_score", "geo_type"]
    with io.open(os.path.join(d, "belotero-centres.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(centres)
    icols = ["name", "name2", "streetNumber", "street", "zip", "city", "region", "country", "lat", "lng"]
    with io.open(os.path.join(d, "belotero-centres-import.csv"), "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=icols, extrasaction="ignore")
        w.writeheader()
        for c in centres:
            w.writerow({k: c.get(k, "") for k in icols})
    out = [{k: c[k] for k in ("name", "streetNumber", "street", "zip", "city")} | {"lat": float(c["lat"]), "lng": float(c["lng"])}
           for c in centres if c["lat"]]
    io.open(os.path.join(d, "belotero-centres.json"), "w", encoding="utf-8").write(
        json.dumps(out, ensure_ascii=False, separators=(",", ":")))
    print(f"écrit : {len(out)} centres dans data/belotero-centres.json")


if __name__ == "__main__":
    main()

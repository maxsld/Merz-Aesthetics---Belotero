#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Génère le plugin WordPress BELOTERO® à partir du site statique.

    python3 build-plugin.py            # (re)construit wordpress-plugin/belotero-landing/
    python3 build-plugin.py --zip      # idem + dist/belotero-landing.zip

index.html, style.css et script.js restent l'unique source : le template
WordPress, la feuille de style et le script du plugin en sont dérivés. On ne
modifie jamais les fichiers générés à la main — sur la landing RADIESSE, le
template était maintenu en parallèle du site et chaque correction devait être
faite deux fois.

Seuls les fichiers réellement référencés par la page sont embarqués.
"""
import io
import os
import re
import shutil
import sys
import urllib.parse
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.join(ROOT, "wordpress-plugin", "belotero-landing")
ASSETS = os.path.join(PLUGIN, "assets")
URL = "<?php echo esc_url( BELOTERO_URL ); ?>"

# Fichiers générés : effacés puis recréés à chaque build.
GENERATED = ["templates", "assets/belotero.css", "assets/belotero.js",
             "assets/fonts", "assets/img", "assets/vendor", "assets/data"]


def read(path):
    return io.open(os.path.join(ROOT, path), encoding="utf-8").read()


def write(path, content):
    path = os.path.join(PLUGIN, path)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    io.open(path, "w", encoding="utf-8", newline="\n").write(content)


def copy_asset(rel):
    """Copie assets/x/y du site vers assets/x/y du plugin."""
    src = os.path.join(ROOT, rel)
    if not os.path.isfile(src):
        sys.exit(f"ERREUR : fichier référencé introuvable : {rel}")
    dst = os.path.join(PLUGIN, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    shutil.copy2(src, dst)


def referenced_assets(*texts):
    refs = set()
    for t in texts:
        for m in re.findall(r'(assets/[^"\'()\s>]+)', t):
            refs.add(urllib.parse.unquote(m))
    return sorted(refs)


# ── Template ───────────────────────────────────────────────────────────────
def build_template(html):
    if "<?" in html:
        sys.exit("ERREUR : index.html contient « <? », que PHP interpréterait.")

    # Balises SEO : laissées au site WordPress (Yoast ou équivalent) pour ne pas
    # publier deux canonical, deux og:title… La donnée structurée FAQPage reste.
    html = re.sub(r'\s*<link rel="canonical"[^>]*>', "", html)
    html = re.sub(r'\s*<meta (?:property="og:|name="twitter:|name="description"|name="robots")[^>]*>', "", html)
    html = re.sub(r'\s*<!-- (?:Open Graph|Twitter Card) -->', "", html)

    # Données structurées : leurs URL deviennent celles du WordPress réel (page,
    # accueil) et celle du plugin pour les images. Remplacement limité au bloc
    # JSON-LD — les liens légaux du footer, eux, pointent volontairement vers
    # les pages fixes de merzaesthetics.fr et ne doivent pas être touchés.
    def dynamic_urls(block):
        b = block.group(0)
        b = b.replace("https://merzaesthetics.fr/assets/",
                      "<?php echo esc_url_raw( BELOTERO_URL ); ?>assets/")
        b = b.replace("https://merzaesthetics.fr/belotero-biomimetic",
                      "<?php echo esc_url_raw( untrailingslashit( get_permalink() ) ); ?>")
        b = b.replace("https://merzaesthetics.fr",
                      "<?php echo esc_url_raw( untrailingslashit( home_url() ) ); ?>")
        return b
    html, n = re.subn(r'<script type="application/ld\+json">.*?</script>', dynamic_urls, html, flags=re.S)
    if n != 1:
        sys.exit("ERREUR : bloc JSON-LD introuvable")

    html = html.replace('<html lang="fr">', "<html <?php language_attributes(); ?>>", 1)
    html = html.replace('<meta charset="UTF-8">', "<meta charset=\"<?php bloginfo( 'charset' ); ?>\">", 1)

    # Feuille de style et script du plugin
    html = html.replace('href="style.css"', f'href="{URL}assets/belotero.css"', 1)
    html = html.replace('src="script.js"', f'src="{URL}assets/belotero.js"', 1)

    # Tous les chemins d'assets, quel que soit l'attribut (src, href, poster…)
    html = re.sub(r'(=\s*")assets/', lambda m: m.group(1) + URL + "assets/", html)

    # Le footer est CONSERVÉ : vérifié sur la landing RADIESSE en production
    # (merzaesthetics.fr/radiesse), le site mère n'injecte ni header ni footer
    # dans ces pages. Sans lui, la page partirait sans aucun lien légal.

    # Points d'accroche WordPress : bandeau cookies Osano, Matomo, etc.
    html = html.replace("</head>", "  <?php wp_head(); ?>\n</head>", 1)
    html = html.replace("<body>", "<body <?php body_class( 'belotero-page' ); ?>>\n<?php wp_body_open(); ?>", 1)
    html = html.replace("</body>", "<?php wp_footer(); ?>\n</body>", 1)

    for hook in ("wp_head()", "wp_body_open()", "wp_footer()", "body_class("):
        if hook not in html:
            sys.exit(f"ERREUR : {hook} n'a pas pu être inséré")

    html = important_inline_styles(html)

    header = ("<?php\n"
              "/**\n"
              " * Template de la landing BELOTERO®.\n"
              " *\n"
              " * FICHIER GÉNÉRÉ par build-plugin.py à partir de index.html.\n"
              " * Ne pas modifier ici : modifier le site, puis relancer le build.\n"
              " */\n"
              "defined( 'ABSPATH' ) || exit;\n"
              "?>\n")
    return header + html


# ── Protection contre le CSS du thème ──────────────────────────────────────
# Comme sur la landing RADIESSE, toutes les déclarations du plugin passent en
# !important : une règle du thème qui viserait les mêmes éléments ne peut pas
# l'emporter, même si le retrait des feuilles du thème échoue.
# Le style.css du site reste sans !important ; seule la copie du plugin l'est.

def _split_top(text, sep):
    """Découpe sur sep, hors guillemets et parenthèses (url(), var()…)."""
    parts, buf, depth, quote = [], [], 0, None
    for ch in text:
        if quote:
            buf.append(ch)
            if ch == quote:
                quote = None
            continue
        if ch in "\"'":
            quote = ch
        elif ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        elif ch == sep and depth == 0:
            parts.append("".join(buf))
            buf = []
            continue
        buf.append(ch)
    parts.append("".join(buf))
    return parts


def important_declarations(block):
    out = []
    for decl in _split_top(block, ";"):
        if ":" not in decl or decl.strip() == "":
            out.append(decl)
        elif re.search(r"!\s*important\s*$", decl.strip(), re.I):
            out.append(decl)
        else:
            out.append(decl.rstrip() + " !important")
    return ";".join(out)


def _boost_selector(sel):
    """Ajoute :not(#_) à chaque sélecteur (spécificité d'un id), avant un
    éventuel pseudo-élément, qui doit rester en dernier."""
    out = []
    for s in _split_top(sel, ","):
        s = s.strip()
        m = re.search(r"::?(?:before|after|placeholder|selection|marker|first-line|first-letter)\b.*$", s)
        out.append(s[:m.start()] + ":not(#_)" + s[m.start():] if m else s + ":not(#_)")
    return ", ".join(out)


def keep_source_priority(css):
    """Une déclaration déjà !important dans la source battait toutes les autres.
    Une fois tout passé en !important, c'est la spécificité qui tranche et elle
    peut perdre (ex. .legal-code battu par .legal-inner p). On la recopie donc
    dans une règle au sélecteur renforcé, placée juste après."""
    def repl(m):
        sel, body = m.group(1), m.group(2)
        imp = [d.strip() for d in _split_top(body, ";") if re.search(r"!\s*important", d, re.I)]
        if not imp or sel.strip().startswith("@"):
            return m.group(0)
        return m.group(0) + "\n" + _boost_selector(sel) + " { " + "; ".join(imp) + "; }"
    return re.sub(r"([^{}]+)\{([^{}]*)\}", repl, css)


def add_important(css):
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    css = keep_source_priority(css)
    out, buf, stack, quote, depth = [], [], [], None, 0
    for ch in css:
        if quote:
            buf.append(ch)
            if ch == quote:
                quote = None
            continue
        if ch in "\"'":
            quote = ch
            buf.append(ch)
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if depth:
            buf.append(ch)
            continue
        if ch == "{":
            prelude = "".join(buf)
            head = prelude.strip()
            kind = head[1:].split()[0].split("(")[0].lower() if head.startswith("@") else "rule"
            stack.append(kind)
            out.append(prelude + "{")
            buf = []
        elif ch == "}":
            content = "".join(buf)
            kind = stack.pop() if stack else "rule"
            skip = kind in ("font-face", "keyframes") or any(k in ("font-face", "keyframes") for k in stack)
            if kind == "rule" and not skip:
                content = important_declarations(content)
            out.append(content + "}")
            buf = []
        else:
            buf.append(ch)
    out.append("".join(buf))
    return "".join(out)


def important_inline_styles(html):
    """Les style="" du HTML doivent aussi primer sur la feuille en !important."""
    return re.sub(r'style="([^"]*)"',
                  lambda m: 'style="' + important_declarations(m.group(1)) + '"', html)


# ── Feuille de style ───────────────────────────────────────────────────────
def build_css(css):
    # La feuille vit dans assets/ : ses url("assets/x") deviennent url("x").
    css = re.sub(r'url\((["\']?)assets/', r'url(\1', css)
    return add_important(css)


def main():
    html, css, js = read("index.html"), read("style.css"), read("script.js")

    for g in GENERATED:
        p = os.path.join(PLUGIN, g)
        if os.path.isdir(p):
            shutil.rmtree(p)
        elif os.path.isfile(p):
            os.remove(p)

    template = build_template(html)
    write("templates/page-belotero.php", template)
    write("assets/belotero.css", build_css(css))
    write("assets/belotero.js", js)

    # On analyse le template GÉNÉRÉ, pas index.html : ce qui n'y figure plus
    # (le logo du footer retiré, par exemple) n'est pas embarqué.
    assets = referenced_assets(template, css, js)
    # Images internes à Leaflet, référencées en relatif par leaflet.min.css
    leaflet_css = read("assets/vendor/leaflet/leaflet.min.css")
    for img in sorted(set(re.findall(r'url\((images/[^)]+)\)', leaflet_css))):
        assets.append("assets/vendor/leaflet/" + img)
    for a in sorted(set(assets) - {"assets/belotero.css", "assets/belotero.js"}):
        copy_asset(a)

    # Jeu de centres embarqué : utilisé tant qu'aucun CSV n'a été importé.
    os.makedirs(os.path.join(ASSETS, "data"), exist_ok=True)
    shutil.copy2(os.path.join(ROOT, "data/belotero-centres.json"),
                 os.path.join(ASSETS, "data/belotero-centres.json"))

    size = sum(os.path.getsize(os.path.join(d, f))
               for d, _, fs in os.walk(PLUGIN) for f in fs)
    print(f"Plugin généré : {len(set(assets))} assets, {size / 1024 / 1024:.1f} Mo")

    if "--zip" in sys.argv:
        os.makedirs(os.path.join(ROOT, "dist"), exist_ok=True)
        out = os.path.join(ROOT, "dist", "belotero-landing.zip")
        with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
            for d, _, fs in os.walk(PLUGIN):
                for f in fs:
                    if f == ".DS_Store":
                        continue
                    full = os.path.join(d, f)
                    z.write(full, os.path.relpath(full, os.path.dirname(PLUGIN)))
        print(f"Archive : dist/belotero-landing.zip ({os.path.getsize(out) / 1024 / 1024:.1f} Mo)")


if __name__ == "__main__":
    main()

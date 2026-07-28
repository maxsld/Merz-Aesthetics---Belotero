/* ============================================================
   BELOTERO® | interactions
   ============================================================ */

const siteHeader = document.querySelector(".site-header");
const siteMenuLinks = Array.from(document.querySelectorAll(".site-menu-item"));
const siteMenuToggle = document.querySelector(".site-menu-toggle");

/* ---- Menu mobile ---- */
if (siteHeader && siteMenuToggle) {
  const syncMenuState = (isOpen) => {
    siteHeader.classList.toggle("is-menu-open", isOpen);
    siteMenuToggle.setAttribute("aria-expanded", String(isOpen));
  };
  siteMenuToggle.addEventListener("click", () => {
    syncMenuState(!siteHeader.classList.contains("is-menu-open"));
  });
  siteMenuLinks.forEach((link) => link.addEventListener("click", () => syncMenuState(false)));
  window.addEventListener("resize", () => {
    if (window.innerWidth > 960) syncMenuState(false);
  });
}

/* ---- Header sticky ---- */
if (siteHeader) {
  const syncHeaderState = () => {
    siteHeader.classList.toggle("is-scrolled", window.scrollY > 12);
  };
  syncHeaderState();
  window.addEventListener("scroll", syncHeaderState, { passive: true });
}

/* ---- Lien de navigation actif au scroll ---- */
if (siteMenuLinks.length) {
  const menuTargets = siteMenuLinks
    .map((link) => {
      const href = link.getAttribute("href");
      if (!href || !href.startsWith("#") || href === "#top") return null;
      const target = document.querySelector(href);
      return target ? { link, target } : null;
    })
    .filter(Boolean);

  const setActive = (activeLink) =>
    siteMenuLinks.forEach((link) => link.classList.toggle("is-active", link === activeLink));

  const syncActive = () => {
    const headerOffset = siteHeader?.offsetHeight || 0;
    const triggerY = window.scrollY + headerOffset + 130;
    let activeItem = null;
    menuTargets.forEach((item) => {
      if (item.target.offsetTop <= triggerY) activeItem = item;
    });
    if (activeItem) setActive(activeItem.link);
  };

  window.addEventListener("scroll", syncActive, { passive: true });
  window.addEventListener("load", syncActive);
  syncActive();
}

/* ---- Vidéo hero ---- */
(() => {
  const video = document.querySelector(".hero-video");
  const controls = document.querySelector(".hero-video-controls");
  if (!video) return;

  const toggleBtn = controls?.querySelector(".video-toggle");
  const muteBtn = controls?.querySelector(".video-mute");
  const timeEl = controls?.querySelector(".video-time");
  const toggleIcon = toggleBtn?.querySelector("i");
  const muteIcon = muteBtn?.querySelector("i");
  const heroClips = [
    {
      src: "assets/videos/hero-sequence/clip-3.mp4",
      type: "video/mp4",
      endAt: 18
    }
  ];
  let activeClip = 0;

  video.muted = true;
  video.defaultMuted = true;
  video.loop = false;

  const fmt = (s) => {
    if (!Number.isFinite(s)) return "0:00";
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, "0");
    return `${m}:${sec}`;
  };
  const syncPlay = () => {
    const paused = video.paused;
    toggleBtn?.setAttribute("aria-label", paused ? "Lire la vidéo" : "Mettre en pause");
    toggleIcon?.classList.toggle("fa-play", paused);
    toggleIcon?.classList.toggle("fa-pause", !paused);
  };
  const syncMute = () => {
    const m = video.muted;
    muteBtn?.setAttribute("aria-label", m ? "Activer le son" : "Couper le son");
    muteIcon?.classList.toggle("fa-volume-xmark", m);
    muteIcon?.classList.toggle("fa-volume-high", !m);
  };
  const playCurrent = () => video.play().catch(() => {});
  const loadClip = (index, autoplay = true) => {
    activeClip = index % heroClips.length;
    const clip = heroClips[activeClip];
    if (video.currentSrc.endsWith(clip.src)) {
      video.currentTime = 0;
      if (autoplay) playCurrent();
      return;
    }
    video.src = clip.src;
    video.load();
    if (autoplay) playCurrent();
  };
  const nextClip = (autoplay = true) => loadClip(activeClip + 1, autoplay);

  toggleBtn?.addEventListener("click", () => {
    if (video.paused) playCurrent();
    else video.pause();
  });
  muteBtn?.addEventListener("click", () => { video.muted = !video.muted; syncMute(); });
  video.addEventListener("play", syncPlay);
  video.addEventListener("pause", syncPlay);
  video.addEventListener("volumechange", syncMute);
  video.addEventListener("ended", () => nextClip(true));
  video.addEventListener("timeupdate", () => {
    const clip = heroClips[activeClip];
    if (clip.endAt && video.currentTime >= clip.endAt) nextClip(!video.paused);
    if (timeEl) timeEl.textContent = fmt(video.currentTime);
  });

  loadClip(0, false);
  const tryPlay = () => playCurrent();
  video.addEventListener("canplay", tryPlay);
  window.addEventListener("load", tryPlay, { once: true });
  syncPlay();
  syncMute();
})();

/* ---- Accordéon FAQ ---- */
const faqItems = document.querySelectorAll(".faq-item");
const setPanelHeight = (item, open) => {
  const panel = item.querySelector(".faq-panel");
  if (panel) panel.style.height = open ? `${panel.scrollHeight}px` : "0px";
};
faqItems.forEach((item) => {
  const trigger = item.querySelector(".faq-trigger");
  if (!trigger) return;
  setPanelHeight(item, item.classList.contains("is-open"));
  trigger.addEventListener("click", () => {
    const open = !item.classList.contains("is-open");
    item.classList.toggle("is-open", open);
    trigger.setAttribute("aria-expanded", String(open));
    setPanelHeight(item, open);
  });
});
window.addEventListener("resize", () => {
  faqItems.forEach((item) => item.classList.contains("is-open") && setPanelHeight(item, true));
});

/* ---- Pins zones : entrée animée au scroll ---- */
if ("IntersectionObserver" in window) {
  const stage = document.querySelector(".areas-figure");
  if (stage) {
    const pins = stage.querySelectorAll(".pin");
    const obs = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        pins.forEach((c, i) => setTimeout(() => c.classList.add("is-visible"), i * 160));
        obs.unobserve(entry.target);
      });
    }, { threshold: 0.4, rootMargin: "0px 0px -8% 0px" });
    obs.observe(stage);
  }
}

/* ---- Compteurs animés (chiffres clés) ---- */
(() => {
  const counters = Array.from(document.querySelectorAll(".stat strong, .benefit-stat strong"));
  if (!counters.length) return;
  const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;

  const parse = (text) => {
    const m = text.trim().match(/^([^0-9]*)(\d+)(.*)$/s);
    return m ? { prefix: m[1] || "", value: Number(m[2]), suffix: m[3] || "" } : null;
  };
  const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

  const animate = (el) => {
    if (el.dataset.done === "true") return;
    // ne pas animer les libellés purement textuels (CPM®, 1re…)
    const parsed = parse(el.textContent || "");
    el.dataset.done = "true";
    if (!parsed || reduce) return;
    const duration = 2200;
    const start = performance.now();
    const frame = (now) => {
      const p = Math.min((now - start) / duration, 1);
      const val = Math.max(1, Math.round(parsed.value * ease(p)));
      el.textContent = `${parsed.prefix}${val}${parsed.suffix}`;
      if (p < 1) requestAnimationFrame(frame);
      else el.textContent = `${parsed.prefix}${parsed.value}${parsed.suffix}`;
    };
    el.textContent = `${parsed.prefix}1${parsed.suffix}`;
    requestAnimationFrame(frame);
  };

  if (!("IntersectionObserver" in window)) { counters.forEach(animate); return; }
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => { if (e.isIntersecting) { animate(e.target); io.unobserve(e.target); } });
  }, { threshold: 0.4 });
  counters.forEach((c) => io.observe(c));
})();

/* ---- Slider avant / après + sélecteur de traitement ---- */
(() => {
  const compare = document.getElementById("compare");
  if (!compare) return;
  const before = compare.querySelector(".compare-before");
  const after = compare.querySelector(".compare-after");
  const handle = compare.querySelector(".compare-handle");
  const chips = Array.from(document.querySelectorAll(".results-chip"));
  let pos = 50;

  const setPos = (percent) => {
    pos = Math.max(0, Math.min(100, percent));
    before.style.clipPath = `inset(0 ${100 - pos}% 0 0)`;
    handle.style.left = `${pos}%`;
    compare.setAttribute("aria-valuenow", String(Math.round(pos)));
  };

  const posFromEvent = (clientX) => {
    const rect = compare.getBoundingClientRect();
    setPos(((clientX - rect.left) / rect.width) * 100);
  };

  let dragging = false;
  const start = (e) => { dragging = true; posFromEvent(e.clientX ?? e.touches?.[0]?.clientX); };
  const move = (e) => {
    if (!dragging) return;
    const x = e.clientX ?? e.touches?.[0]?.clientX;
    if (x != null) { posFromEvent(x); e.preventDefault?.(); }
  };
  const end = () => { dragging = false; };

  compare.addEventListener("mousedown", start);
  window.addEventListener("mousemove", move, { passive: false });
  window.addEventListener("mouseup", end);
  compare.addEventListener("touchstart", start, { passive: true });
  window.addEventListener("touchmove", move, { passive: false });
  window.addEventListener("touchend", end);

  compare.addEventListener("keydown", (e) => {
    if (e.key === "ArrowLeft") { setPos(pos - 4); e.preventDefault(); }
    if (e.key === "ArrowRight") { setPos(pos + 4); e.preventDefault(); }
  });

  // Sélecteur de type de traitement : change la paire avant/après
  const captionEl = document.getElementById("results-caption-text");
  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      chips.forEach((c) => { c.classList.remove("is-active"); c.setAttribute("aria-selected", "false"); });
      chip.classList.add("is-active");
      chip.setAttribute("aria-selected", "true");
      before.src = chip.dataset.before;
      after.src = chip.dataset.after;
      if (captionEl && chip.dataset.caption) captionEl.textContent = chip.dataset.caption;
      setPos(50);
    });
  });

  setPos(50);
})();

/* ---- Localisateur de praticiens BELOTERO® ---- */
(() => {
  const input = document.getElementById("loc-search");
  const searchBtn = document.querySelector(".cta-search-btn");
  const resultsBox = document.getElementById("locator-results");
  const countEl = document.getElementById("locator-count");
  const listEl = document.getElementById("locator-list");
  const mapEl = document.getElementById("locator-map");
  const mapFrame = document.querySelector(".cta-map-frame");
  if (!input || !searchBtn || !resultsBox || !mapEl || typeof L === "undefined") return;

  const MAX_RESULTS = 8;
  const RADIUS_KM = 60;
  const FALLBACK = 3;

  // Praticiens (données de démonstration)
  const CENTERS = [
    { name: "Cabinet Esthétique Saint-Honoré", city: "Paris", zip: "75008", lat: 48.870477, lng: 2.310511, street: "Rue de Ponthieu", num: "12" },
    { name: "Clinique Saint-Germain Esthétique", city: "Paris", zip: "75006", lat: 48.852399, lng: 2.339677, street: "Boulevard Saint-Germain", num: "126" },
    { name: "Centre Dermatologique Opéra", city: "Paris", zip: "75009", lat: 48.8805, lng: 2.330073, street: "Square Moncey", num: "5" },
    { name: "Cabinet Médical Monceau", city: "Paris", zip: "75017", lat: 48.882461, lng: 2.309578, street: "Place du Général Catroux", num: "7" },
    { name: "Institut Esthétique Caudéran", city: "Bordeaux", zip: "33200", lat: 44.856182, lng: -0.615268, street: "Rue Falquet", num: "12" },
    { name: "Cabinet Rodocanachi", city: "Marseille", zip: "13008", lat: 43.274374, lng: 5.385837, street: "Boulevard Rodocanachi", num: "55" },
    { name: "Institut Esthétique Prado", city: "Marseille", zip: "13006", lat: 43.289532, lng: 5.374693, street: "Rue Roux de Brignoles", num: "13" },
    { name: "Cabinet Quai Jean Moulin", city: "Lyon", zip: "69001", lat: 45.766576, lng: 4.837887, street: "Quai Jean Moulin", num: "9" },
    { name: "Clinique Presqu'île Esthétique", city: "Lyon", zip: "69002", lat: 45.754, lng: 4.832, street: "Rue de la République", num: "48" },
    { name: "Centre Médical Wilson", city: "Toulouse", zip: "31000", lat: 43.6045, lng: 1.4442, street: "Place du Président Wilson", num: "3" },
    { name: "Cabinet Promenade", city: "Nice", zip: "06000", lat: 43.6959, lng: 7.2716, street: "Rue de France", num: "21" },
    { name: "Centre Esthétique Graslin", city: "Nantes", zip: "44000", lat: 47.217029, lng: -1.563169, street: "Place Aristide Briand", num: "5" },
    { name: "Cabinet Médical Antigone", city: "Montpellier", zip: "34000", lat: 43.600264, lng: 3.898424, street: "Rue de Syracuse", num: "82" },
    { name: "Clinique Villa Ermitage", city: "Lambersart", zip: "59130", lat: 50.644465, lng: 3.031826, street: "Avenue Henri Delecaux", num: "8" },
    { name: "Centre Dermatologique Neudorf", city: "Strasbourg", zip: "67100", lat: 48.5734, lng: 7.7521, street: "Route du Polygone", num: "104" },
    { name: "Cabinet Entraigues", city: "Tours", zip: "37000", lat: 47.388074, lng: 0.688381, street: "Rue d'Entraigues", num: "19" },
    { name: "Maison Elixience", city: "Metz", zip: "57000", lat: 49.107022, lng: 6.163254, street: "Rue Bossuet", num: "31" },
    { name: "Dermatologie Esthétique Caen", city: "Caen", zip: "14000", lat: 49.179074, lng: -0.361873, street: "Place de l'Ancienne Comédie", num: "12" },
    { name: "Centre Esthétique Thabor", city: "Rennes", zip: "35000", lat: 48.1147, lng: -1.6702, street: "Rue de Paris", num: "42" },
    { name: "Clinique Del Mar", city: "Antibes", zip: "06160", lat: 43.558947, lng: 7.128187, street: "Boulevard Francis Meilland", num: "90" },
  ];

  const DEPT_COORDS = {
    "01":[46.2,5.2],"02":[49.5,3.4],"03":[46.3,3.4],"04":[44.1,6.2],"05":[44.7,6.4],"06":[43.9,7.2],"07":[44.7,4.7],"08":[49.7,4.7],"09":[42.9,1.6],"10":[48.3,4.1],
    "11":[43.2,2.4],"12":[44.3,2.6],"13":[43.5,5.4],"14":[49.1,-0.4],"15":[45.0,2.6],"16":[45.7,0.2],"17":[45.7,-0.6],"18":[47.1,2.4],"19":[45.3,2.0],"21":[47.3,4.8],
    "22":[48.3,-2.8],"23":[46.0,2.2],"24":[45.1,0.7],"25":[47.2,6.0],"26":[44.7,5.0],"27":[49.1,1.2],"28":[48.4,1.5],"29":[48.2,-4.2],"2A":[41.9,9.0],"2B":[42.4,9.3],
    "30":[44.0,4.2],"31":[43.6,1.4],"32":[43.6,0.6],"33":[44.8,-0.6],"34":[43.6,3.9],"35":[48.1,-1.7],"36":[46.8,1.6],"37":[47.4,0.7],"38":[45.2,5.7],"39":[46.7,5.6],
    "40":[43.9,-0.8],"41":[47.6,1.3],"42":[45.5,4.2],"43":[45.0,3.9],"44":[47.2,-1.6],"45":[47.9,2.2],"46":[44.6,1.6],"47":[44.4,0.6],"48":[44.5,3.5],"49":[47.5,-0.6],
    "50":[49.1,-1.3],"51":[49.0,4.4],"52":[48.1,5.1],"53":[48.1,-0.8],"54":[48.7,6.2],"55":[49.1,5.4],"56":[47.9,-2.9],"57":[49.1,6.2],"58":[47.1,3.5],"59":[50.4,3.1],
    "60":[49.4,2.1],"61":[48.4,0.1],"62":[50.5,2.6],"63":[45.8,3.2],"64":[43.3,-0.4],"65":[43.2,0.1],"66":[42.7,2.9],"67":[48.5,7.5],"68":[47.8,7.3],"69":[45.7,4.8],
    "70":[47.6,6.2],"71":[46.6,4.5],"72":[47.9,0.2],"73":[45.6,6.4],"74":[46.0,6.4],"75":[48.9,2.3],"76":[49.4,1.1],"77":[48.5,2.9],"78":[48.8,1.9],"79":[46.4,-0.4],
    "80":[50.0,2.3],"81":[43.9,2.1],"82":[44.0,1.4],"83":[43.4,6.1],"84":[43.9,5.1],"85":[46.7,-1.4],"86":[46.6,0.3],"87":[45.8,1.3],"88":[48.1,6.5],"89":[47.9,3.6],
    "90":[47.6,6.9],"91":[48.6,2.3],"92":[48.8,2.2],"93":[48.9,2.4],"94":[48.8,2.5],"95":[49.1,2.1],
  };

  const CITY_TO_DEPT = {
    "paris":"75","marseille":"13","lyon":"69","toulouse":"31","nice":"06","nantes":"44","strasbourg":"67","montpellier":"34","bordeaux":"33","lille":"59","rennes":"35",
    "reims":"51","le havre":"76","toulon":"83","grenoble":"38","dijon":"21","angers":"49","nimes":"30","nîmes":"30","tours":"37","amiens":"80","limoges":"87","metz":"57",
    "caen":"14","antibes":"06","cannes":"06","brest":"29","orleans":"45","orléans":"45","rouen":"76","mulhouse":"68","nancy":"54","perpignan":"66","besancon":"25","besançon":"25",
    "aix-en-provence":"13","clermont-ferrand":"63","annecy":"74","avignon":"84","poitiers":"86","pau":"64","la rochelle":"17","monaco":"06",
  };

  let map = null;
  let markers = [];

  const icon = () => L.divIcon({
    className: "",
    html: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="38" viewBox="0 0 28 38"><path d="M14 0C6.268 0 0 6.268 0 14c0 9.5 14 24 14 24S28 23.5 28 14C28 6.268 21.732 0 14 0z" fill="#EC7404"/><circle cx="14" cy="14" r="6" fill="#fff"/></svg>',
    iconSize: [28, 38], iconAnchor: [14, 38], popupAnchor: [0, -40],
  });

  const ensureMap = () => {
    if (map) return map;
    if (mapFrame) mapFrame.hidden = true;
    mapEl.hidden = false;
    map = L.map(mapEl, { zoomControl: true }).setView([46.8, 2.3], 6);
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', maxZoom: 18,
    }).addTo(map);
    setTimeout(() => map.invalidateSize(), 60);
    return map;
  };

  const esc = (v) => String(v).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  const addr = (c) => `${c.num} ${c.street}, ${c.zip} ${c.city}`;
  const directions = (c) => `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(addr(c) + ", France")}`;

  const haversine = (la1, ln1, la2, ln2) => {
    const R = 6371, dLa = ((la2 - la1) * Math.PI) / 180, dLn = ((ln2 - ln1) * Math.PI) / 180;
    const a = Math.sin(dLa / 2) ** 2 + Math.cos((la1 * Math.PI) / 180) * Math.cos((la2 * Math.PI) / 180) * Math.sin(dLn / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const zipCoords = (zip) => {
    if (!/^\d{5}$/.test(zip)) return null;
    const code = zip.substring(0, 2);
    return DEPT_COORDS[code] ? { lat: DEPT_COORDS[code][0], lng: DEPT_COORDS[code][1] } : null;
  };
  const cityCoords = (q) => {
    const code = CITY_TO_DEPT[q.toLowerCase().trim()];
    return code && DEPT_COORDS[code] ? { lat: DEPT_COORDS[code][0], lng: DEPT_COORDS[code][1] } : null;
  };
  const geocode = (q) =>
    fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=fr,mc&q=${encodeURIComponent(q)}`, { headers: { Accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((d) => (d?.length ? { lat: parseFloat(d[0].lat), lng: parseFloat(d[0].lon) } : null));

  const renderList = (items) => {
    listEl.innerHTML = "";
    items.forEach((c) => {
      const li = document.createElement("li");
      li.className = "locator-item";
      li.innerHTML =
        `<p class="locator-item-title">${esc(c.name)}</p>` +
        `<p class="locator-item-address">${esc(addr(c))}</p>` +
        `<span class="locator-item-distance">${Math.round(c.dist)} km</span>` +
        '<div class="locator-item-actions">' +
          '<button type="button" class="locator-action locator-action-primary" data-act="dir"><i class="fa-solid fa-route"></i> Itinéraire</button>' +
        "</div>";
      li.addEventListener("click", () => {
        map.setView([c.lat, c.lng], 14);
        markers.find((m) => m._c === c)?.openPopup();
      });
      li.querySelector('[data-act="dir"]').addEventListener("click", (e) => {
        e.stopPropagation();
        window.open(directions(c), "_blank", "noopener");
      });
      listEl.appendChild(li);
    });
    resultsBox.hidden = false;
  };

  const showNearest = (lat, lng, q) => {
    ensureMap();
    markers.forEach((m) => map.removeLayer(m));
    markers = [];
    const sorted = CENTERS.map((c) => ({ ...c, dist: haversine(lat, lng, c.lat, c.lng) })).sort((a, b) => a.dist - b.dist);
    let nearest = sorted.filter((c) => c.dist <= RADIUS_KM).slice(0, MAX_RESULTS);
    let fallback = false;
    if (!nearest.length) { nearest = sorted.slice(0, FALLBACK); fallback = true; }

    markers = nearest.map((c) => {
      const m = L.marker([c.lat, c.lng], { icon: icon() }).addTo(map);
      m._c = c;
      m.bindPopup(`<div class="locator-popup"><p class="locator-popup-title">${esc(c.name)}</p><p class="locator-popup-address">${esc(addr(c))}</p></div>`);
      return m;
    });
    map.fitBounds(L.latLngBounds(nearest.map((c) => [c.lat, c.lng])), { padding: [40, 40], maxZoom: 12 });
    countEl.textContent = fallback
      ? `Aucun praticien à moins de ${RADIUS_KM} km de « ${q} ». Voici les ${nearest.length} plus proches :`
      : `${nearest.length} praticien${nearest.length > 1 ? "s" : ""} BELOTERO® près de « ${q} »`;
    renderList(nearest);
  };

  const doSearch = () => {
    const q = input.value.trim();
    if (!q) return;
    const coords = zipCoords(q) || cityCoords(q);
    if (coords) { showNearest(coords.lat, coords.lng, q); return; }
    countEl.textContent = `Recherche autour de « ${q} »…`;
    listEl.innerHTML = "";
    resultsBox.hidden = false;
    geocode(q)
      .then((loc) => loc ? showNearest(loc.lat, loc.lng, q) : (countEl.textContent = `Aucun praticien trouvé pour « ${q} ». Essayez un code postal.`))
      .catch(() => { countEl.textContent = `Aucun praticien trouvé pour « ${q} ». Essayez un code postal.`; });
  };

  searchBtn.addEventListener("click", doSearch);
  input.addEventListener("keydown", (e) => { if (e.key === "Enter") doSearch(); });
})();

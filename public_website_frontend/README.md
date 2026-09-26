# Felege Kidusan Sunday School (FKSS) — Standalone Frontend Package

This package is the **exact, decoupled frontend extraction** of the current Sunday School Management System (SSMS) landing website (`index.php`), tailored for local frontend redesign and development **with zero PHP or MySQL dependencies**.

---

## 🔍 Deep Identity & Archaeology Analysis: "Felege Kidusan" vs "Birhan"

### 1. Where did "Birhan" originate?
Across the SSMS codebase archaeology:
- The system codebase previously originated / branched from a predecessor church project named **"WBWS" / "Wulde Birhan" (ውሉደ ብርሃን - "Children of Light")**.
- In the active configuration (`school_config.php`) and themes (`themes/fkss/`), the system was officially rebranded to **"FKSS" / "Felege Kidusan" (ፈለገ ቅዱሳን - "Spring of Saints")** under the parish **Bole Bulbula St. Mary & St. John Holy Church (የቦሌ ቡልቡላ ፍ/ሕ ቅድስት ድንግል ማርያም እና መ/መ/ ቅ/ዮሐንስ ቤ/ክ)**.
- In legacy areas of `index.php` (such as `info@wulidebirhan.org`), traces of the old WBWS name remained (detected by `leak_detector.php`).
- In an earlier draft, the names "Felege" and "Birhan" were mistakenly merged into "Felege Birhan".

### 2. The True Single Source of Truth
- **Amharic Name:** `ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት` (Short: `ፈለገ ቅዱሳን`)
- **English Name & Translation:** `Felege Kidusan Sunday School` (*"Spring of Saints"*)
- **Parish Name:** `የቦሌ ቡልቡላ ፍ/ሕ ቅድስት ድንግል ማርያም እና መ/መ/ ቅ/ዮሐንስ ቤ/ክ` (*Bole Bulbula St. Mary & St. John Holy Church*)
- **Denomination:** `የኢትዮጵያ ኦርቶዶክስ ተዋሕዶ ቤተ ክርስቲያን` (*Ethiopian Orthodox Tewahdo Church*)
- **Theme Palette:** Deep Maroon (`#600000` / `#400000`) and Radiant Gold (`#F0C000` / `#E0B020`).

---

## 📁 Decoupled Package Structure

```text
public_website_frontend/
├── index.html                  # Exact semantic HTML extracted from index.php
├── README.md                   # This developer & integration guide
├── css/
│   ├── style.css               # Extracted theme variables, hero pattern, card hover, palette remap
│   └── public_gallery.css      # Extracted public gallery styles and lightbox CSS
├── js/
│   ├── main.js                 # Mobile menu toggle, smooth scroll, scroll effects, card observer
│   ├── public_gallery.js       # Gallery explorer with local data/gallery.json fallback
│   └── registration.js         # Real-time form handler (local mock or live /register_submit.php)
├── data/                       # Exact database schemas matching CMS tables
│   ├── school_config.json      # School identity, contact, parish, and brand colors
│   ├── programs.json           # cms_programs (Little Lambs, Young Disciples, Teen Ministry)
│   ├── schedule.json           # cms_schedule (Sunday, Wednesday, Saturday)
│   ├── teachers.json           # cms_teachers (Abba Tekle, Memhir Dawit, W/ro Marta, Gashe Solomon)
│   ├── gallery.json            # Public gallery API payload (albums, featured, photo items)
│   └── social.json             # cms_social_links (Facebook, Telegram, YouTube)
└── assets/
    ├── logos/
    │   ├── school_logo.png     # Official FKSS circular seal & cross logo
    │   ├── icon-192.png        # PWA app icon 192px
    │   └── icon-512.png        # PWA app icon 512px
    └── images/
        ├── teachers/           # Profile portraits
        └── gallery/            # Activity photo assets
```

---

## 🚀 How to Run and Edit Locally

You do **not** need PHP, MySQL, Apache, or Docker. Run any static web server:

```bash
# Option 1: Python built-in web server
python3 -m http.server 3000

# Option 2: Node.js npx serve
npx serve .

# Option 3: VS Code Live Server
# Right click index.html -> "Open with Live Server"
```

Open `http://localhost:3000` in any browser.

---

## 🎨 Theme Palette & CSS Variable System

The design uses the exact FKSS brand tokens defined in `school_config.php:175`:

```css
:root {
    --fkss-maroon: #600000;        /* Deep maroon brand color */
    --fkss-maroon-light: #8B2030;  /* Lighter maroon for hovers */
    --fkss-maroon-dark: #400000;   /* Darkest maroon (hero background & footer) */
    --fkss-gold: #F0C000;          /* Primary gold accent */
    --fkss-gold-dark: #E0B020;     /* Secondary deeper gold accent */
}
```

### FKSS Palette Remap
`css/style.css` includes automatic class remaps (`.text-green-*`, `.bg-green-*`, `.text-yellow-*`) so standard Tailwind classes adapt immediately to the official maroon and gold color scheme.

---

## 🔄 Dynamic CMS Data Contracts

When re-integrating the redesigned HTML back into `index.php`, the backend populates data using the schemas found in the `data/` folder:

| Component | JSON Contract File | Backing Database Table |
| :--- | :--- | :--- |
| **School Config** | `data/school_config.json` | `school_config.php` constants |
| **Programs** | `data/programs.json` | `cms_programs` |
| **Schedule** | `data/schedule.json` | `cms_schedule` |
| **Teachers** | `data/teachers.json` | `cms_teachers` |
| **Gallery** | `data/gallery.json` | `cms_gallery_photos`, `cms_gallery_albums` |
| **Social Links** | `data/social.json` | `cms_social_links` |

---

## 📝 Student Registration Form Contract

In production, the form posts via `FormData` to `/register_submit.php`.
When running standalone, `js/registration.js` automatically simulates the submission and saves test records to `localStorage.getItem('fkss_submissions')`.

### Expected Fields:
- `guardian_name`: Parent or legal guardian full name
- `full_name` *(Required)*: Student's full name
- `age`: Student age (4 to 18)
- `phone` *(Required)*: Phone number (`+251 9X XXX XXXX`)
- `email`: Email address
- `message`: Optional special needs, spiritual background, or questions
- `website` *(Honeypot)*: Anti-spam trap input (must remain empty)

---

## 📦 How to Re-Integrate Back into `index.php`

Once your frontend redesign is complete:
1. Update `index.php` with the new HTML markup structure.
2. Replace static sample items in `#programs-grid`, `#schedule-list`, and `#teachers-grid` with the existing PHP loops (`<?php foreach ($cms['programs'] as $prog): ?> ... <?php endforeach; ?>`).
3. Copy any updated CSS and JS files into `/css/` and `/js/`.

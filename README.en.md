# AssetSign — Handover, return, donation and sale of equipment with electronic signature

<p align="center"><img src="docs/banner.png" alt="AssetSign — Signature, suivi, traçabilité" width="180"></p>

<p align="center"><strong>Written proof of every equipment handover — no paper, no spreadsheet, no extra effort.</strong></p>

Created by **Vincent GUILLOTTE**.

[🇫🇷 Français](README.md) | 🇬🇧 **English**

## Table of contents

- [What is this plugin?](#what-is-this-plugin)
- [What sets it apart](#what-sets-it-apart)
- [Screenshots](#screenshots)
- [Documentation](#documentation)
- [License](#license)

## What is this plugin?

When a computer, monitor, phone or any other piece of equipment is handed over to someone in the company, a written trace is often needed: that the recipient acknowledges receiving that equipment, in that condition, on that date, and accepts the terms of use (IT charter, terms and conditions...). In practice this step is often skipped, done on paper and never archived, or handled by hand in a spreadsheet — until the day a dispute or an audit asks for it, and it exists nowhere.

This plugin automates it entirely from GLPI, the tool your technicians already use to manage assets — no new screen to learn, no external service to pay for:

1. A technician assigns equipment to a user in GLPI (as they already normally do).
2. The plugin detects it automatically and generates a **handover sheet as a PDF** (equipment, recipient, accessories, terms and conditions, IT charter).
3. The recipient gets an **e-mail** with a link to that sheet.
4. They log in to GLPI with their own account, **review the PDF and sign on screen** (mouse, finger or stylus).
5. The **signed PDF** is automatically archived — on the equipment record **and** on the user's record, viewable at any time.
6. If the recipient doesn't sign, the plugin **sends automatic reminders**, then marks the document as expired after a configurable delay.

The same mechanism also works in reverse: when equipment is **returned** (unassigned), a return sheet can be generated and signed the same way. Two more workflows are also available for a **Donation** or a **Sale** of equipment, including to a person or association outside the company — see the [user guide](USER_GUIDE.md) for the full feature list.

**Security**: the signature page is never reachable via a plain anonymous link — you must be logged in to GLPI, and the link only works for the actual recipient of the document. Another authenticated user who obtained the link by mistake (forwarded e-mail, etc.) is denied access.

> This plugin was designed and **validated end-to-end** in a real GLPI environment (Docker): assignment → detection → PDF generation → e-mail → login → review → signature → attachment — every step was tested with real requests against a GLPI instance, including access-denial cases.

## What sets it apart

- **Each asset's complete life passport**, not just today's handover: a new **Asset Passport** tab automatically rebuilds — retroactively too, on an already-existing fleet, with no re-entry — the timeline of everything that happened to it (handovers, returns, donations, sales, maintenance, purchase, warranty, linked tickets), with a **health score out of 100** computed from age, incidents, physical condition and the number of successive holders — weights you can tune yourself. The same view exists on the user side ("what has this person received since they joined?").
- **Zero cost, zero external dependency**: the on-screen signature is built in (native canvas, no third-party service, no subscription), and every signature produces real, viewable proof — signer, IP address, document SHA-256 fingerprint, timestamp.
- **Built for multi-site and multi-brand setups**: settings, logo, charter and terms and conditions are independent per entity with automatic inheritance — a single GLPI instance can serve several companies without them seeing each other.
- **Beyond the handover**: equipment donation and sale (beneficiary internal or external to the company), maintenance/preparation sheets with a fully configurable checklist, visual condition report with damage markers clickable directly on an equipment diagram.
- **Installs without breaking anything**: works immediately with your existing custom assets, respects native GLPI rights, no data duplicated beyond what the signature workflow genuinely requires.
- **Interface in 5 languages** (French, English, Spanish, German, Italian), detected automatically from the recipient's account language.

## Screenshots

**A handover record, technician side** — status, observations, visual condition report with damage markers, accessories handed over:

![Fiche de remise avec état des lieux visuel et accessoires](docs/screenshots/assetsign-fiche.png)

**The signature page, recipient side** — PDF review, visual condition report, free-text remark, on-screen signature:

![Page de signature côté bénéficiaire](docs/screenshots/sign-page.png)

All other screenshots (settings, dashboard, lists, tabs, dropdowns...) are in the [user guide](USER_GUIDE.md#screenshots).

## Documentation

| Document | For whom / for what |
|---|---|
| **[INSTALLATION.md](INSTALLATION.md)** | Prerequisites, installation, first-time setup, updating the plugin. |
| **[USER_GUIDE.md](USER_GUIDE.md)** | Day-to-day use: detailed behavior, full feature list, dashboard, FAQ. |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | For developers: code structure, subsystems, repository security, automated test suite. |
| **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** | Pitfalls encountered and non-obvious decisions made under real conditions. |
| **[CONTRIBUTING.md](CONTRIBUTING.md)** | Contribution workflow, checks before pushing, how to publish a release. |
| **[ROADMAP.md](ROADMAP.md)** | What's under consideration, not yet committed to a specific date. |
| **[SECURITY.md](SECURITY.md)** | How to report a vulnerability. |
| **[CHANGELOG.md](CHANGELOG.md)** | History of published releases. |

## License

[GPL-3.0-only](LICENSE), in line with GLPI plugin conventions.

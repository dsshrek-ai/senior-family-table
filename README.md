# The Senior Family Table

A multi-user family recipe book on MyDataWorld: recipes shared across several cookbooks without
copies, co-owned recipes, paste-text import with review, photos, serving/batch scaling, and
purpose-based shopping lists that work like the Senior Family Cookbook's — now shared and
synced between devices.

No meal planner, by design.

> **Status:** Live. Recipe library, scaling, shopping lists (Senior Family Cookbook style, shared
> and synced), family notes, tags, and Send as PDF are built and tested. Next: paste-text import,
> photos, ingredient catalog.

| File | What it is |
| ---- | ---------- |
| [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) | Application specification v1.0 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, permissions, shopping lists (§6), import, photos, backup |
| [docs/IMPORT-SENIOR-FAMILY.md](docs/IMPORT-SENIOR-FAMILY.md) | Plan for importing the Senior Family Cookbook recipes (not Living Lean) |
| [api/schema.sql](api/schema.sql) | `sft_` tables for the MyDataWorld database |
| [SETUP.md](SETUP.md) | How to put it online |
| [tests/](tests/README.md) | API and browser tests |

Stack: static HTML + vanilla JS on GitHub Pages; PHP 8 API at
`seniorfamily.org/senior-family-table-api/`; My Apps Hub sign-in (app key `senior-family-table`).

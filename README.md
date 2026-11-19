# Recipe Book

A multi-user recipe book on MyDataWorld: recipes shared across several cookbooks without
copies, co-owned recipes, paste-text import with review, photos, serving/batch scaling, and
purpose-based shopping lists that work like the Senior Family Cookbook's — now shared and
synced between devices.

No meal planner, by design.

> **Status:** Foundation phase (design). Nothing is implemented yet.

| File | What it is |
| ---- | ---------- |
| [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) | Application specification v1.0 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, permissions, shopping lists (§6), import, photos, backup |
| [api/schema.sql](api/schema.sql) | Draft `rb_` tables for the MyDataWorld database |

Stack: static HTML + vanilla JS on GitHub Pages; PHP 8 API at
`seniorfamily.org/recipe-book-api/`; My Apps Hub sign-in (app key `recipe-book`).

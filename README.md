# fisha.shop

Custom code for [fisha.shop](https://fisha.shop) — WordPress + WooCommerce on Hostinger.

## What lives here

| Path | What it is |
|---|---|
| `wp-content/mu-plugins/fisha-design.php` + `fisha-design/` | All Fisha design code: home river, mosaic, tattoos, contact, shop & category pages, cart persistence, legal pages |
| `docker/`, `docker-compose.yml` | Local development copy (see `docker/README.md`) |
| `.github/workflows/deploy.yml` | Deploys the design code to Hostinger on every push to `main` |

**Not in git:** WordPress core, third-party plugins, themes, `uploads/`, `wp-config.php`,
and database dumps (`docker/db/*.sql`). Content — pages, products, menus, forms, settings —
lives in the database and is edited in WP admin, not deployed from git.

## Daily workflow

```
# edit locally, check on http://localhost:8484, then:
git add -A
git commit -m "Describe the change"
git push            # -> GitHub Actions deploys to Hostinger (~30 s)
```

Watch the run under the repo's **Actions** tab. A PHP syntax error stops the deploy
before anything is uploaded. You can also re-run a deploy manually: Actions →
*Deploy to Hostinger* → **Run workflow**.

## One-time deploy setup

1. **Enable SSH** in Hostinger hPanel → *Advanced → SSH Access*. Note the IP, port
   (usually `65002`) and username (`u123456789`).
2. **Create a deploy key** on your PC (PowerShell):
   ```
   ssh-keygen -t ed25519 -f $HOME\.ssh\fisha_deploy -C "github-deploy" -N '""'
   ```
   Add the contents of `fisha_deploy.pub` in hPanel → SSH Access → *SSH keys*.
3. **Add GitHub secrets** — repo → *Settings → Secrets and variables → Actions → New repository secret*:

   | Secret | Example |
   |---|---|
   | `SSH_KEY` | full contents of `fisha_deploy` (the private key) |
   | `SSH_HOST` | `153.92.xx.xx` |
   | `SSH_PORT` | `65002` |
   | `SSH_USER` | `u123456789` |
   | `WP_PATH` | `/home/u123456789/domains/fisha.shop/public_html` |

Until the secrets exist the workflow only lints and skips the upload.

The deploy only touches `mu-plugins/fisha-design.php` and `mu-plugins/fisha-design/`
(minus `tools/`). Hostinger's own mu-plugins, plugins, themes and uploads on the server
are never modified.

## Moving content (database + uploads)

Git does not move content. Pages, products, menus, the contact form, category texts and
images in `wp-content/uploads/` are moved with a migration plugin (e.g. All-in-One WP
Migration or WPvivid) or by importing a SQL export and running a search-replace of
`http://localhost:8484` → `https://fisha.shop`.

After the site is live, never push a local database over the live one — it would wipe
real orders and customers. Copy the database **down** from live instead
(`docker/db/fisha.sql` + `docker/reset-db.ps1`).

# dev-stack

Runs this checkout as a local FacturaScripts instance: PHP 8.3 + Apache (with
`mod_rewrite`), MariaDB 11, composer dependencies, and the unattended installer.

## Use

```bash
dev-stack/install.sh
```

The script is idempotent. It generates `dev-stack/.env` on first run (database
password, root password, initial admin password, bind address) and never
overwrites an existing one. Re-running it rebuilds the image, keeps the database
volume and leaves an already-installed `config.php` alone.

Secrets live only in `dev-stack/.env`, which is git-ignored.

## Access

- Plain HTTP: `http://self0.tail7e037e.ts.net:8180` (the container publishes this)
- HTTPS: `https://self0.tail7e037e.ts.net:8443` — Tailscale Serve terminates TLS with
  a managed certificate and proxies to the container:

```bash
tailscale serve --bg --https=8443 http://<tailnet-ip>:8180
```

`install.sh` does not run this (it changes tailnet state, not the app). Disable with
`tailscale serve --https=8443 off`. The certificate renews via Tailscale, so no cron
job is needed — unlike mounting cert files into Apache, which would expire.

## Notes

- **Bind address.** `FS_BIND_ADDR` in `.env` controls where port 8180 is
  published. `install.sh` defaults it to the host's Tailscale IPv4, so the app is
  reachable on the tailnet and not on the LAN or loopback.
- **Apache runs as uid 1000.** The repository is bind-mounted; running Apache as
  `www-data` would leave `config.php`, `MyFiles/` and `Plugins/` unwritable or
  root-owned.
- **Builds use a host-network buildx builder.** With Tailscale DNS active,
  build containers cannot resolve `deb.debian.org` or `repo.packagist.org`, and
  buildx does not support `--dns`. See the `docker-tailscale-dns` skill.
- **Do not commit billing data.** Company records, NIFs, IBANs and client data of
  a live instance belong outside this repository (this fork is public).

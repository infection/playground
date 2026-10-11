# Infection Playground

This is a repository for Infection Playground, hosted here: https://infection-php.dev

### Infection - Mutation Testing Framework

Please read documentation here: [infection.github.io](http://infection.github.io)

* Twitter: [@infection_php][[twitter](http://twitter.com/infection_php)]
* Mastodon: [@infection_php][[mastodon](https://mastodon.social/@infection_php)]
* Discord: https://discord.gg/ZUmyHTJ

### Developing

The project is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker):
FrankenPHP (Caddy) and MySQL, PHP 8.5, Symfony 8. There is no Node.js: the frontend is served by
[AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html), Tailwind CSS is built by
[symfonycasts/tailwind-bundle](https://symfony.com/bundles/TailwindBundle/current/index.html) and the
Monaco Editor is taken from its npm package by the `Dockerfile`.

```bash
docker compose up --wait
```

and open https://localhost (accept the self-signed certificate).

The database is migrated when the container starts. To recreate the dev and test databases:

```bash
make docker-app-reinstall
```

Tests, static analysis and coding standards:

```bash
make docker-analyze
```

Rebuild the CSS while changing templates:

```bash
make docker-tailwind-watch
```

#### Infection and PHPUnit versions

Playground runs use the PHARs in `infection-builds/`, their versions are in `App\Infection\Runner`.
The PHP version is the one of the Docker image.

### Production

Production runs `compose.yaml` + `compose.prod.yaml` on the droplet. Caddy gets and renews the
Let's Encrypt certificate for `infection-php.dev` automatically (ports 80 and 443 must be reachable),
and keeps it in the `caddy_data` volume.

The deploy scripts read the settings from the git-ignored `secrets/` directory
(`app_secret`, `mysql_db_name`, `mysql_db_user`, `mysql_password`, `mysql_root_password`, `ssh_user`, `ssh_host`)
and talk to Docker on the droplet over SSH.

```bash
deploy/prod.sh
```

builds and pushes the image, then restarts the app; the container runs the Doctrine migrations on start.

```bash
deploy/backup.sh
```

dumps the production database to the git-ignored `backups/` directory. Nothing backs it up automatically.

#### Server requirements

- Docker Engine 20.10.10 or newer: the images are built on glibc 2.34+, whose threads need the `clone3` syscall,
  which the seccomp profile of older Docker versions rejects. The deploy scripts check this and refuse to run.
- Ports 80/tcp and 443/tcp (and 443/udp for HTTP/3) reachable from the internet. MySQL is not published.
- Some swap: the droplet has 2 GB of RAM for MySQL 8.4, FrankenPHP and the playground runs.

#### One-time switch from the legacy swarm stack

Until 2026 production ran a Docker swarm stack (nginx + php-fpm + MySQL 5.7, certificates from certbot).
`deploy/migrate-from-swarm.sh` moves it to this setup: it dumps the MySQL 5.7 database to `backups/`,
removes the swarm stack, loads the dump into MySQL 8.4 on a new volume, compares the row counts and
starts the app. The legacy volume `infection_db-data` is kept for a rollback.
Run it with `DRY_RUN=1` first: that only takes the dump and changes nothing.
The droplet ran Docker 19.03 at that time, so Docker has to be upgraded before the switch (see the requirements above).

# Infection Playground

This is a repository for Infection Playground, hosted here: https://infection-php.dev

### Infection - Mutation Testing Framework

Please read documentation here: [infection.github.io](http://infection.github.io)

Twitter: [@infection_php](http://twitter.com/infection_php)

### Developing

The project is fully dockerized, after cloning just run `docker compose up` and you are good to go.

Database can be setup up by `docker compose exec php make app-reinstall`.

### How to renew SSL certificate

Generate certificate

```bash
docker run -it --rm --name certbot \
-v "./secrets/certbot/www/:/var/www/certbot/" \
-v "./secrets/certbot/conf/:/etc/letsencrypt/" \
-v "./secrets/digitalocean-token.ini/:/secrets/digitalocean-token.ini" \
certbot/dns-digitalocean certonly --webroot --webroot-path /var/www/certbot/ --dry-run -d infection-php.dev --dns-digitalocean --dns-digitalocean-credentials /secrets/digitalocean-token.ini
```

Renew 

```bash
docker run -it --rm --name certbot \
-v "./secrets/certbot/www/:/var/www/certbot/" \
-v "./secrets/certbot/conf/:/etc/letsencrypt/" \
-v "./secrets/digitalocean-token.ini/:/secrets/digitalocean-token.ini" \
certbot/dns-digitalocean renew

cp secrets/certbot/conf/live/infection-php.dev/fullchain.pem secrets/bundle.crt
cp secrets/certbot/conf/live/infection-php.dev/privkey.pem secrets/infection-php_dev.key
```

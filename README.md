# Docker

## PHP

Build an image:

```bash
docker build -f "Dockerfile" -t infection-playground/php:latest .
```

## Test production mode locally

```bash
docker-compose -f docker-compose.prod.yml build

docker push maksrafalko/infection-playground-php:latest
docker push maksrafalko/infection-playground-nginx:latest

docker stack deploy -c docker-stack.yml infection --with-registry-auth
```

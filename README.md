# Peekchimp

Peekchimp is a Laravel application with an Inertia and Vue frontend. Its production Docker Compose stack includes the web application, Laravel Horizon, the Laravel scheduler, PostgreSQL, and Redis.

## Deploying to Coolify

### 1. Create the resource

1. In Coolify, create a new resource from the public Git repository.
2. Keep the branch set to `main` and the base directory set to `/`.
3. Select **Docker Compose** as the build pack. Do not select Dockerfile: the Compose file also starts PostgreSQL, Redis, Horizon, and the scheduler.
4. Continue to the resource configuration screen.

The included [`docker-compose.yml`](docker-compose.yml) creates these services:

| Service     | Purpose                     | Publicly exposed          |
| ----------- | --------------------------- | ------------------------- |
| `app`       | Laravel web application     | Through the Coolify proxy |
| `horizon`   | Redis queue workers         | No                        |
| `scheduler` | Laravel task scheduler      | No                        |
| `postgres`  | PostgreSQL database         | No                        |
| `redis`     | Cache, sessions, and queues | No                        |

You do not need to create separate PostgreSQL or Redis resources in Coolify when using this Compose setup.

### 2. Configure environment variables

Add the following variables to the Coolify resource:

```dotenv
APP_NAME=Peekchimp
APP_ENV=production
APP_KEY=base64:GENERATED_LARAVEL_KEY
APP_DEBUG=false
APP_URL=https://app.example.com

DB_DATABASE=peekchimp
DB_USERNAME=peekchimp
DB_PASSWORD=GENERATED_DATABASE_PASSWORD

REDIS_PASSWORD=GENERATED_REDIS_PASSWORD

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

VITE_APP_NAME=Peekchimp
```

Generate the application key locally and copy the complete output into `APP_KEY`:

```bash
php artisan key:generate --show
```

Generate the database and Redis passwords once:

```bash
openssl rand -hex 32
openssl rand -hex 32
```

Use one generated value for `DB_PASSWORD` and the other for `REDIS_PASSWORD`. Each password is entered only once in Coolify. Docker Compose passes `DB_PASSWORD` to both Laravel and PostgreSQL, and passes `REDIS_PASSWORD` to both Laravel and Redis. Store these values in a password manager.

Do not change `APP_KEY` after the application contains encrypted data. Do not simply change `DB_PASSWORD` after PostgreSQL has initialized; password rotation must also update the password inside PostgreSQL.

The Compose file supplies the internal connection settings automatically:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

Do not replace `postgres` or `redis` with `localhost`. Inside a container, `localhost` means that same container rather than another Compose service.

For registration, password resets, and other production email, also configure a real mail provider:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME=Peekchimp
```

Use the host, port, and any scheme or encryption settings supplied by the mail provider.

### 3. Connect a domain

At the DNS provider, create an `A` record that points to the Coolify server's public IPv4 address:

| Domain            | Record name | Record value        |
| ----------------- | ----------- | ------------------- |
| `app.example.com` | `app`       | Coolify server IPv4 |
| `example.com`     | `@`         | Coolify server IPv4 |

Only add an `AAAA` record if IPv6 is correctly configured on the server. Confirm that DNS resolves to the server:

```bash
dig +short app.example.com
```

In Coolify:

1. Open the Peekchimp Compose resource.
2. Select the `app` service. Do not assign a domain to PostgreSQL, Redis, Horizon, or the scheduler.
3. Set the domain to `https://app.example.com:8000`.
4. Set `APP_URL=https://app.example.com` without `:8000`.
5. Save and redeploy.

The `:8000` in the Coolify domain tells its proxy which internal container port receives traffic. Visitors still use `https://app.example.com` without a port. The server firewall must allow inbound TCP ports `80` and `443`; do not publicly open ports `8000`, `5432`, or `6379`.

When using Cloudflare, starting with the record set to **DNS only** makes initial certificate setup easier. After HTTPS works, the Cloudflare proxy can be enabled with SSL/TLS mode set to **Full (strict)**. Do not use Flexible mode.

See the official Coolify documentation for [DNS configuration](https://coolify.io/docs/knowledge-base/dns-configuration), [domains](https://coolify.io/docs/knowledge-base/domains), and [Docker Compose deployments](https://coolify.io/docs/knowledge-base/docker/compose).

### 4. Deploy and verify

Deploy the resource. The `app` service waits for PostgreSQL and Redis to become healthy, runs the database migrations, and then starts the web server. Verify:

- Coolify reports `app`, `postgres`, and `redis` as healthy.
- `horizon` and `scheduler` are running.
- `https://app.example.com/up` returns a successful response.
- The application loads at `https://app.example.com`.

If deployment fails, check the logs for the specific Compose service rather than only the general deployment log.

### Database and Redis persistence

PostgreSQL and Redis use the named volumes `postgres-data` and `redis-data`. Normal application deployments and container recreations retain those volumes, so data is not wiped on every deploy.

Data can still be lost if the volumes or the entire Coolify resource are deleted, a command such as `docker compose down --volumes` is run, or the server disk fails. Configure regular off-server database backups before storing production data.

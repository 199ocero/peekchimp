---
paths:
  - '**'
  - 'Dockerfile,docker-compose.yaml'
---

# General

## Run full checks before commit or push
Before creating a commit or pushing changes, run the full project CI check with `composer ci:check`. Only commit or push when every check passes.

## Initialize GeoIP data in the persistent runtime volume
Do not download the DB-IP database during the Docker image build: the runtime geoip-data mount hides image contents and slow downloads make builds fail. Let the scheduler container initialize an empty shared volume, then use Laravel's weekly analytics:geoip:update schedule.

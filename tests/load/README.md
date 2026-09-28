# Load & database performance testing

Everything here runs against a **separate** database, `capstone_loadtest`,
never the real one. The seeder and the session command both refuse to run on
a database whose name doesn't contain `loadtest`.

## 1. Build the test database (1,000 students)

```bash
mysql -u root -e "CREATE DATABASE capstone_loadtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_DATABASE=capstone_loadtest php artisan migrate --force
DB_DATABASE=capstone_loadtest php artisan db:seed --class=AdminSeeder --force
DB_DATABASE=capstone_loadtest LOADTEST_STUDENTS=1000 php artisan db:seed --class=LoadTestSeeder --force
```

Change `LOADTEST_STUDENTS` for bigger runs (e.g. 5000). Seeded parents' password: `password`.

## 2. Create logged-in sessions for the load tool

```bash
DB_DATABASE=capstone_loadtest php artisan loadtest:sessions --parents=50
```

Writes `storage/app/loadtest-cookies.json` (git-ignored).

## 3. Point the web server at the test database

Apache reads `.env`, so for the duration of the test:

1. Back up `.env`, then set `DB_DATABASE=capstone_loadtest`.
2. Run `php artisan config:cache`. **Required on Windows XAMPP**: Apache runs
   PHP in threads there, and reading `.env` on every request is not
   thread-safe — without the cache, some requests randomly lose their
   settings under load ("No application encryption key", "Unknown database
   'laravel'"). Railway already caches config at startup.
3. **Afterwards:** restore `.env` and run `php artisan config:clear`.

## 4. Run the load test

Quick single-page test with Apache Bench (ships with XAMPP):

```bash
C:/xampp/apache/bin/ab.exe -k -n 1000 -c 50 -C "<cookie from the json>" -H "Accept: application/json" "http://localhost/capstone-name/public/tuition?enrollment_id=<id>"
```

Realistic mixed test (50→100 parents + 5 admins, 3 minutes) with k6 via Docker Desktop:

```bash
docker run --rm -i -v "%cd%:/app" -w /app grafana/k6 run -e BASE=http://host.docker.internal/capstone-name/public tests/load/k6-mixed.js
```

Use `-k` (keep-alive) with ab: without it, a Windows laptop running the load
tool, Apache and MySQL together runs out of network ports after ~20–30 s at
several hundred requests/second ("Only one usage of each socket address") —
a limit of the test machine, not the app.

## 5. Find slow queries

Count queries per page by wrapping a request in `DB::enableQueryLog()` (the
numbers in the performance report were measured this way), and use MySQL's
slow query log plus `EXPLAIN` for anything slow:

```sql
SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 0.1;
```

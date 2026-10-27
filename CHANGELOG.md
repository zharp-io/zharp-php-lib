# Changelog

## v0.1.1

- A rejected API key (HTTP 401/403) stops sending for an hour with one warning — across requests, remembered in a temp file — instead of every request sending to a dead key; sending resumes, with a notice, once the key is accepted again.

## v0.1.0

- First release: `Zharp::init()` and `debug`/`info`/`warn`/`error`/`fatal` (and the PSR-3 names), from options or `ZHARP_*` environment variables.
- Exceptions carry their class and full stack, previous ones included; PSR-3 `exception` context.
- Sent when the request ends, after the response under PHP-FPM and LiteSpeed; periodically in long-running processes.
- Uncaught exceptions and fatal errors captured in plain PHP.
- `ZharpHandler` for Monolog 2 and 3.
- Laravel: package discovery, a `zharp` log channel, `php artisan zharp:test`, flushing after jobs and Octane requests.

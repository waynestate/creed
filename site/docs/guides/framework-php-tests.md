---
title: "Our framework tests | CREED docs"
description: "The framework x PHP matrix CREED runs itself: each Laravel, CakePHP and Slim major's official skeleton, created, tested and served on every PHP line its newest release allows, with the results in data/framework-php-tests.json."
url: https://creed.apps.wayne.edu/docs/guides/framework-php-tests.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Our framework tests [#](https://creed.apps.wayne.edu/docs/guides/framework-php-tests.html#our-framework-tests)

A `require.php` such as `^8.3` lets Composer install a framework on PHP lines nobody has tried yet. So CREED runs the frameworks itself. For each framework major (Laravel 13 and 12, CakePHP 5 and 4, Slim 4 and 3) and each PHP line from 8.0 to 8.6 that the major's newest release allows, a fresh container on that line:

1. creates the major's **official skeleton** at its newest release with `composer create-project` (`laravel/laravel`, `cakephp/app`, `slim/slim-skeleton`), never with `--ignore-platform-reqs`, so a refusal is a result;
2. runs the skeleton's own test suite with every deprecation, warning and notice shown;
3. serves it with PHP's built-in server and asks for `/`;
4. counts the deprecations and warnings PHP printed along the way, wherever the framework sent them (stderr, the page itself, PHPUnit's issue list, Laravel's deprecations log channel, CakePHP's error renderer), and keeps the first three distinct messages;
5. records the PHP patch, the framework and skeleton versions, the image digest and the date.

A line the newest release's `require.php` excludes is not run: Composer would refuse it before it started.

Each cell reads one of:

| Result | Meaning |
| --- | --- |
| **pass** (P) | installed, tests passed, `/` answered 200, and PHP said nothing |
| **pass-deprecations** (D) | it works, but PHP printed deprecations or warnings; the footnote lists the first ones |
| **fail** (F) | a step failed: the footnote names the step and its first error line |
| **refused** (R) | Composer refused the PHP platform when it resolved the skeleton's dependencies |
| **not run** (-) | the release's `require.php` excludes the line |

This is half of what the [live PHP page](https://creed.apps.wayne.edu/docs/guides/live-php.html) calls supported. The other half is what the framework's maintainers have announced; a line we passed but they have not announced is not supported yet.

## The results [#](https://creed.apps.wayne.edu/docs/guides/framework-php-tests.html#the-results)

Results from 2026-11-20.

| Framework | PHP 8.0 | PHP 8.1 | PHP 8.2 | PHP 8.3 | PHP 8.4 | PHP 8.5 | PHP 8.6 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Laravel 13 (13.43.0) | - \[1\] | - \[2\] | - \[3\] | P | P | P | P |
| Laravel 12 (12.73.0) | - \[4\] | - \[5\] | P | P | P | P | F \[6\] |
| CakePHP 5 (5.4.4) | - \[7\] | - \[8\] | P | P | P | P | P |
| CakePHP 4 (4.6.6) | R \[9\] | P | P | P | R \[10\] | R \[11\] | R \[12\] |
| Slim 4 (4.15.3) | P | P | P | P | D \[13\] | D \[14\] | - \[15\] |
| Slim 3 (3.13.1) | - \[16\] | P | P | P | P | P | D \[17\] |

P: pass. D: pass with deprecations or warnings. F: fail. R: Composer refused the PHP platform. -: the release's `require.php` excludes the line, so it was not run. ?: not run yet, or no official image.

1. Laravel 13 on PHP 8.0: not run; laravel/framework 13.43.0 requires php `^8.3`.
2. Laravel 13 on PHP 8.1: not run; laravel/framework 13.43.0 requires php `^8.3`.
3. Laravel 13 on PHP 8.2: not run; laravel/framework 13.43.0 requires php `^8.3`.
4. Laravel 12 on PHP 8.0: not run; laravel/framework 12.73.0 requires php `^8.2`.
5. Laravel 12 on PHP 8.1: not run; laravel/framework 12.73.0 requires php `^8.2`.
6. Laravel 12 on PHP 8.6 (PHP 8.6.0, laravel/framework 12.73.0, laravel/laravel 12.12.2): failed at tests. 2 tests, 1 failure, 0 errors: Failed asserting that 500 is identical to 200. First: "Function spl_object_hash() is deprecated since 8.6, consider using spl_object_id() instead" (vendor/laravel/framework/src/Illuminate/Container/Container.php:1112, install); "Function mb_split() is deprecated since 8.6, because the underlying library is no longer maintained" (vendor/laravel/framework/src/Illuminate/Support/Str.php:1728, install); "Since symfony/http-foundation 7.4: Trying to use "Illuminate\\Http\\Response::sendHeaders()" after headers have already been sent is deprecated and will throw a PHP warning in 8.0. Use a "StreamedResponse" instead." (vendor/symfony/deprecation-contracts/function.php:25, request).
7. CakePHP 5 on PHP 8.0: not run; cakephp/cakephp 5.4.4 requires php `>=8.2`.
8. CakePHP 5 on PHP 8.1: not run; cakephp/cakephp 5.4.4 requires php `>=8.2`.
9. CakePHP 4 on PHP 8.0 (PHP 8.0.30, cakephp/app 4.5.0): Composer refused the platform. - twig/markdown-extra\[v3.26.0, ..., 3.x-dev\] require php >=8.1.0 -> your php version (8.0.30) does not satisfy that requirement.
10. CakePHP 4 on PHP 8.4 (PHP 8.4.28, cakephp/app 4.5.0): Composer refused the platform. - laminas/laminas-diactoros\[2.26.0, ..., 2.26.x-dev\] require php ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 -> your php version (8.4.28) does not satisfy that requirement.
11. CakePHP 4 on PHP 8.5 (PHP 8.5.13, cakephp/app 4.5.0): Composer refused the platform. - laminas/laminas-diactoros\[2.26.0, ..., 2.26.x-dev\] require php ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 -> your php version (8.5.13) does not satisfy that requirement.
12. CakePHP 4 on PHP 8.6 (PHP 8.6.0, cakephp/app 4.5.0): Composer refused the platform. - laminas/laminas-diactoros\[2.26.0, ..., 2.26.x-dev\] require php ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 -> your php version (8.6.0) does not satisfy that requirement.
13. Slim 4 on PHP 8.4 (PHP 8.4.28, slim/slim 4.15.3, slim/slim-skeleton 4.5.0): works, with 36 deprecations. First: "DI\\create(): Implicitly marking parameter $className as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/functions.php:35, tests); "DI\\autowire(): Implicitly marking parameter $className as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/functions.php:48, tests); "DI\\ContainerBuilder::writeProxiesToFile(): Implicitly marking parameter $proxyDirectory as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/ContainerBuilder.php:283, tests).
14. Slim 4 on PHP 8.5 (PHP 8.5.13, slim/slim 4.15.3, slim/slim-skeleton 4.5.0): works, with 36 deprecations. First: "DI\\create(): Implicitly marking parameter $className as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/functions.php:35, tests); "DI\\autowire(): Implicitly marking parameter $className as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/functions.php:48, tests); "DI\\ContainerBuilder::writeProxiesToFile(): Implicitly marking parameter $proxyDirectory as nullable is deprecated, the explicit nullable type must be used instead" (vendor/php-di/php-di/src/ContainerBuilder.php:283, tests).
15. Slim 4 on PHP 8.6: not run; slim/slim 4.15.3 requires php `~7.4.0 || ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`.
16. Slim 3 on PHP 8.0: not run; slim/slim 3.13.1 requires php `^8.1`.
17. Slim 3 on PHP 8.6 (PHP 8.6.0, slim/slim 3.13.1, slim/slim-skeleton 3.1.8): works, with 2 deprecations. First: "Function is_integer() is deprecated since 8.6, use is_int() instead" (vendor/slim/slim/Slim/Http/Response.php:223, tests).

| PHP line | Patch | Image |
| --- | --- | --- |
| 8.0 | 8.0.30 | `php:8.0-cli@sha256:0569e384b9064c04dec55dc6e41be41b494a878dfbb6577a7d76bd50cfd5bc00` |
| 8.1 | 8.1.34 | `php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087` |
| 8.2 | 8.2.36 | `php:8.2-cli@sha256:842748bc4526d4c3c56501fd314e2d75d1005e8a45b7a40f9768ee820df0a6d6` |
| 8.3 | 8.3.37 | `php:8.3-cli@sha256:f14261a8cdb7f28b2ec45798c256b744c3ab6a6dd8598206b028c1efb7f76d15` |
| 8.4 | 8.4.28 | `php:8.4-cli@sha256:6b13a8678b38ca38e4e3950be57b664ed8c8706131a0586111f68792aede4c58` |
| 8.5 | 8.5.13 | `php:8.5-cli@sha256:f324fcb4dc009ac0470a7d614cd122499dcee3ca47a9bab620fc14c0eb759ba2` |
| 8.6 | 8.6.0 | `php:8.6-cli@sha256:d84acf3155d907afaa5afa9b9457c5f12016b6dc7ade84659f40cdc505cc087d` |

The same results, cell by cell, with every count, version and first error, are in [`data/framework-php-tests.json`](https://git.wayne.edu/enterprise-apps/creed/-/blob/main/data/framework-php-tests.json).

## Running it again [#](https://creed.apps.wayne.edu/docs/guides/framework-php-tests.html#running-it-again)

The matrix needs Docker and nothing else on the computer.

```powershell
scripts\framework-matrix.ps1                      # every cell (Windows, Docker Desktop)
scripts\framework-matrix.ps1 -Php 8.6 -Framework laravel-13
```

```sh
scripts/framework-matrix.sh                       # every cell (Linux, macOS)
scripts/framework-matrix.sh --php 8.6 --framework laravel-13
```

The script builds one image per PHP line (`creed-matrix-php<line>`) from the official `php:<line>-cli` image, pinned by the digest it just pulled, with the extensions the skeletons need (intl, mbstring, zip, pdo_mysql) and an ini that shows everything PHP says. When a line has no release image yet it uses `php:<line>-rc-cli` and marks the cells prerelease; with neither, the cells stay "no image". It plans the cells from Packagist (`php bin/framework-matrix.php plan`), runs each one alone in a container named `creed-matrix-*` (`bin/framework-matrix-cell.php`), writes the report (`php bin/framework-matrix.php report`), and removes the images. A partial run replaces only the cells it ran.

| Variable | What it does |
| --- | --- |
| `CREED_MATRIX_COMPOSER_CACHE` | the Composer cache volume every cell mounts (default `stack-composer-cache`) |
| `CREED_MATRIX_LOCK` | a lock file shared with other container jobs on the same computer: created holding the script's pid before every `docker run` or `docker build` and deleted after; a lock whose pid is gone is cleared |
| `CREED_MATRIX_MAX_COMMIT` | Windows only: hold before each container while the commit charge is at or above this percent (default 72; 0 turns it off) |

The frameworks, their skeletons and the PHP lines are configuration in `tools/FrameworkMatrix/frameworks.json`: a new major is one entry there. A full run of the 33 cells took about five minutes on 2026-11-20 with a warm Composer cache and the base images already pulled; pulling and building the seven images from cold adds a few minutes more. Logs for every step land in `build/framework-matrix/logs/`, which is not committed.

Like Creed Bratton's references, every claim on this page can be checked; unlike his, the checks come back.

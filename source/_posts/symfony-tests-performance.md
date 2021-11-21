---
extends: _layouts.post
section: content
title: Improve Symfony Tests Performance
date: 2021-11-21
categories: [php, symfony, tests]
description: Improve performance of tests suite for Symfony Application.
cover_image: /assets/img/symfony-tests-performance.png
---

* [Using more simple password hashers](#using-more-simple-password-hasher)
* [Do not use Doctrine logging by default](#do-not-use-doctrine-logging-by-default)
* [Set `APP_DEBUG` to `false`](#set-app-debug-false)
* [Completely disable Xdebug](#completely-disable-xdebug)
* [Parallel tests execution using Paratest](#parallel-tests-execution-using-paratest)
* [Collect coverage with `pcov` if possible](#collect-coverage-with-pcov-if-possible)
* [Collect coverage with `cacheDirectory`](#collect-coverage-with-cache-directory)

For all the latest Symfony projects at my company we were writing unit and mostly functional tests, occasionally improving their performance, but didn't have a chance to summarize all the improvements we made to speed up the test suite.

In this article, I will show the most comprehensive list of tips and tricks to decrease tests time, resource consumption and improve their speed.

First, let's start with our baseline for one of the projects.

* `2285` - the total number of tests
* `979` unit tests
* `1306` functional tests (Symfony's `WebTestCase`, testing API endpoints)
* Symfony 5.3, PHP 8.1

The whole test suite *before* optimizations takes: `Time: 12:25.512, Memory: 551.01 MB`.

Let's see what we can do here.

<a name="using-more-simple-password-hasher"></a>
## Using more simple password hasher

Password hashers are used in Symfony to hash the raw password during persisting the User to database and to verify password validity. For production, we have to use [more reliable](https://symfony.com/doc/current/security/passwords.html#supported-algorithms) hashing algorithms which are quite slow by their nature (Argon2, bcrypt, etc.).

While checking 1 password during login is not a big deal, imaging hashing passwords thousands of times during tests execution. This becomes a bottleneck.

Instead of using mentioned hashing algorithms, we can use `md5` for `test` environment and increase the speed of the test suite.

```yaml
# config/packages/security.yaml for dev & prod env
security:
    password_hashers:
      App\Entity\User\User:
        algorithm: argon2i


# override in config/packages/test/security.yaml for test env
security:
    password_hashers:
        App\Entity\User\User:
            algorithm: md5
            encode_as_base64: false
            iterations: 0
```

Let's run `phpunit` again and check the results:

```bash
vendor/bin/phpunit

...

Time: 05:32.496, Memory: 551.00 MB
```

What an improvement!

```diff
- Time: 12:25.512, Memory: 551.01 MB
+ Time: 05:32.496, Memory: 551.00 MB
```

It is 2.25x faster than it was before just by changing hashing function. This is one of the most valuable performance optimization that can be done in minutes, and, to be honest, I don't know why it isn't forced by big players like API-Platform or Symfony itself in their distributions. Let's try to change that and help other developers to now waste time: https://github.com/api-platform/docs/pull/1472.

<a name="do-not-use-doctrine-logging-by-default"></a>
## Do not use Doctrine logging by default

After a couple of years working with the test suite with disabled Doctrine logging, we didn't experience any inconveniences. When there is an error thrown, stack trace will have a failed SQL query in the log/output anyway. So for tests execution, there is no really a need to log SQL queries to the log file, as in most cases you will need them only in case of errors, which already works as mentioned above.

Let's disable doctrine logging for the `test` environment:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                logging: false
```

Run the tests again and compare with the previous results:

```diff
- Time: 05:32.496, Memory: 551.00 MB
+ Time: 04:13.959, Memory: 547.01 MB
```

Such an easy change and another minute is gone. This improvement highly depends on how you use the (monolog) logger for `test` environment. General tip: do not log too much for tests. For example, setting log level `debug` is not necessary, and for tests you [can use](https://github.com/api-platform/api-platform/blob/ac68010c818bde422b97a7044b8df04176e970a4/api/config/packages/test/monolog.yaml#L5) production-like configuration - `fingercrossed` handler with `action: error`.

<a name="set-app-debug-false"></a>
## Set `APP_DEBUG` to `false`

It was proposed [back in 2019](https://github.com/symfony/recipes/pull/530) by **@javiereguiluz**, but wasn't merged in. Though, now Symfony's documentation mentions this improvement in a ["Set-up your Test Environment"](https://symfony.com/doc/current/testing.html#set-up-your-test-environment) paragraph:

> It is recommended to run your test with `debug` set to `false` on your CI server, as it significantly improves test performance.

To disable debug mode, add the following line to your `phpunit.xml` file:

```xml
<?xml version="1.0" encoding="UTF-8"?>

<phpunit >
    <php>
        <!--  ..... -->
        <server name="APP_DEBUG" value="false" />
    </php>
</phpunit>

```

Disabling `debug` mode also disables clearing the cache. And if your tests don't run in a clean environment each time (for example tests are executed locally, where you always change the source files), you have to manually clear the cache each time `PHPUnit` is executed. 

This is how it looks like on our project inside `PHPUnit`'s `bootstrap` file:

```php
<?php

use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

// ...

(new Filesystem())->remove([__DIR__ . '/../var/cache/test']);

echo "\nTest cache cleared\n";
```

We can live with this "inconvenience", especially with the benefit it gets. Ready to see the results?

```diff
- Time: 04:13.959, Memory: 547.01 MB
+ Time: 02:45.307, Memory: 473.00 MB
```

Besides the speed, there is one more (I think major) benefit of using `APP_DEBUG=false`. Functional tests start responding with `Internal Server Error` rather than with an exception message thrown from the source code.

This can be a dealbreaker in Symfony projects. I saw a couple of projects, where people used the following code:

```php
# App\Controller\SomeController.php

throw new ConflictHttpException('There is a conflict between X and Y');
```

asserting in tests that response contains exactly this exception message `There is a conflict between X and Y` in functional tests when `APP_DEBUG=true`, while in fact the response message is `The server returned a "409 Conflict".` with `APP_DEBUG=false`, and test start failing after using `APP_DEBUG=false`.

Using `APP_DEBUG=false` with functional tests is a *right way* from `5xx` errors points of view and this mimics a real production environment.

<a name="completely-disable-xdebug"></a>
## Completely disable Xdebug

Many of us install `Xdebug` for debugging purposes, adding it to the base development docker images or right to the local machine. If you use `pcov` to collect a coverage, `Xdebug` can still impact a performance of the test suite, even if you use `xdebug.mode=debug` but not `xdebug.mode=coverage`.

So make sure to completely disable `Xdebug` before running your tests:

```bash
XDEBUG_MODE=off vendor/bin/phpunit
```

For our project, we managed to get a great performance boost by applying this approach on development environment:

```diff
- Time: 02:45.307, Memory: 473.00 MB
+ Time: 01:47.368, Memory: 449.00 MB
```

Moreover, we did the same for many other commands in our `Makefile`, for example:

```bash
# Makefile
DISABLE_XDEBUG=XDEBUG_MODE=off

app-reinstall: prerequisites ## Setup application database with fixtures
	$(DISABLE_XDEBUG) bin/console doctrine:database:drop --force --if-exists
	$(DISABLE_XDEBUG) bin/console doctrine:database:create
	$(DISABLE_XDEBUG) bin/console doctrine:schema:update --force
	$(DISABLE_XDEBUG) bin/console doctrine:fixtures:load -n
```

**Note:** There are a number of OSS tools that use [`composer/xdebug-handler`](https://github.com/composer/xdebug-handler) that can *automatically* disable `Xdebug` and re-run the process. From my point of view - this is very convenient and it should be used if possible for such tools as PHP Magic Detector, PHP-CS-Fixer, etc. Basically, for static analysis tools.

> Do not use `Xdebug` for collecting code coverage unless you need a [`path`/`branch` coverage](https://doug.codes/php-code-coverage#branch-coverage). Use `pcov` instead (explained below)

<a name="parallel-tests-execution-using-paratest"></a>
## Parallel tests execution using Paratest

Every good tool has an option to be executed in parallel (to name a few: `Psalm`, `PHPStan`, `Infection`). To get all the power from multicore processor of your local machine or CI server, make sure to run your tests in parallel as well.

Personally, I recommend using [`Paratest`](https://github.com/paratestphp/paratest). It is a wrapper for `PHPUnit` that just works, even code coverage can be collected and combined from different threads.

If you use DB for your functional tests, you will have to set up as many DB schemas as threads you want to use in `Paratest`. This library [exposes](https://github.com/paratestphp/paratest#test-token) a `TEST_TOKEN=<int>` environment variable that can be used to determine what DB connection to use.

Imaging you run your tests with 4 threads, so you need 4 DB schemas and 4 different DB connections:

```bash
vendor/bin/paratest --processes=4 --runner=WrapperRunner
```

To configure Doctrine to use different connections, the following config can be used:

```yaml
# config/packages/test/doctrine.yaml

parameters:
    test_token: 1

doctrine:
    dbal:
        dbname: 'db_%env(default:test_token:TEST_TOKEN)%'
```

In this case, depending on `TEST_TOKEN` variable, `PHPUnit` will run an application connected to different databases: `db_1`, `db_2`, `db_3`, `db_4`. 

Why is it needed? Because tests, executed simultaneously for the same DB, can break each other: they can rewrite or remove the same data, transactions can be time outed or locked. Thus, running tests in isolation - when each thread uses its own DB - fixes this issue. 

Running a test suite with 4 threads for our project gives the following performance boost:

```diff
- Time: 01:47.368, Memory: 449.00 MB
+ Time: 00:34.256, Memory: 40.00 MB
```

Remember, we started with `Time: 12:25.512, Memory: 551.01 MB`? 

After all the changes, it's `Time: 00:34.256, Memory: 40.00 MB`! This is **21x faster** than it was in the beginning.

<a name="collect-coverage-with-pcov-if-possible"></a>
## Collect coverage with `pcov` if possible

Now, let's see how we can improve the speed of the test suite when we collect coverage data. To make it more visible, let's step back and run our test suite without `Paratest`, using 1 thread in `PHPUnit` with `Xdebug` and then `pcov` as a coverage driver.

```diff
- Time: 03:49.987, Memory: 575.00 MB # Xdebug
+ Time: 02:13.209, Memory: 519.01 MB # pcov
```

As we can see, for this particular case `pcov` is 1.72x faster than `Xdebug`. Depending on your project, you can get even better results (e.g. [5x times faster](https://dev.to/swashata/setup-php-pcov-for-5-times-faster-phpunit-code-coverage-3d9c))

`pcov` [has a comparable accuracy](https://github.com/krakjoe/pcov#differences-in-reporting) in coverage reports with `Xdebug`, so this should be a great choice unless you need a path/branch coverage (not supported by `pcov`).

<a name="collect-coverage-with-cache-directory"></a>
## Collect coverage with `cacheDirectory`

As [suggested](https://github.com/paratestphp/paratest#generating-code-coverage) in the Paratest repository:

> Beginning from `PHPUnit` 9.3.4, it is strongly advised to set a coverage cache directory, see [PHPUnit Changelog @ 9.3.4](https://github.com/sebastianbergmann/phpunit/blob/9.3.4/ChangeLog-9.3.md#934---2020-08-10).

Before doing this update, let's see how much time does it take to run `PHPUnit` with collecting coverage metrics:

```bash
XDEBUG_MODE=off vendor/bin/paratest -p4 --runner=WrapperRunner --coverage-clover=reports/coverage.xml --coverage-html=reports

...

Time: 01:02.904, Memory: 478.93 MB
Generating code coverage report ... done [00:10.796]
```

Total time with code coverage reports generating is 1m 13s.

Now, let's add a `cacheDirectory` in `phpunit.xml` file:


```diff
- <coverage>
+ <coverage cacheDirectory=".coverage-cache">
```

and run `PHPUnit` with collecting code coverage again. Here are the results:

```diff
- Time: 01:02.904, Memory: 478.93 MB
- Generating code coverage report ... done [00:10.796]
+ Time: 00:43.759, Memory: 475.70 MB
+ Generating code coverage report ... done [00:05.394]
```

Nice, much faster now. On a real big tests suite, we were able to decrease the time from 11 minutes to 5 minutes on CI thanks to `cacheDirectory` setting. 

> Read more about how it works under the hood in a post by Sebastian Bergmann: [https://thephp.cc/articles/caching-makes-everything-faster-right](https://thephp.cc/articles/caching-makes-everything-faster-right)

---

Credits & related articles:

* [https://titouangalopin.com/posts/60edL3P43zwG6uGUiIlvPL/tips-for-a-reliable-and-fast-test-suite-with-symfony-and-doctrine](https://titouangalopin.com/posts/60edL3P43zwG6uGUiIlvPL/tips-for-a-reliable-and-fast-test-suite-with-symfony-and-doctrine)
* [https://codewave.eu/blog/how-to-reduce-time-symfony-integration-tests](https://codewave.eu/blog/how-to-reduce-time-symfony-integration-tests)
* [https://habr.com/ru/post/505736/](https://habr.com/ru/post/505736/)

<p class="my-12 text-center">
    <b>Find this interesting?</b> Let's continue the conversation on <a href="https://twitter.com/maks_rafalko" rel="nofollow">Twitter</a>.
</p>

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
* [Miscellaneous](#miscellaneous)
  * [Use `dama/doctrine-test-bundle` to rollback transaction after each test](#dama-doctrine-test-bundle)
  * [Combine functional & unit tests. Prefer Unit tests](#prefer-unit-tests)

For all the latest Symfony projects at my company we were writing unit and mostly functional tests, occasionally improving their performance, but didn't have a chance to summarize all the improvements we made to speed up the test suite.

In this article, I will show the most comprehensive list of tips and tricks to decrease tests time, resource consumption and improve their speed.

First, let's start with our baseline for one of the projects.

* `2285` - the total number of tests
* `979` unit tests
* `1306` functional tests (Symfony's `WebTestCase`, testing API endpoints)
* Symfony 5.3, PHP 8.1

The whole test suite *before* optimizations takes: `Time: 12:25.512, Memory: 551.01 MB`.

Why having a fast and reliable tests suite is important? There a lot of reasons, but 2 main are:

1. The more tests suite takes to be executed, the more annoying it is for a developer
2. The more resources (CPU, Memory) tests suite takes, the worse it is for CI server (it can slow down other jobs/builds) and eventually for our Planet

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
      App\Entity\User:
        algorithm: argon2i


# override in config/packages/test/security.yaml for test env
security:
    password_hashers:
        App\Entity\User:
            algorithm: md5
            encode_as_base64: false
            iterations: 0
```

Let's run `phpunit` again and check the results:

```bash
vendor/bin/phpunit

# ...

Time: 05:32.496, Memory: 551.00 MB
```

What an improvement!

```diff
- Time: 12:25.512, Memory: 551.01 MB
+ Time: 05:32.496, Memory: 551.00 MB
```

It is 2.25x faster than it was before just by changing hashing function. This is one of the most valuable performance optimization that can be done in minutes, and, to be honest, I don't know why it isn't forced by big players like API-Platform or Symfony itself in their distributions. Let's try to change that and help other developers to not waste time: [api-platform/docs#1472](https://github.com/api-platform/docs/pull/1472), [symfony/recipes#1024](https://github.com/symfony/recipes/issues/1024).

<a name="do-not-use-doctrine-logging-by-default"></a>
## Do not use Doctrine logging by default

After a couple of years working with the test suite with disabled Doctrine logging, we didn't experience any inconveniences. When there is an error thrown, stack trace will have a failed SQL query in the log/output anyway. So for tests execution, there is no really a need to log SQL queries to the log file, as in most cases you will need them only in case of errors, which already works as mentioned above.

Let's disable doctrine logging for the `test` environment:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
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

It was proposed [back in 2019](https://github.com/symfony/recipes/pull/530) by **@javiereguiluz**, but didn't get enough popularity. Though, now Symfony's documentation mentions this improvement in a ["Set-up your Test Environment"](https://symfony.com/doc/current/testing.html#set-up-your-test-environment) paragraph:

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

Using `APP_DEBUG=false` with functional tests is a *right way* from errors/exceptions points of view and this mimics a real production environment.

Again, to save developers' time, let's try to change API-Platform distribution and Symfony's `phpunit-bridge` recipe and add this behavior by default: [api-platform/api-platform#2078](https://github.com/api-platform/api-platform/pull/2078), [symfony/recipes#1025](https://github.com/symfony/recipes/issues/1025)

<a name="completely-disable-xdebug"></a>
## Completely disable Xdebug

Many of us install `Xdebug` for debugging purposes, adding it to the base development docker images or right to the local machine. If you use `pcov` to collect a coverage or _even if you don't collect coverage at all_, `Xdebug` can still impact a performance of the test suite, even if you use `xdebug.mode=debug` but not `xdebug.mode=coverage`.

So make sure to completely disable `Xdebug` before running your tests:

```bash
XDEBUG_MODE=off vendor/bin/phpunit
```

For our project, we managed to get a great performance boost by applying this approach on development environment:

```diff
- Time: 02:45.307, Memory: 473.00 MB
+ Time: 01:47.368, Memory: 449.00 MB
```

> There is no need to install `Xdebug` on CI if you collect coverage with `pcov`, so in our case CI was not affected.

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

Do you remember we started with `Time: 12:25.512, Memory: 551.01 MB`? 

After all the changes, it's `Time: 00:34.256, Memory: 40.00 MB`! This is **21x faster** than it was in the beginning.

<a name="collect-coverage-with-pcov-if-possible"></a>
## Collect coverage with `pcov` if possible

Now, let's see how we can improve the speed of the test suite when we collect coverage data. To make it more visible, let's step back and run our test suite without `Paratest`, using 1 thread in `PHPUnit` with `Xdebug` and then `pcov` as a coverage driver.

```diff
- Time: 03:49.987, Memory: 575.00 MB # Xdebug
+ Time: 02:13.209, Memory: 519.01 MB # pcov
```

As we can see, for this particular case `pcov` is 1.72x faster than `Xdebug`. Depending on your project, you can get even better results (e.g. [5x times faster](https://dev.to/swashata/setup-php-pcov-for-5-times-faster-phpunit-code-coverage-3d9c))

`pcov` [has a comparable accuracy](https://github.com/krakjoe/pcov#differences-in-reporting) in coverage reports with `Xdebug`, so this should be a great choice unless you need a path/branch coverage (which are not supported by `pcov`).

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

<a name="miscellaneous"></a>
## Miscellaneous

<a name="dama-doctrine-test-bundle"></a>
### Use `dama/doctrine-test-bundle` to rollback transaction after each test

There are many ways on how to work with a database in functional tests, including setting up DB schema _before_ each test case (on `setUp()` method), truncating only changed tables _after_ each test case and so on.

Things we should be aware of:

1. We **should not** setup DB schema for each test. This is a one-time operation before tests are started.
2. We **should not** insert required for application work data for each test. Examples: lookup tables, administrator user, countries and states. Basically, everything that is static and stored in the DB - should be inserted one time before tests are started. This data should be reused across all the functional tests.

When these 2 points are done, all we need to do is to restore DB to the same state that it was when a test case started. And here is when [`dama/doctrine-test-bundle`](https://github.com/dmaicher/doctrine-test-bundle) comes into play.

It decorates a Doctrine database connection and starts a transaction _before_ each test then rolls it back _after_ it. By doing a `ROLLBACK`, each test leaves a database in its initial state after execution, while during the test we can do whatever we want - inserts, updates, deletes and searches.

> This results in a performance boost as there is no need to rebuild the schema, import a backup SQL dump or re-insert fixtures before every testcase. 

As always, results depend on your project, but [here is an example](https://locastic.com/blog/speed-up-database-refreshing-in-phpunit-tests/) of 40% performance improvement by using this bundle/approach.

<a name="prefer-unit-tests"></a>
### Combine functional & unit tests. Prefer Unit tests

Functional tests are very powerful, as they not just test independent _unit_ of code, but test how things work together. For example, if you are testing API endpoints, you can test the whole flow of your application: from `Request` to `Response`. 

However, testing every single condition and line of code only by functional tests is expensive, as it requires too many slow tests.

Imagine, we have an API endpoint for getting Order details: `GET /orders/{id}`. And the following business rules should apply:

* `Admin` **can** view Order details
* `Manager` **can** view Order details
* `User` who placed this Order **can** view Order details
* `User` who was given shared access but not placed this Order **can** view Order details
* Any other authenticated `User` **can not** view Order details
* Not authenticated `User` **can not** view Order details

API endpoint is protected by Security check: 

```php
#[IsGranted('ORDER_VIEW', object)]
public function viewOrder(Order $order) { /* ... */ }
```

To cover these requirements, we need to write at least 6 tests. But instead of creating 6 slow functional tests, we can create 2, just to check that action in a controller is protected by `#[IsGranted]` attribute.

```php
public function test_guest_user_can_not_view_order_details(): void
{
    $order = $this->createOrder();

    // send request by guest user
    $this->sendRequest(Request::METHOD_GET, sprintf('/api/orders/%s', $order->getId()));

    $this->assertRequestIsForbidden();
}

public function test_admin_user_can_view_order_details(): void
{
    $this->logInAsAdministrator();

    $order = $this->createOrder();

    // send request by administrator user
    $this->sendRequest(Request::METHOD_GET, sprintf('/api/orders/%s', $order->getId()));

    $this->assertResponseStatusCodeSame(Response::HTTP_OK);
}
```

All other cases can be checked in **Unit** tests for Security Voter and its logic. With this approach, we know that our voter is being called during API call (functional test checks it), and all the conditions/branches are covered by fast unit tests.

To give you an idea about how fast unit tests are (from a real project discussed above): 

```bash
XDEBUG_MODE=off vendor/bin/phpunit --testsuite=Unit

Time: 00:00.750, Memory: 66.01 MB

OK (979 tests, 2073 assertions)
```

So `979` unit tests take less than `1s` to be executed in 1 thread, while `1306` functional tests take `1m 46s` in 1 thread. For this case, unit tests are **105x** times faster. While 1 functional test is being executed, we can run 100 unit tests!

Also, having more unit tests makes Mutation Testing ([Infection](https://infection.github.io/guide/)) work _much_ faster for your project, while functional tests slows down this process.

---

Credits & related articles:

* [https://titouangalopin.com/posts/60edL3P43zwG6uGUiIlvPL/tips-for-a-reliable-and-fast-test-suite-with-symfony-and-doctrine](https://titouangalopin.com/posts/60edL3P43zwG6uGUiIlvPL/tips-for-a-reliable-and-fast-test-suite-with-symfony-and-doctrine)
* [https://codewave.eu/blog/how-to-reduce-time-symfony-integration-tests](https://codewave.eu/blog/how-to-reduce-time-symfony-integration-tests)
* [https://habr.com/ru/post/505736/](https://habr.com/ru/post/505736/)

<p class="my-12 text-center">
    <b>Find this interesting?</b> Let's continue the conversation on <a href="https://twitter.com/maks_rafalko" rel="nofollow">Twitter</a>.
</p>

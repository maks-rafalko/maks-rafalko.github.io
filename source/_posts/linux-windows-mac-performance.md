---
extends: _layouts.post
section: content
title: Comparing Linux, Windows, Mac performance with Symfony tests suite on Docker
date: 2024-06-24
categories: [php, symfony, tests, linux, mac, windows, docker, orbstack]
description: Comparing Linux, Windows, Mac performance with Symfony tests suite.
---

Currently, I have different laptops at home and can compare how fast different test suites can be on 3 Operation Systems:

- Windows
- Arch Linux (Manjaro)
- MacOS

This is not a detailed review of processors and other hardware, rather just my experiments and interesting findings with 2 laptops and 3 OSs on them.

- Laptop 1: Dell XPS 9500 with i7-10750H 10Gen and 64Gb RAM. Release date: May 2020. It has Windows 11 PRO and Arch Linux Manjaro
- Laptop 2: MacBook PRO 14" with M3 PRO processor and 36Gb RAM. Release date: end of 2023

### Windows with WSL2 with Docker vs native Docker on Linux

The first experiment is to compare the speed _on the same laptop_ on different OSs.

The first test suite is a Symfony-based project, API-Platform, with 900+ functional ([application](https://symfony.com/doc/current/testing.html#write-your-first-application-test)) tests. Tests spin up a real MySQL database, actually 4 databases inside the same container and are executed in 4 threads using [Paratest](https://github.com/paratestphp/paratest). Tests use `WebTestCase` base class and BrowserKit under the hood.

Before comparing, I assume that Linux with native Docker will have the best performance, so let's start from it and take it as a baseline.

```bash
docker compose exec php vendor/bin/paratest -p4 --runner=WrapperRunner
```

Results:

```bash
[...]
............................................................... 819 / 901 ( 90%)
............................................................... 882 / 901 ( 97%)
...................                                             901 / 901 (100%)

Time: 00:11.493, Memory: 26.00 MB

OK (901 tests, 3914 assertions)
```

Pretty nice results. 11 seconds to run almost 1000 functional tests in 4 threads.

Now let's boot into Windows and run the same test suite there.

NOTE: While I write Windows, I work there on WSL2 exclusively. This is a separate topic to speak about, but in a nutshell, you will get the best performance on Windows OS if you do the following:

- you shouldn't store you code on Windows system, only under WSL2
- you shouldn't run Docker from Windows, only from WSl2
- basically, you should do everything from WSL2 and only run IDE from Windows, that can easily connect and edit code stored in WSL2

So, I open WSL2 terminal and execute the same commands:

```bash
docker compose exec php vendor/bin/paratest -p4 --runner=WrapperRunner
```

Results:

```bash
[...]
............................................................... 819 / 901 ( 90%)
............................................................... 882 / 901 ( 97%)
...................                                             901 / 901 (100%)

Time: 00:11.230, Memory: 27.00 MB

OK (901 tests, 3914 assertions)
```

I did it ~10 times and results vary less than by 1s. So 11.2s is an average time, which is **the same speed as on native Linux with Docker**.

Let's sum it up correctly: if you configure Windows to store the source code on WSL2 and work with Docker from there, you will get the same or pretty much the same performance as on native Linux with Docker. 

### MacOS with OrbStack/Docker vs Docker on Windows/Linux

Now, let's compare this tests suite with MacBook Pro 14" with M3 PRO processor and 36Gb RAM.

Instead of Docker, I tried to use [OrbStack](https://orbstack.dev/), which is a drop-in replacement.

Run the command

```bash
docker compose exec php vendor/bin/paratest -p4 --runner=WrapperRunner
```

and see interesting results:

```bash
[...]
............................................................... 819 / 901 ( 90%)
............................................................... 882 / 901 ( 97%)
...................                                             901 / 901 (100%)

Time: 00:05.493, Memory: 26.00 MB

OK (901 tests, 3914 assertions)
```

It's 2 times faster than on my Dell laptop, both on Windows and Linux 😳

When it takes around 11 seconds on Windows and Linux, it takes only 5 seconds to run 901 functional tests on Mac. Nice results!

Back in 2020, my experiments showed that Docker on Linux was extremely faster than on MacOS (2-3 times faster), but now, with the new M processor, it looks like it's changed. I will keep an eye on it, but very impressed by the results.

Let's do one more comparison with [Infection](https://infection.github.io/guide/)'s tests suite: 4000+ unit tests executed in 1 thread using PHPUnit:

Docker on Linux:

```bash
[...]
............................................................. 4026 / 4103 ( 98%)
............................................................. 4087 / 4103 ( 99%)
................                                              4103 / 4103 (100%)

Time: 00:12.906, Memory: 100.00 MB

OK, but some tests were skipped!
Tests: 4103, Assertions: 9991, Skipped: 1.
```

Docker on Windows under WSL2:

```bash
[...]
............................................................. 4026 / 4103 ( 98%)
............................................................. 4087 / 4103 ( 99%)
................                                              4103 / 4103 (100%)

Time: 00:12.822, Memory: 100.00 MB

OK, but some tests were skipped!
Tests: 4103, Assertions: 9991, Skipped: 1.
```

OrbStack on MacOS:

```bash
[...]
............................................................. 4026 / 4103 ( 98%)
............................................................. 4087 / 4103 ( 99%)
................                                              4103 / 4103 (100%)

Time: 00:06.258, Memory: 96.00 MB

OK, but some tests were skipped!
Tests: 4103, Assertions: 9991, Skipped: 1.
```

And again, MacBook with its new M3 PRO processor outperforms Dell XPS with Intel i7-10750H 10gen both on Linux and Windows with WSL2.

Very interesting and promising!

<p class="my-12 text-center">
    <b>Find this interesting?</b> Let's continue the conversation on <a href="https://twitter.com/maks_rafalko" rel="nofollow">Twitter</a>.
</p>

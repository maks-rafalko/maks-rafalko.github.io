---
title: About
description: A little bit about the site
---
@extends('_layouts.master')

@section('body')
    <h1>About</h1>

    <img src="/assets/img/about.png"
        alt="About image"
        class="flex rounded-full h-64 w-64 bg-contain mx-auto md:float-right my-6 md:ml-10">

    <p class="mb-6">Hey, my name is Maks. I'm a Software Engineer working mainly with PHP and it's ecosystem.</p>

    <p class="mb-6">I'm the author and one of the maintainers of <a href="https://infection.github.io/guide/">Infection PHP</a> - Mutation Testing library for PHP.</p>

    <p class="mb-6">The second language/ecosystem I'm working with professionally is Node.js.</p>
@endsection

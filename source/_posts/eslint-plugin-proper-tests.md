---
extends: _layouts.post
section: content
title: ESLint plugin for writing proper tests
date: 2024-07-14
categories: [js, nodejs, eslint, jest, tests]
description: Improve performance of tests suite for Symfony Application.
---

<img src="https://eslint.org/assets/images/logo/eslint-logo-color.svg" alt="eslint cover">

Recently I joined a project with many typical microservices, where each one is a standalone NestJS application with many unit and e2e tests. 

The good thing - developers write really many tests there, covering all the API endpoints, validation, transactions, etc.

The bad thing - tests were looking really messy, so we decided to 

- fix the most popular mistakes and spread best practices across all developers of different microservices
- somehow control it, to not allow writing messy tests in the future

To achieve the second point in an automatic way, it was decided to write custom ESLint Rules so that developers immediately notified right in their IDE when tests look bad, and which is more important - build is failed if our best practices are not followed.

Below, I would like to show the most interesting rules that I've created and then applied to this work project.

# eslint-plugin-proper-tests

Let's quickly look into some of the rules from this plugin.

## [`no-useless-matcher-to-be-defined`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-useless-matcher-to-be-defined.md)

This rule disallows using `expect(..).toBeDefined()` matcher when it is obvious that a variable is always defined.

Here are a couple of real examples of bad code:

```ts
const response = await request.post(`/api/some-resource`);

expect(response).toBeDefined();
expect(response.status).toBe(200);
```

What's the problem with this code? The problem is that `expect(response).toBeDefined();` always passes and is really the useless check here.

The type of `response` variable is `Response`. It physically can not be `undefined`. Does it make sense to check it with `.toBeDefined()`? No.

So let's just remove this line:

```diff
const response = await request.post(`/api/some-resource`);

- expect(response).toBeDefined();
expect(response.status).toBe(200);
```

Another example that is hard to spot looking into the code for the first time:

```ts
const response = await request.post(`/api/some-resource`);

// ...

const userCacheKeys = await redisClient.keys('USER_*');

expect(userCacheKeys).toBeDefined();
```

The purpose of this test was to check that after calling API endpoint, user's data is cached to Redis. But does it really test anything?

Absolutely no, because the type of `userCacheKeys` is `string[]`. So `redisClient.keys()` **always returns an array**, which is always "defined".

Again, we have expectation that always passes, and which is worse, we don't test anything. If Redis has these keys or hasn't - tests doesn't catch it.

The proper change for this case is the following:

```diff
const userCacheKeys = await redisClient.keys('USER_*');

- expect(userCacheKeys).toBeDefined();

+ const cacheValues = await redisClient.mGet(userCacheKeys);
+
+ expect(cacheValues).toEqual(['cache-value-1', 'cache-value-2']);
```

So, now we check the number of keys and exact values returned from Redis. Adding new keys or removing keys will affect this test, which is what we really want.

## [`no-useless-matcher-to-be-null`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-useless-matcher-to-be-null.md)

Similar rule to the previous one, complains where `not.toBeNull()` is used when it shouldn't:

```ts
const user = repository.findByIdOrThrow(123); // return type is `User`

expect(user).not.toBeNull();
```

## [`no-mixed-expectation-groups`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-mixed-expectation-groups.md)

This rule reports an error if expectations for different variables are mixed with each other.

Here is a very simplified example of bad test:

```ts
const response = await request.post(`/api/some-resource`);

const entity = await repository.getById(123);

expect(response.status).toBe(201);
expect(entity).toMatchObject({...});
expect(response.headers).toMatchObject({...});
expect(response.body).toMatchObject({...});
```

In real life when you have multiple lines the things look much worse. So what is the problem here? The problem is that we are checking different variables, mixing them with each other.

Instead, it's better to have "groups" of expectations. The same code can be rewritten like:

```ts
const response = await request.post(`/api/some-resource`);

const entity = await repository.getById(123);

expect(response.status).toBe(201);
expect(response.headers).toMatchObject({...});
expect(response.body).toMatchObject({...});

expect(entity).toMatchObject({...});
```

## [`no-long-arrays-in-test-each`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-long-arrays-in-test-each.md)

This rule disallows the usage of long arrays in `test.each()` calls.

The following code is considered errors:

```ts
test.each([
  {
    description: 'test case name #1',
    inputValue: 'a',
    expectedOutput: 'aa',
  },
  {
    description: 'test case name #2',
    inputValue: 'b',
    expectedOutput: 'bb',
  },
  {
    description: 'test case name #3',
    inputValue: 'c',
    expectedOutput: 'cc',
  },
  {
    description: 'test case name #4',
    inputValue: 'd',
    expectedOutput: 'dd',
  },
  {
    description: 'test case name #5',
    inputValue: 'e',
    expectedOutput: 'ee',
  },
  {
    description: 'test case name #6',
    inputValue: 'f',
    expectedOutput: 'ff',
  },
])('$description', ({ clientCountry, expectedPaymentMethod, processorName }) => {
  // ...
});
```

Consider extracting such long arrays to a separate files with for example `.data.ts` postfix.

The following code is considered correct:

```ts
// some-service.data.ts
export type TestCase = Readonly<{
  description: string;
  inputValue: string;
  expectedOutput: string;
}>;

export const testCases: TestCase[] = [
  {
    description: 'test case name #1',
    inputValue: 'a',
    expectedOutput: 'aa',
  },
  {
    description: 'test case name #2',
    inputValue: 'b',
    expectedOutput: 'bb',
  },
  {
    description: 'test case name #3',
    inputValue: 'c',
    expectedOutput: 'cc',
  },
  {
    description: 'test case name #4',
    inputValue: 'd',
    expectedOutput: 'dd',
  },
  {
    description: 'test case name #5',
    inputValue: 'e',
    expectedOutput: 'ee',
  },
  {
    description: 'test case name #6',
    inputValue: 'f',
    expectedOutput: 'ff',
  },
];
```

and now test is more readable:

```ts
test.each(testCases)('$description', ({ inputValue, expectedOutput }) => {
  // ...
});
```


----

TODO

- add section about typed rules, and how technically to-be-defined works. With links to docs

---
extends: _layouts.post
section: content
title: ESLint plugin for writing proper tests
date: 2024-07-14
categories: [js, nodejs, eslint, jest, tests]
description: Improve performance of tests suite for Symfony Application.
---

<img src="https://eslint.org/assets/images/logo/eslint-logo-color.svg" alt="eslint cover">

- [Open Source plugin](#open-source-plugin)
    - [no-useless-matcher-to-be-defined](#no-useless-matcher-to-be-defined)
    - [no-useless-matcher-to-be-null](#no-useless-matcher-to-be-null)
    - [no-mixed-expectation-groups](#no-mixed-expectation-groups)
    - [no-long-arrays-in-test-each](#no-long-arrays-in-test-each)
- [Add more power to your ESLint rules with TypeScript](#add-power-to-eslint-rules-with-typescript)

Recently I joined a project with many typical microservices, where each one is a standalone NestJS application with many unit and e2e tests. 

The good thing - developers write really many tests there, covering all the API endpoints, validation, transactions, etc.

The bad thing - tests were looking messy, so we decided to 

- fix the most popular mistakes and spread best practices across all developers of different microservices
- somehow control it, to not allow writing messy tests in the future

To achieve the second point in an automatic way, it was decided to write custom ESLint Rules so that developers immediately notified right in their IDE when tests look bad, and which is more important - build is failed if our best practices are not followed.

Below, I would like to show the most interesting rules that I've created and then applied to this work project.

<a name="open-source-plugin"></a>
## Open Source plugin

[`eslint-plugin-proper-tests`](https://github.com/maks-rafalko/eslint-plugin-proper-tests) - let's quickly look into some of the rules from this plugin.

<a name="no-useless-matcher-to-be-defined"></a>
### [`no-useless-matcher-to-be-defined`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-useless-matcher-to-be-defined.md)

This rule disallows using `expect(...).toBeDefined()` matcher when it is obvious that a variable is always defined.

I was surprised how many times I saw this in the codebases. And I was pretty sure that there should be a rule for this, but seems like not.

As an OSS contributor, I understand that creating new libs/repos is not always a good idea, so I tried to propose this rule for [`eslint-plugin-jest`](https://github.com/jest-community/eslint-plugin-jest) (btw, it's awesome!), but for some reason didn't even get a response (and at the time of writing of this post, this is still the case).

> See original proposal [`eslint-plugin-jest#1616`](https://github.com/jest-community/eslint-plugin-jest/issues/1616)

Ok, let's get back to the rule. Here are a couple of real-life examples of bad code:

```ts
const response = await request.post(`/api/some-resource`);

expect(response).toBeDefined();
expect(response.status).toBe(200);
```

What's the problem with this code? The problem is that `expect(response).toBeDefined();` always passes and is really the useless check here.

The type of `response` variable is `Response`. It physically can not be `undefined`. Does it make sense to check it with `.toBeDefined()`? No.

Reported error:

```bash
Type of "response" variable is "Response", it can not be undefined. It is useless to check it with `.toBeDefined()` matcher.
```

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

Again, we have expectation that always passes, and which is worse, we don't test anything. Whether Redis has these keys or not - tests don't really catch it.

The proper change for this case is the following:

```diff
const userCacheKeys = await redisClient.keys('USER_*');

- expect(userCacheKeys).toBeDefined();

+ const cacheValues = await redisClient.mGet(userCacheKeys);
+
+ expect(cacheValues).toEqual(['cache-value-1', 'cache-value-2']);
```

So, now we check the number of keys and exact values returned from Redis. When at some point developers will do a change that will lead to adding new keys or removing keys, such test will catch it, which is what we want.

> [Read below](#add-power-to-eslint-rules-with-typescript) to understand how to use TypeScript types to add more power to your ESLint rules

<a name="no-useless-matcher-to-be-null"></a>
### [`no-useless-matcher-to-be-null`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-useless-matcher-to-be-null.md)

Similar rule to the previous one, this rule complains where `not.toBeNull()` is used when it shouldn't:

```ts
const user = repository.findByIdOrThrow(123); // return type is `User`

expect(user).not.toBeNull();
```

From types point of view, `user` can not be `null`, so this check is useless and will be reported by this ESLint rule. Just remove it and replace with a proper expectation.

<a name="no-mixed-expectation-groups"></a>
### [`no-mixed-expectation-groups`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-mixed-expectation-groups.md)

This rule reports an error if expectations for different variables are mixed with each other.

Here is a very simplified example of a bad test:

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

expect(response.status).toBe(201);
expect(response.headers).toMatchObject({...});
expect(response.body).toMatchObject({...});

const entity = await repository.getById(123);

expect(entity).toMatchObject({...});
```

<a name="no-long-arrays-in-test-each"></a>
### [`no-long-arrays-in-test-each`](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/main/docs/rules/no-long-arrays-in-test-each.md)

This rule disallows the usage of long arrays in `test.each()` calls.

The following code is bad:

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

The following code way better:

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
test.each(testCases)('$description', ({ inputValue, expectedOutput }: TestCase) => {
  // ...
});
```

<a name="add-power-to-eslint-rules-with-typescript"></a>
## Add more power to your ESLint rules with TypeScript

[`eslint-plugin-proper-tests`](https://github.com/maks-rafalko/eslint-plugin-proper-tests) plugin uses TypeScript to provide more accurate results and get the power of TypeScript to understand variables types. 

To enable it properly, you need to [configure ESLint to work with TypeScript](https://typescript-eslint.io/getting-started/typed-linting):

```ts
// .eslintrc.js

module.exports = {
  "parser": "@typescript-eslint/parser",
  "parserOptions": {
    "project": true,
    "tsconfigRootDir": __dirname,
  }
}
```

Now, let's quickly look into how to use TypeScript types to improve your ESLint rules with an example of [`no-useless-matcher-to-be-defined`](#no-useless-matcher-to-be-defined) rule.

> Read official docs on how to use `typescript-eslint` for typed rules [here](https://typescript-eslint.io/developers/custom-rules#typed-rules)

In our rule we need to use `typeChecker` service:

```ts
const services = ESLintUtils.getParserServices(context);
const typeChecker = services.program.getTypeChecker();
```

[>> Look into the code](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/4d7a4a2b9d8ff466c0acb14f79746c6eae5ab9eb/src/custom-rules/no-useless-matcher-to-be-defined.ts#L11C5-L12C59)

Then, to understand if a variable is always defined, we need to:

- get the type of the variable
- check if it can be undefined

To get the declaration type of variable, we use the following code:

```ts
// argumentNode - is a node of the variable inside expect(variable) call

const symbol = services.getSymbolAtLocation(argumentNode);

const declarationType = typeChecker.getTypeOfSymbolAtLocation(symbol!, symbol!.valueDeclaration!);
```

It's a bit tricky, but the good thing you shouldn't do it often. What we did is got the declaration type of the variable.

Now, we need to check if it "contains" `undefined` sub-type inside (like `string | undefined`).

For this, I use `isTypeFlagSet` helper:

```ts
const isVariableCanBeUndefined = isTypeFlagSet(
  declarationType,
  ts.TypeFlags.Undefined
);
```

which under the hood gets all the union types of a variable and by bit mask checks if `undefined` is there:

```ts
const getTypeFlags = (type: ts.Type): ts.TypeFlags => {
  let flags: ts.TypeFlags = 0;

  for (const t of tsutils.unionTypeParts(type)) {
    flags |= t.flags;
  }

  return flags;
};

const isTypeFlagSet = (type: ts.Type, flagsToCheck: ts.TypeFlags): boolean => {
  const flags = getTypeFlags(type);

  return (flags & flagsToCheck) !== 0;
};
```

> Look to the final code [here](https://github.com/maks-rafalko/eslint-plugin-proper-tests/blob/4d7a4a2b9d8ff466c0acb14f79746c6eae5ab9eb/src/custom-rules/no-useless-matcher-to-be-defined.ts#L38-L49)

This is how you can use TypeScript types to improve your ESLint rules and make them more accurate. Possibilities are endless!

If you like this plugin and the work I'm doing, consider [giving it a Star on GitHub](https://github.com/maks-rafalko/eslint-plugin-proper-tests/).

<p class="my-12 text-center">
    <b>Find this interesting?</b> Let's continue the conversation on <a href="https://x.com/maks_rafalko/status/1804868289732002283" rel="nofollow">Twitter</a>.
</p>

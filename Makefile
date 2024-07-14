SHELL=bash

# See https://tech.davis-hansson.com/p/make/
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

deploy:
	npm run prod
	vendor/bin/jigsaw build production
	git add build_production && git commit -m "Build for deploy"
	git subtree push --prefix build_production origin gh-pages

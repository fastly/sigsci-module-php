# Makefile for sigsci-module-php 
#
#

# use a small container
PHP_CONTAINER=phpdev
SHARED_OPTS=run --rm -v$$(pwd):$$(pwd) -w $$(pwd)
PHP_ENV=docker ${SHARED_OPTS} ${PHP_CONTAINER}
PHP=${PHP_ENV} php
PHP_SERVER=docker ${SHARED_OPTS} -p 127.0.0.1:8000:8000 ${PHP_CONTAINER} php

VERSION=$(shell cat ./VERSION)

# need YYYY-MM-DD output
DATE=$(shell date +"%Y-%m-%d")

SOURCES=sigsci.php helloworld.php msgpack.php
PHP_SOURCES=$(wildcard *.php)
PHP_LINT=$(patsubst %.php, %.php.lint, $(PHP_SOURCES))

all: lint

release: lint version build-docker build

run-server: build-docker
	${PHP_SERVER} -S 0.0.0.0:8000 helloworld.php

%.php.lint: %.php
	@echo 'php -l $^; let rv+=$$?' > $@

lint.test.tmp.sh: $(PHP_LINT)
	@rm -f lint.test.tmp.sh
	@echo "rv=0" > lint.test.tmp.sh
	@cat $^ >> lint.test.tmp.sh
	@echo 'exit $${rv}' >> lint.test.tmp.sh
	@rm -rf $^

lint: lint.test.tmp.sh
	${PHP_ENV} sh $^
	@rm -f $^

VERSION=$(shell cat VERSION)
version:
	echo ${DATE}
	echo ${VERSION}
	sed -i.bak 's#"sigsci-module-php .*"#"sigsci-module-php $(VERSION)"#g' sigsci.php

clean:
	rm -rf _release/
	rm -rf *.bak *~
	rm -rf distrib/*.bak
	rm -rf *gz
	rm -rf module.php
	rm -rf fmt.phar phpmd.phar phpunit.phar
	rm -rf package.xml
	rm -rf sigisci-formatted.php
	rm -rf phpmd phpunit


build: clean
	mkdir _release/
	tar -cvzf _release/sigsci-module-php-$(VERSION).tar.gz $(SOURCES)

build-docker:
	docker build -t phpdev .

.PHONY: version



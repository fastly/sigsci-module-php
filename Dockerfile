FROM php:7.4-fpm-alpine

# enable socket extension
RUN docker-php-ext-install sockets

ADD https://phar.phpunit.de/phpunit-7.phar /usr/local/bin/phpunit
ADD https://phpmd.org/static/latest/phpmd.phar /usr/local/bin/phpmd
ADD https://github.com/nanch/phpfmt_stable/blob/master/fmt.phar?raw=true /usr/local/bin/phpfmt
RUN chmod a+x /usr/local/bin/phpunit /usr/local/bin/phpmd /usr/local/bin/phpfmt


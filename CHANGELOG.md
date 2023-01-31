# PHP SDK Module Release Notes

## 2.1.0 2021-08-11

* Standardized release notes
* Added module testing capability 

## 2.0.1 2021-07-29

* Added support for content-type application/graphql

## 2.0.0 2021-02-11

* Added support to block on HTTP codes 300-599
* Added support for OPTIONS and CONNECT methods
* Added redirect support

## 1.2.3 2018-06-29

* Standardized release notes
* Fixed pear packaging

## 1.2.2 2018-01-31

* Added support for multipart/form-data post
* Added ability to send all HTTP headers to agent for inspection

## 1.2.1 2017-08-23

* Fixed module type

## 1.2.0 2017-03-21

* Added ability to send XML posts to agent

## 1.1.1 2016-07-20

* No operational changes
* Added new download option

## 1.1.0 2016-07-14

* Improved error handling
* Switched to SemVer version numbers

## 1.0.0.52 2016-02-16

* Improved and simplified networking calls
* Improved error messages
* Upgraded MessagePack library
* Added support for detection of open redirects
* Configuration change: Originally HTTP methods that were inspected
  where explicitly listed (allowlisted, e.g. "GET", "POST") using the
  `allowed_methods` configuration parameter. The logic is now
  inverted, and one lists methods that should be ignored (blocklisted,
  e.g. "OPTIONS", "CONNECT") using the `ignore_methods`
  parameter. This allows for the detection of invalid or malicious
  HTTP requests.
* Added more detailed PHP version information sent to the agent for better
  identification and debugging

## 1.0.0.48 2015-10-26

* Initial release

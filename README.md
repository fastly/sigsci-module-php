## Requirements

* PHP 5.3 or higher

## Installation

### Install manually

1. Build the Signal Sciences PHP module.

    ```bash
    make release
    ```

1. Extract the PHP module archive to the current directory.

    ```bash
    tar -xvzf _release/sigsci-module-php-*
    ```

    After you extract the PHP package, you will need to include it within your application. Depending on your application structure, you may want to move the `msgpack.php` and `sigsci.php` files to a new directory to ensure the module is within your application tree.

1. At the top of your application, add the following line:

    ```php
    require_once('sigsci.php');
    ```

## Usage

The Signal Sciences PHP module class is named `SigSciModule`. This module contains several methods used for communicating with the Signal Sciences agent in addition to the following methods which the customer can safely access:

```php
__construct()
block()
agentResponseCode()
agentRequestID()
agentMeta()
agentTags()
preRequest()
postRequest()
```

### Basic usage

1. Call the `SigSciModule` class:

    ```php
    $sigsci = new SigSciModule();
    ```

1. After you instantiate the `SigSciModule` class, you will need to call `$sigsci->preRequest()`. This gathers request metadata which is sent to the agent to make a decision on the request.

    ```php
    $sigsci->preRequest(); // Gathers request details for the agent
    ```

1. Once `$sigsci->preRequest()` has completed, you will have access to `$sigsci->block()`.

    ```php
    if ($sigsci->block()){
    http_send_status(406);
    echo "Invalid Request Detected";
    ...
    ```

1. Pull detected attack types such as `SQLI` and `XSS`, which are returned to the module from the agent, by calling the `$sigsci->agentTags()` method.

    You will also need to add `$sigsci->postRequest()` to the end of the application. If your application exits anywhere in your application code, you should make the `$sigsci` object available to that calling method to call `$sigsci->postRequest()`.

    ```php
    if ($sigsci->block()){
    echo "Invalid Request Detected";
    http_response_code(406);
    $sigsci->postRequest();
    exit();
    }

    // Your application code
    $sigsci->postRequest();
    ?>
    ```

#### Example

```php
$sigsci = new SigSciModule();
$sigsci->preRequest(); // Gathers request details for the agent
if ($sigsci->block()){
echo "Invalid Request Detected";
http_response_code(406);
$sigsci->postRequest();
exit();
}

// Your application code
$sigsci->postRequest();
?>
```

### Simplified configuration

You can use the `__construct()` and `__destruct()` magic methods to simplify the configuration process. Instantiate the `SigSciModuleSimple()` class, which extends `SigSciModule()` and automatically calls `preRequest` and `postRequest` within `__construct()` and `__destruct()` respectfully.

This simplifies implementation into the following example:

```php
if (block()){
echo "Invalid Request Detected";
http_response_code(406);
exit();
}

// Your application code ....
?>
```

### Advanced configuration

Alternatively, you can configure the module via an `array()`. The following attributes are set by default, but may need to be modified to provide support for different environments.

```php
$config = array(
'max_post_size' => 100000, /* ignore posts bigger than this */
'timeout_microseconds' => 500000, /* fail open if agent calls take longer than this */
'socket_domain' => AF_UNIX, /* INET or UNIX */
'socket_address' => "/tmp/sigsci-lua",
'socket_port' => 0,
'allowed_methods' => array("GET", "POST", "PUT", "DELETE", "PATCH"),
'body_methods' => array("POST", "PUT", "PATCH"),
'filter_header' => array("cookie", "set-cookie", "authorization", "x-auth-token"), /* headers never sent to agent */
'anomaly_size' => 524288, /* if output is bigger size than this, send info to SigSci */
'anomaly_duration' => 1000, /* if request length is greater than this (millisecond), report back */
);
```

For example, on a SystemD-based system, the socket cannot run in `/tmp/sigsci-lua`. As a result, you will need to update the agent configuration to point to `/var/tmp/sigsci-lua`. To ensure the module can communicate with the agent, you must match the socket during module instantiation:

```php
$sigsci_conf = array('socket_address' => '/var/tmp/sigsci-lua');
$sigsci = new SigSciModuleSimple($config);
```

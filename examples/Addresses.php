<?php

/**
 * @package Abacus PHP REST API
 * @author Thomas Hirter <memurame@tekomail.ch>
 */

require '../vendor/autoload.php';
require './config.php';

use AbacusAPIClient\AbacusClient;
use AbacusAPIClient\ResourceType;

$client = new AbacusClient($abacus_client_config);


//
// Get all Addresses
//

$addresses = $client->resource(ResourceType::ADDRESSES)->all();

print("<pre>".print_r($addresses,true)."</pre>");
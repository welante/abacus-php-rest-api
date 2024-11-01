<?php

/**
 * @package Abacus PHP REST API
 * @author Thomas Hirter <memurame@tekomail.ch>
 */

namespace AbacusAPIClient\Resource;

class Addresses extends AbstractResource
{
    const URLS = [
        'get'    => 'Addresses/{object_id}',
        'all'    => 'Addresses',
    ];

    public function all(){
        return ($this->getClient()->getRequest($this->getURL('all')));
    }

}
<?php
namespace pr2\http;

// A pass-through stand-in so the test can post plain JSON.
class Encryptor
{
    public function setKey($key)
    {
    }

    public function decrypt($data, $iv)
    {
        return $data;
    }
}

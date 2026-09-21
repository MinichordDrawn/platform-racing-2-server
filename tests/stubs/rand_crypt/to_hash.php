<?php
// A fast stand-in for the real bcrypt hashing, which is deliberately slow.
function to_hash($pass)
{
    return 'HASHED:' . $pass;
}

<?php


namespace Battis\PersistentObject\PerUser;


use Battis\PersistentObject\PersistentObjectException;

class PerUserObjectException extends PersistentObjectException
{
    const
        USER_UNDEFINED = 1001,
        USER_REDEFINED = 1002;
}

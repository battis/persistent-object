<?php


namespace Battis\PersistentObject\PerUser;


use Battis\PersistentObject\PersistentObject;
use Battis\PersistentObject\PersistentObjectException;

class User extends PersistentObject
{
    /** @var int */
    protected static $PASSWORD_HASH_ALGORITHM = PASSWORD_DEFAULT;

    /** @var array */
    protected static $PASSWORD_HASH_OPTIONS = [];


    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /**
     * @param string $username
     * @throws PersistentObjectException
     * @noinspection PhpUnused
     */
    protected function setUsername(string $username)
    {
        return $this->setField('username', $username);
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @param string $password
     * @throws PersistentObjectException
     * @noinspection PhpUnused
     */
    protected function setPassword(string $password)
    {
        return $this->setField('password', password_hash($password, static::$PASSWORD_HASH_ALGORITHM, static::$PASSWORD_HASH_OPTIONS));
    }

    /**
     * @param string $password
     * @return bool
     * @noinspection PhpUnused
     */
    protected function verifyPassword(string $password): bool
    {
        return password_hash($password, static::$PASSWORD_HASH_ALGORITHM, static::$PASSWORD_HASH_OPTIONS) === $this->password;
    }
}

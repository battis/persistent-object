<?php


namespace Battis\PersistentObject\PerUser;


use Battis\PersistentObject\Parts\Condition;
use Battis\PersistentObject\PersistentObject;
use Battis\PersistentObject\PersistentObjectException;
use PDO;

/**
 * @method static User[] getInstances(Condition $condition = null, $ordering = null, PDO $pdo = null)
 * @method static User|null getInstanceById($id, Condition $condition = null, PDO $pdo = null)
 * @method static User createInstance(array $values, bool $strict = true, bool $overwrite = false, PDO $pdo = null)
 * @method static User deleteInstance(string $id, Condition $condition = null, PDO $pdo = null)
 */
class User extends PersistentObject
{
    const USERNAME = 'username', PASSWORD = 'password';

    /** @var int */
    protected static $PASSWORD_HASH_ALGORITHM = PASSWORD_DEFAULT;


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
        return $this->setField(static::USERNAME, $username);
    }

    /**
     * @return string
     * @noinspection PhpUnused
     * @throws PersistentObjectException
     */
    public function getUsername(): string
    {
        return $this->getField(static::USERNAME);
    }

    /**
     * @param string $password
     * @throws PersistentObjectException
     * @noinspection PhpUnused
     */
    public function setPassword(string $password)
    {
        // crude check to see if the 'password' is actually a hash
        if (password_get_info($password)['algo'] === 0) {
            return $this->setField(static::PASSWORD, password_hash($password, static::$PASSWORD_HASH_ALGORITHM));
        } else {
            return $this->setField(static::PASSWORD, $password);
        }
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $password
     * @return bool
     * @noinspection PhpUnused
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function toArray(array $fieldsToExpand = [], array $fieldsToSuppress = []): array
    {
        $fieldsToSuppress[] = static::canonical(static::PASSWORD);
        return parent::toArray($fieldsToExpand, array_unique($fieldsToSuppress));
    }
}

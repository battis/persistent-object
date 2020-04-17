<?php
/**
 * @noinspection SqlDialectInspection
 * @noinspection SqlNoDataSourceInspection
 * @noinspection DuplicatedCode
 */

// FIXME duplicated code is bad

namespace Battis\PersistentObject\PerUser;


use Battis\PersistentObject\Parts\Condition;
use Battis\PersistentObject\PersistentObject;
use Battis\PersistentObject\PersistentObjectException;
use PDO;

abstract class PerUserObject extends PersistentObject
{
    const USER = 'user';

    /** @var string */
    protected static $userType = User::class;

    /** @var PersistentObject */
    private static $sessionUser;

    /** @var PersistentObject */
    protected $user;

    /******************************************************************
     * Static (per-session) user management
     */

    /**
     * @param string|PersistentObject $user
     * @throws PersistentObjectException
     */
    public static function assignUser($user)
    {
        if (empty(self::$sessionUser)) {
            if (empty($user)) {
                throw new PerUserObjectException(
                    'A valid user must be assigned',
                    PerUserObjectException::USER_UNDEFINED
                );
            } else if (is_a($user, static::$userType)) {
                self::$sessionUser = $user;
            } else {
                /** @var PersistentObject $t */
                $t = static::$userType;
                if (is_array($user)) {
                    self::$sessionUser = $t::createInstance($user);
                } else {
                    self::$sessionUser = $t::getInstanceById($user);
                }
            }
        } else {
            throw new PerUserObjectException(
                'A user has already been assigned for this session',
                PerUserObjectException::USER_REDEFINED
            );
        }
    }

    /**
     * @throws PerUserObjectException
     */
    protected function requireUser(): void
    {
        if ($this->getUser() === null) {
            throw new PerUserObjectException(
                "A valid user must be defined for this operation",
                PerUserObjectException::USER_UNDEFINED
            );
        }
    }

    public static function getSessionUser()
    {
        return self::$sessionUser;
    }

    /******************************************************************
     * Database interactions
     */

    /**
     * @param Condition|null $condition
     * @param null $ordering
     * @param array $params
     * @return array
     * @throws PersistentObjectException
     */
    protected static function querySelect(Condition $condition = null, $ordering = null, array $params = []): array
    {
        $result = [];
        $condition = Condition::merge($condition, Condition::fromPairedValues([self::canonical(self::USER) => self::getSessionUser()->getId()]));
        $statement = static::prepareStatement(
            'SELECT * FROM `' . static::databaseTable() . '` ' .
            (empty($condition) ? '' : " WHERE $condition ") .
            self::orderingToOrderingClause($ordering)
        );
        static::executeStatement($statement, ($condition ? $condition->parameters($params) : []));
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $instance = self::getCached($row[self::canonical(self::ID)]);
            if ($uncached = $instance === null) {
                $instance = new static();
            }
            $instance->constructFromValues($row, true, true, true);
            if ($uncached) {
                self::cache($instance);
            }
            $result[] = $instance;
        }
        return $result;
    }


    /**
     * @param array $fields
     * @throws PersistentObjectException
     */
    protected function queryUpdate($fields = []): void
    {
        if (is_string($fields)) {
            $fields = [$fields];
        }
        $fields = array_merge($fields, $this->dirty, [self::canonical(self::USER)]);

        $placeholders = [];
        foreach ($fields as $field) {
            $placeholders[] = "`$field` = :$field";
        }
        $statement = static::prepareStatement(
            'UPDATE `' . static::databaseTable() . '` ' . 'SET ' . implode(', ', $placeholders) . ' ' .
            'WHERE `' . self::canonical(self::USER) . '` = :user AND `' . self::canonical(self::ID) . '` = :id'
        );
        $fields[] = 'id';
        static::executeStatement($statement, $this->prepareQueryParameters($fields));
        $this->clearChanges();
    }

    protected function queryDelete()
    {
        $statement = static::prepareStatement(
            'DELETE FROM `' . static::databaseTable() . '` ' .
            'WHERE `' . self::canonical(self::USER) . '` = :user AND `' . self::canonical(self::ID) . '` = :id'
        );
        static::executeStatement($statement, ['user' => $this->getUser()->getId(), 'id' => $this->getId()]);
        self::clearCache($this);
        $this->clearChanges();
    }

    /*private static function perUserTable()
    {
        return 'temp_' . static::databaseTable() . '_per_user';
    }*/

    /* protected static function prepareStatement(string $query): PDOStatement
    {
        if (preg_match('/(FROM|JOIN)\s+`?/i', $query)) {
            $query = preg_replace('/((FROM|JOIN)\s+)(`?' . static::databaseTable() . '`?)/i', '$1`' . self::perUserTable() . '`', $query);

            $statement = parent::prepareStatement(
                'CREATE TEMPORARY TABLE `' . self::perUserTable() . '` ' .
                '(SELECT * FROM `' . static::databaseTable() . '` ' .
                'WHERE `user` = :user)'
            );
            parent::executeStatement($statement, ['user' => self::$sessionUser->getId()]);
        }

        return parent::prepareStatement($query);
    } */

    /* protected static function executeStatement(PDOStatement $statement, array $parameters = null)
    {
        $result = parent::executeStatement($statement, $parameters);

        try {
            $dropTemp = parent::prepareStatement('DROP TEMPORARY TABLE `' . self::perUserTable() . '`');
            parent::executeStatement($dropTemp);
        } catch (PersistentObjectException $e) {
            if ($e->getCode() !== PersistentObjectException::DATABASE_ERROR) {
                throw $e;
            }
        }

        return $result;
    } */

    /******************************************************************
     * Accessor methods
     */

    /**
     * @return PersistentObject
     * @noinspection PhpUnused
     */
    public function getUser()
    {
        return self::$sessionUser;
    }

    /**
     * @param string|PersistentObject $user
     * @throws PerUserObjectException
     * @throws PersistentObjectException
     * @noinspection PhpUnused
     */
    public function setUser($user)
    {
        $this->setField('user', $user, User::class);
        if (empty(self::$sessionUser)) {
            self::assignUser($user);
        } else if (is_a($user, static::$userType) && $user !== self::$sessionUser) {
            throw new PerUserObjectException(
                'Attempt to redefine user',
                PerUserObjectException::USER_REDEFINED
            );
        }
    }
}

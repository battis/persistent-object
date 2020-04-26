<?php

namespace Battis\PersistentObject;


use Battis\PersistentObject\Parts\Condition;
use DateTime;
use Exception;
use JsonSerializable;
use PDO;
use PDOStatement;

abstract class PersistentObject implements JsonSerializable
{
    const ID = 'id', CREATED = 'created', MODIFIED = 'modified';


    protected static $canonicalFields = [
        self::ID => self::ID,
        self::CREATED => self::CREATED,
        self::MODIFIED => self::MODIFIED
    ];

    protected static $DEFAULT_VALUES = [];

    /** @var string DateTime conversion format for string output (e.g. in url) */
    protected static $DATE_FORMAT = DATE_ISO8601;

    /** @var string Prefix for all database table names */
    protected static $TABLE_PREFIX = '';

    /** @var string Unique ID of this instance in the database */
    private $id;

    /** @var DateTime When this instance was created in the database */
    private $created;

    /** @var DateTime When this instance was last modified in the database */
    private $modified;


    /** @var PDO Database connection */
    private static $database;

    /** @var PersistentObject[string][string] A cache of instances already loaded from the database */
    private static $instances = [];

    /** @var string[string] A list of "dirty" fields that differ from current database record */
    protected $dirty = []; // FIXME was private, should be again

    /** @var string[] */
    private $deferred = [];

    /******************************************************************
     * Canonical database and url naming
     */

    /**
     * Canonical name of this object
     * @return string
     */
    public static function name(): string
    {
        $class = static::class;
        return strtolower(
            preg_replace(
                '/([A-Z])/',
                '$1',
                substr($class, strrpos($class, '\\') + 1)
            )
        );
    }

    /**
     * Canonical plural name of this object
     * @return string
     */
    public static function namePlural(): string
    {
        return static::name() . 's';
    }

    /**
     * Name of the table that stores this object class in database
     *
     * The default value is {@link PersistentObject::namePlural()}), but
     * subclasses can override this method to customize that value.
     * @return string
     */
    protected static function databaseTable(): string
    {
        return static::$TABLE_PREFIX . static::namePlural();
    }

    /**
     * Entries of field names stored in the database for this object
     * @return string[]
     */
    protected function fieldNames(): array
    {
        $fields = [static::canonical(static::ID)];
        foreach ($this as $field => $value) {
            switch ($field) {
                case 'db':
                case 'dirty':
                case 'deferred':
                    break;
                default:
                    if (false === in_array($field, $fields)) {
                        $fields[] = $field;
                    }
            }
        }
        return $fields;
    }

    public static function canonical($field)
    {
        if (empty(static::$canonicalFields[$field])) {
            if (property_exists(static::class, $field)) {
                return $field;
            }
        } else {
            return static::$canonicalFields[$field];
        }
        return null;
    }

    /******************************************************************
     * Static factory methods
     */

    /**
     * @param Condition|null $condition
     * @param string|string[]|null $ordering
     * @param PDO|null $pdo (Optional, immutable) May be set statically in
     *        advance using {@link PersistentObject::setDatabase()}
     * @return PersistentObject[]
     * @throws PersistentObjectException
     */
    public static function getInstances(Condition $condition = null, $ordering = [], PDO $pdo = null): array
    {
        if ($pdo !== null) {
            self::setDatabase($pdo);
        }
        return self::querySelect($condition, $ordering);
    }

    /**
     * Delete an existing instance from the database
     * @param string $id Unique instance ID
     * @param Condition|null $condition
     * @param PDO|null $pdo (Optional, immutable) May be set statically in
     *        advance using {@link PersistentObject::setDatabase()}
     * @return PersistentObject|null The object that was deleted
     * @throws PersistentObjectException
     * @uses \Battis\PersistentObject\PersistentObject::getInstanceById()
     */
    public static function deleteInstance(string $id, Condition $condition = null, PDO $pdo = null)
    {
        if ($pdo !== null) {
            self::setDatabase($pdo);
        }
        if ($instance = self::getInstanceById($id, $condition)) {
            $instance->queryDelete();
            return $instance;
        }
        return null;
    }

    /**
     * Select an existing instance from the database
     * @param string $id Unique instance ID
     * @param Condition|null $condition
     * @param PDO|null $pdo (Optional, immutable) May be set statically in
     *        advance using {@link PersistentObject::setDatabase()}
     * @return PersistentObject|null
     * @throws PersistentObjectException
     */
    public static function getInstanceById($id, Condition $condition = null, PDO $pdo = null)
    {
        if ($pdo !== null) {
            self::setDatabase($pdo);
        }
        $instance = self::getCached($id);
        if ($instance === null || $condition !== null) {
            $instance = new static();
            $instance->constructFromDatabase($id, $condition);
            self::cache($instance);
        }
        return $instance;
    }

    /**
     * Insert a new instance into the database
     * @param array<string,mixed> $values Associative array of field values to
     *        create a new instance (e.g. `['myField' => 'value']`)
     * @param bool $strict
     * @param bool $overwrite (Optional, default `false`) Only relevant if an
     *        instance ID is included in `$values`
     * @param PDO|null $pdo (Optional, immutable) May be set statically in
     *        advance using {@link PersistentObject::setDatabase()}
     * @return PersistentObject
     * @throws PersistentObjectException
     */
    public static function createInstance(array $values, bool $strict = true, bool $overwrite = false, PDO $pdo = null)
    {
        if ($pdo !== null) {
            self::setDatabase($pdo);
        }
        $instance = new static();
        $instance->constructFromValues($values, $strict, $overwrite);
        self::cache($instance);
        return $instance;
    }

    /******************************************************************
     * Instance factory caching
     */

    /**
     * Test if an instance is already cached
     * @param string $id Unique instance ID
     * @return bool `true` if a cached instance with matching ID exists
     */
    private static function isCached(string $id): bool
    {
        return false === empty(self::$instances[static::class][$id]);
    }

    /**
     * Get a cached instance, if present
     * @param string $id Unique instance ID
     * @return PersistentObject|null The matching instance if cached, `null`
     *         otherwise
     * FIXME was private, should be again
     */
    protected static function getCached(string $id)
    {
        if (self::isCached($id)) {
            return self::$instances[static::class][$id];
        }
        return null;
    }

    /**
     * Cache an instance (overwriting any prior cache)
     * @param PersistentObject $instance The instance to cache
     * @return PersistentObject The cached instance
     * FIXME was private, should be again
     */
    protected static function cache(PersistentObject $instance)
    {
        return self::$instances[static::class][$instance->getId()] = $instance;
    }

    /**
     * Clear any existing cache of an instance
     * @param PersistentObject $instance The instance whose cache is to be cleared
     * @return bool `true` if the instance was cached
     * FIXME was private, should be again
     */
    protected static function clearCache(PersistentObject $instance): bool
    {
        if (self::isCached($instance->getId())) {
            unset(self::$instances[static::class][$instance->getId()]);
            return true;
        }
        return false;
    }

    /******************************************************************
     * Instance constructors
     */

    /**
     * Default constructor
     *
     * PersistentObjects adhere to a factory design pattern and may not be
     * directly constructed
     * @see PersistentObject::getInstance()
     * @see PersistentObject::getInstanceById()
     * @see PersistentObject::createInstance()
     */
    private function __construct()
    {
        // create empty instance
    }

    /**
     * Construct a PersistentObject from database record
     * @param string $id Unique instance ID
     * @param Condition $condition
     * @throws PersistentObjectException
     * FIXME was private, should be again
     */
    protected function constructFromDatabase(string $id, Condition $condition = null)
    {
        $condition = Condition::merge(
            Condition::fromPairedValues([self::canonical(self::ID) => $id]),
            $condition
        );
        $statement = static::prepareStatement(
            'SELECT * FROM `' . static::databaseTable() . '` ' .
            "WHERE $condition " .
            'LIMIT 1'
        );
        static::executeStatement($statement, $condition->parameters());
        $values = $statement->fetch(PDO::FETCH_ASSOC);
        if ($values !== false) {
            $this->constructFromValues($values, false, true, true);
        } else {
            throw new PersistentObjectException(
                static::name() . " ID $id does not exist",
                PersistentObjectException::NO_SUCH_INSTANCE
            );
        }
    }

    /**
     * Construct a PersistentObject from a list of field values
     * @param array<string,string> $values Associative array of field values to
     *        create a new instance (e.g. `['myField' => 'value']`)
     * @param bool $strict
     * @param bool $overwrite (Optional, default `false`) Whether to overwrite
     *        data already in the instance with `$values`
     * @param bool $clean (Optional, default `false`) Whether the data in
     *        `$values` represents a clean load
     * @throws PersistentObjectException <a href='psi_element://PersistentObjectException::UNEXPECTED_OVERWRITE'>PersistentObjectException::UNEXPECTED_OVERWRITE</a>
     * FIXME was private, should be again
     */
    protected function constructFromValues($values, bool $strict = true, bool $overwrite = false, bool $clean = false)
    {
        foreach ($values as $field => $value) {
            if (false === empty($value)) {
                if ($overwrite || empty($this->$field)) {
                    $this->_set($field, $value, $strict);
                } else {
                    throw new PersistentObjectException(
                        "Data overwrites field `$field` unexpectedly.",
                        PersistentObjectException::UNEXPECTED_OVERWRITE
                    );
                }
            }

        }
        if ($clean) {
            $this->clearChanges();
        } else {
            if ($this->getId() === null) {
                $this->queryInsert();
            } else {
                $this->queryUpdate();
            }
        }
    }

    /**
     * @param array<string,string>[] $array Array of associative arrays of field values to
     *        create new instances (e.g. `[['myField' => 'value1'], ['myField' => 'value2']]`)
     * @param bool $strict
     * @param bool $overwrite (Optional, default `false`) Whether to overwrite
     *        data already in the instance with `$values`
     * @param bool $clean (Optional, default `false`) Whether the data in
     *        `$values` represents a clean load
     * @return PersistentObject[]
     * @throws PersistentObjectException <a href='psi_element://PersistentObjectException::UNEXPECTED_OVERWRITE'>PersistentObjectException::UNEXPECTED_OVERWRITE</a>
     */
    protected static function constructArrayFromValues(array $array, bool $strict = true, bool $overwrite = false, bool $clean = false)
    {
        $result = [];
        foreach ($array as $row) {
            $id = $row[static::canonical(static::ID)] ?? false;
            $instance = null;
            if ($id !== false) {
                $instance = self::getCached($id);
            }
            if ($instance === null) {
                $instance = new static();
            }
            $instance->constructFromValues($row, $strict, $overwrite, $clean);
            $result[] = $instance;
        }
        return $result;
    }

    /******************************************************************
     * Static database management
     */

    /**
     * Establish database connection for all instances
     * @param PDO $pdo
     * @param string|null $tableNamePrefix
     * @throws PersistentObjectException <a href='psi_element://PersistentObjectException::DATABASE_REDEFINED'>PersistentObjectException::DATABASE_REDEFINED</a>
     */
    public static function setDatabase(PDO $pdo, string $tableNamePrefix = null): void
    {
        if (self::$database === null) {
            self::$database = $pdo;
        } else {
            throw new PersistentObjectException(
                'PDO connection already active.',
                PersistentObjectException::DATABASE_REDEFINED
            );
        }

        if ($tableNamePrefix !== null) {
            static::$TABLE_PREFIX = $tableNamePrefix;
        }
    }

    /**
     * Get database connection
     * @return PDO
     * @throws PersistentObjectException {@link PersistentObjectException::DATABASE_UNDEFINED}
     */
    protected static final function getDatabase(): PDO
    {
        if (self::$database === null) {
            throw new PersistentObjectException(
                'Active database connection required.',
                PersistentObjectException::DATABASE_UNDEFINED
            );
        }
        return self::$database;
    }

    /******************************************************************
     * Database queries
     */

    /**
     * Overridable conversion of field values to strings for use in database query
     * @param string $field Name of field
     * @param mixed $value Value of field
     * @return string Value to be used in database query
     * @noinspection PhpUnusedParameterInspection
     */
    protected function prepareValueForQuery(string $field, $value): string
    {
        if (is_a($value, DateTime::class)) {
            /** @var DateTime $value */
            return $value->format('Y-m-d H:i:s');
        }
        return (string)$value;
    }

    /**
     * Prepare field values for use in database query
     * @param string[]|null $fields (Optional, defaults to all fields) Entries of
     *        fields to be prepared
     * @return mixed[string] Associative array of field values (e.g.
     *        `['myField' => 'value']`)
     * @throws PersistentObjectException
     * FIXME was private, should be again
     */
    protected function prepareQueryParameters(array $fields = null): array
    {
        if ($fields === null) {
            $fields = $this->fieldNames();
        }
        // exclude created, modified fields to allow CURRENT_TIMESTAMP() to function in database
        $fields = array_diff($fields, ['created', 'modified']);

        $values = [];
        foreach ($fields as $field) {
            $value = $this->_get($field);
            if ($value instanceof PersistentObject) {
                $values[$field] = $value->getId();
            } elseif (is_null($value)) {
                $values[$field] = null;
            } else {
                $values[$field] = $this->prepareValueForQuery($field, $value);
            }
        }
        return $values;
    }

    /**
     * Prepare a database statement
     * @param string $query Database query
     * @return PDOStatement
     * @throws PersistentObjectException
     */
    protected static function prepareStatement(string $query): PDOStatement
    {
        return self::getDatabase()->prepare($query);
    }

    /**
     * Execute a prepared database statement
     * @param PDOStatement $statement Prepared statement
     * @param array|null $parameters (Optional) Any parameters to be bound to
     *        the statement
     * @throws PersistentObjectException {@link PersistentObjectException::DATABASE_ERROR}
     */
    protected static function executeStatement(PDOStatement $statement, array $parameters = [])
    {
        if (false === $statement->execute($parameters)) {
            $message = 'MySQL error ' . $statement->errorCode() . PHP_EOL;
            $message .= $statement->queryString . PHP_EOL;
            foreach ($statement->errorInfo() as $line) {
                $message .= $line . PHP_EOL;
            }
            throw new PersistentObjectException(
                $message,
                PersistentObjectException::DATABASE_ERROR
            );
        }
    }

    /**
     * Get instances of a child PersistentObject subclass that refers back to
     * this instance
     *
     * The assumption is that the child subclass has a field whose name matches
     * {@link PersistentObject::name()} that stores an instance ID matching
     * this instance.
     * @param string $childType Fully-qualified type of child subclass (e.g.
     *        `"\Foo\Bar\Baz" or `Baz::class`)
     * @param Condition $condition
     * @param string|string[]|array<string,string>|null $ordering
     * @param array<string,string> $params
     * @return PersistentObject[]
     * @throws PersistentObjectException
     */
    protected function getChildren(string $childType, Condition $condition = null, $ordering = [], $params = []): array
    {
        /** @var $childType PersistentObject */
        $condition = Condition::merge(
            Condition::fromPairedValues([static::name() => $this->getId()]),
            $condition
        );
        return $childType::getInstances($condition, $ordering);
    }

    /**
     * Convert a loosely-defined ordering into a valid database query `ORDER
     * BY` clause
     *
     * A loosely-defined ordering might be:
     *
     *   - Actual database query clause(s) (e.g. ``"ORDER BY `foo` ASC, `bar`
     *     ASC LIMIT 5 OFFSET 25"`` &mdash; note that further post-`WHERE` clauses
     *     can be included)
     *   - A list of ordering subclauses (e.g. ``["`foo` ASC", "`bar` ASC"]``)
     *   - A list of fields (e.g. `["foo", "bar"]`) to be sorted in ascending
     *     order
     *   - A list of fields with orders (e.g. `["foo" => "ASC", "bar" =>
     *     "ASC"]`)
     *
     * Each of the following examples results in the followng `ORDER BY` clause (the first example also includes the further `LIMIT` clause):
     *
     * ``ORDER BY `foo` ASC, `bar` ASC``
     * @param string|string[]|string[string]|null $ordering
     * @return string
     * FIXME was private, should be again
     */
    protected static function orderingToOrderingClause($ordering): string
    {
        if (empty($ordering)) {
            return "";
        } elseif (is_array($ordering)) {
            if (array_keys($ordering) !== range(0, count($ordering) - 1)) {
                $temp = [];
                foreach ($ordering as $field => $order) {
                    $temp[] = "`$field` $order";
                }
                $ordering = $temp;
            }
            return 'ORDER BY ' . implode(', ', $ordering);
        } else {
            return (string)$ordering;
        }
    }

    /**
     * @param Condition|null $condition
     * @param string|string[]|null $ordering
     * @param array $params
     * @return PersistentObject[]
     * @throws PersistentObjectException
     */
    protected static function querySelect(Condition $condition = null, $ordering = null, array $params = []): array
    {
        $result = [];
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
     * @throws PersistentObjectException
     */
    protected function queryInsert(): void
    {
        foreach(static::$DEFAULT_VALUES as $field => $defaultValue) {
            if ($this->get($field) === null) {
                $this->set($field, $defaultValue);
            }
        }

        $fields = array_diff(static::fieldNames(), [static::canonical(static::CREATED), static::canonical(static::MODIFIED)]);
        if (empty($this->getId())) {
            $fields = array_diff($fields, [static::canonical(static::ID)]);
        }
        $statement = static::prepareStatement(
            'INSERT INTO `' . static::databaseTable() . '` ' .
            '(`' . implode('`, `', $fields) . '`) ' .
            'VALUES(:' . implode(', :', $fields) . ')'
        );
        static::executeStatement($statement, $this->prepareQueryParameters($fields));
        $this->constructFromDatabase(self::getDatabase()->lastInsertId());
        $this->clearChanges();
    }

    /**
     * @param string|string[]|null $fields
     * @throws PersistentObjectException
     */
    protected function queryUpdate($fields = []): void
    {
        if (is_string($fields)) {
            $fields = [$fields];
        }

        $fields = array_merge($fields, $this->dirty);

        $placeholders = [];
        foreach ($fields as $field) {
            $placeholders[] = "`$field` = :$field";
        }
        $statement = static::prepareStatement(
            'UPDATE `' . static::databaseTable() . '` ' . 'SET ' . implode(', ', $placeholders) . ' ' .
            'WHERE `' . self::canonical(self::ID) . '` = :id'
        );
        $fields[] = 'id';
        static::executeStatement($statement, $this->prepareQueryParameters($fields));
        $this->clearChanges();
    }

    /**
     * @throws PersistentObjectException
     */
    protected function queryDelete()
    {
        $statement = static::prepareStatement(
            'DELETE FROM `' . static::databaseTable() . '` ' .
            'WHERE `' . self::canonical(self::ID) . '` = :id'
        );
        static::executeStatement($statement, ['id' => $this->getId()]);
        self::clearCache($this);
        $this->clearChanges();
    }

    /******************************************************************
     * Track changes and automatically update on __destruct()
     */

    /**
     * @throws PersistentObjectException
     */
    public function flushChanges()
    {
        if (false === empty($this->dirty)) {
            $this->queryUpdate();
        }
    }

    // FIXME was private, should be again
    protected function clearChanges()
    {
        $this->dirty = [];
    }

    private function trackChange($field, $value)
    {
        if ($this->$field !== $value) {
            $this->$field = $value;
            $this->dirty[] = $field;
        }
    }

    /**
     * Useful for allowing deferred database fetches of related records
     * @param string $field
     * @return mixed
     * @throws PersistentObjectException
     */
    protected function getField(string $field)
    {
        if (in_array($field, array_keys($this->deferred))) {
            /** @var PersistentObject $t */
            $t = $this->deferred[$field];
            $id = $this->$field;
            if (is_a($id, PersistentObject::class)) {
                /** @var PersistentObject $id */
                $id = $id->getId();
            }
            $this->trackChange($field, $t::getInstanceById($id));
            unset($this->deferred[$field]);
        }
        return $this->$field;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param string $className (Optional) A subclass of {@link PersistentObject}
     * @param bool $strict (Optional, default `true`) Enforce relational
     *      integrity by fetching the matching database record during the field
     *      assignment -- meaningless if `$className` is not also provided!
     * @throws PersistentObjectException
     */
    protected function setField(string $field, $value, string $className = null, bool $strict = true)
    {
        /** @var PersistentObject $className
         */
        if ($className) {
            if (is_a($value, $className)) {
                $this->trackChange($field, $value);
            } elseif (is_array($value)) {
                $this->trackChange($field, $className::createInstance($value));
            } else {
                if ($strict) {
                    $this->trackChange($field, $className::getInstanceById($value));
                } else {
                    $this->trackChange($field, $value);
                    $this->deferred[$field] = $className;
                }
            }
        } else {
            $this->trackChange($field, $value);
        }
    }

    /**
     * @throws PersistentObjectException
     */
    public function __destruct()
    {
        $this->flushChanges();
    }

    /******************************************************************
     * Accessor methods
     */

    /**
     * @param string $verb {@link PersistentObject::SET} or {@link PersistentObject::GET}
     * @param string $field
     * @return string Accessor method name
     */
    private static function accessorMethodName(string $verb, string $field): string
    {
        $verb = strtolower($verb);
        switch ($verb) {
            case 'set':
            case 'get':
                // all is copacetic
                break;
            default:
                // default to "safe" verb if bad value
                $verb = 'get';
        }
        return $verb . str_replace('_', '', ucwords($field, '_'));
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return mixed
     * @throws PersistentObjectException `UNKNOWN_METHOD`
     */
    public function set($field, $value)
    {
        return $this->_set($field, $value, true, [External::instance(), 'is_callable']);
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param callable|mixed $callable_test
     * @param bool $strict
     * @return mixed
     * @throws PersistentObjectException
     */
    private function _set($field, $value, bool $strict = true, $callable_test = 'is_callable')
    {
        $setter = [$this, self::accessorMethodName('set', $field)];
        if (call_user_func($callable_test, $setter)) {
            return call_user_func($setter, $value, $strict);
        } elseif ($strict) {
            throw new PersistentObjectException(
                "No setter method available for field `$field`",
                PersistentObjectException::MUTATOR_NOT_DEFINED
            );
        }
        return null;
    }

    /**
     * @param string $field
     * @return mixed
     * @throws PersistentObjectException `UNKNOWN_METHOD`
     */
    public function get($field)
    {
        return $this->_get($field, [External::instance(), 'is_callable']);
    }

    /**
     * @param string $field
     * @param callable|mixed $callable_test
     * @return mixed
     * @throws PersistentObjectException
     */
    private function _get($field, $callable_test = 'is_callable')
    {
        $getter = [$this, self::accessorMethodName('get', $field)];
        if (call_user_func($callable_test, $getter)) {
            return call_user_func($getter);
        } else {
            throw new PersistentObjectException(
                "No getter method available for field `$field`",
                PersistentObjectException::ACCESSOR_NOT_DEFINED
            );
        }
    }

    /**
     * @param string $id
     * @see PersistentObject::$id
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function setId(string $id)
    {
        $this->id = $id;
    }

    /**
     * @return string
     * @see PersistentObject::$id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param DateTime|string $created
     * @throws Exception
     * @see PersistentObject::$created
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function setCreated($created)
    {
        if (false === is_a($created, DateTime::class)) {
            $created = new DateTime($created);
        }
        $this->created = $created;
    }

    /**
     * @return DateTime
     * @see PersistentObject::$created
     * @noinspection PhpUnused
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @param $modified
     * @throws Exception
     * @see PersistentObject::$modified
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function setModified($modified)
    {
        if (false === is_a($modified, DateTime::class)) {
            $modified = new DateTime($modified);
        }
        $this->modified = $modified;
    }

    /**
     * @return DateTime
     * @see PersistentObject::$modified
     * @noinspection PhpUnused
     */
    public function getModified()
    {
        return $this->modified;
    }

    /*******************************************************************
     * JsonSerialize
     */

    /**
     * @param string $field
     * @param mixed $value
     * @return string|null
     */
    protected static function prepareValueForToArray(string $field, $value)
    {
        switch ($field) {
            case self::canonical(self::CREATED):
            case self::canonical(self::MODIFIED):
                /** @var DateTime $value */
                return $value->format(static::$DATE_FORMAT);
            default:
                if (is_a($value, PersistentObject::class)) {
                    /** @var PersistentObject $value */
                    return $value->getId();
                } elseif (is_a($value, DateTime::class)) {
                    /** @var DateTime $value */
                    return $value->format(self::$DATE_FORMAT);
                } elseif ($value === null) {
                    return null;
                } else {
                    return (string)$value;
                }
        }
    }

    /**
     * @param array $fieldsToExpand
     * @param array $fieldsToSuppress
     * @return array
     * @throws PersistentObjectException
     */
    public function toArray(array $fieldsToExpand = [], array $fieldsToSuppress = []): array
    {
        /** @var mixed[string] $array */
        $array = [];

        /** @var PersistentObject[string] $expanded */
        $expanded = [];

        foreach ($this->fieldNames() as $field) {
            try {
                if (false === in_array($field, $fieldsToSuppress)) {
                    $value = $this->_get($field);
                    if (is_a($value, PersistentObject::class)) {
                        if (in_array($field, $fieldsToExpand)) {
                            $expanded[$field] = $value;
                        }
                        $array["{$field}_id"] = $value->getId();
                    } else {
                        $array[$field] = static::prepareValueForToArray($field, $value);
                    }
                }
            } catch (PersistentObjectException $e) {
                // ignore fields without (accessible) getter methods (e.g. User::$password)
                if ($e->getCode() !== PersistentObjectException::ACCESSOR_NOT_DEFINED) {
                    throw $e;
                }
            }
        }
        $fieldsToExpand = array_diff($fieldsToExpand, array_keys($expanded));
        $fieldsToSuppress = array_merge($fieldsToSuppress, array_keys($expanded), [static::name()]);
        foreach ($expanded as $field => $value) {
            $array[$field] = $value->toArray($fieldsToExpand, $fieldsToSuppress);
        }
        return $array;
    }

    /**
     * @param PersistentObject[] $array
     * @param string[] $fieldsToExpand
     * @param string[] $fieldsToSuppress
     * @return array<string,mixed>[]
     * @throws PersistentObjectException
     */
    public static function toArrays(array $array, array $fieldsToExpand = [], array $fieldsToSuppress = []): array
    {
        foreach ($array as $key => $value) {
            if (is_a($value, PersistentObject::class)) {
                $array[$key] = $value->toArray($fieldsToExpand, $fieldsToSuppress);
            } else {
                $array[$key] = self::prepareValueForToArray($key, $value);
            }
        }
        return $array;
    }

    /**
     * @return array<string,mixed>
     * @throws PersistentObjectException
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}

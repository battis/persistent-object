<?php
/** PersistentObjectException class */


namespace Battis\PersistentObject;


use Exception;

/**
 * Exceptions thrown by PersistentObject
 * @author Seth Battis <seth@battis.net>
 */
class PersistentObjectException extends Exception
{
    /**
     * @var int An accessor method was called that is not defined (or not
     *      accessible), usually in a subclass
     */
    const ACCESSOR_NOT_DEFINED = 1;

    /**
     * @var int A mutator method was called that is not defined (or not
     *      accessible), usually in a subclass
     */
    const MUTATOR_NOT_DEFINED = 2;

    /**
     * @var int An attempt to access the database was made before a
     *      database connection was provided
     */
    const DATABASE_UNDEFINED = 10;

    /**
     * @var int An attempt to redefine the database connection was made after
     *      it was previously established
     */
    const DATABASE_REDEFINED = 11;

    /**
     * @var int There was an error making a query to the database
     */
    const DATABASE_ERROR = 20;

    /**
     * @var int A request was made that would have overwritten data without an
     *      overwrite being explicitly permitted
     */
    const UNEXPECTED_OVERWRITE = 30;

    /**
     * @var int A request was made for a specific instance that does not exist
     */
    const NO_SUCH_INSTANCE = 31;
}

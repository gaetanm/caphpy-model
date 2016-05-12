<?php
/**
 * Copyright (C) 2014 Gaëtan Masson
 *
 * This file is part of CaPHPy.
 *
 * CaPHPy is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * CaPHPy is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CaPHPy.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace System\Core;

use \PDOException;
use \PDO;

use System\Error\DbConnectionHandlerException;
use System\Error\ModelException;

/**
 * This class stands for the M of MVC.
 *
 * @author  Gaëtan Masson <gaetanmdev@gmail.com>
 * @version   0.1
 * @licence   GPL
 * @copyright Copyright (c) 2014, Gaëtan Masson
 */
class Model
{
    private $stmt;
    private $exceptionHandler;
    private $dbConnectionHandler;

    /**
     * Constructor.
     */
    public function __construct($dbConnectionHandler, $exceptionHandler)
    {
        $this->stmt = [];
        $this->exceptionHandler    = $exceptionHandler;
        $this->dbConnectionHandler = $dbConnectionHandler;
    }

    /**
     * Orders to the DbConnectionHandler class to create a new PDO instance to use another database.
     *
     * @param string $dbInfoKey The key to access to the array containing the data of the targeted database
     */
    public function setPDO($dbInfoKey)
    {
        try
        {
            $this->dbConnectionHandler->createPDOInstance($dbInfoKey);
        }
        catch (DbConnectionHandlerException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
    }

    /**
     * Gets the PDO instance.
     */
    private function getPDO()
    {
        return $this->dbConnectionHandler->getPDO();
    }

    /**
     * Gets the targeted Entity instance.
     *
     * @param string $entityName The name of the targeted entity
     *
     * @throws ModelException
     *
     * @return Entity
     */
    public function getEntity($entityName)
    {
        try
        {
            $entityName = 'Application\\Entity\\'.$entityName;
            if (!class_exists($entityName))
                throw new ModelException('<strong>Model error</strong>: The '.$entityName.' entity does not exist');
            return new $entityName();
        }
        catch (ModelException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
    }

    /**
     * Creates iteratively an object for each foreign key of the table.
     *
     * @param string $object The name of the Entity class
     */
    private function getFkAsObject($object)
    {
        if (isset($object->fk))
        {
            foreach($object->fk as $key => $value)
            {
                $table = $key;
                if (!isset($object->pk)) $id = 'id';
                else $id = $object->pk;

                if ($object->$table != null)
                    $object->$table = $this->select($value, "WHERE $id = ?", $object->$table);
                if (isset($object->$table->fk))
                    $this->getFkAsObject($object->$table->fk);
            }
        }
    }

    /**
     * Returns query result as an object based on the name of the entity.
     *
     * @param string       $entity
     * @param PDOStatement $q
     * @param bool         $getFkAsObject
     *
     * @return Entity $object
     */
    public function asObject($entity, $q, $getFkAsObject)
    {
        if ($entity == 'stdClass')
            $object = $q->fetchObject('\\'.$entity);
        else
            $object = $q->fetchObject('Application\\Entity\\'.$entity);
        if ($getFkAsObject) $this->getFkAsObject($object);
        return $object;
    }

    /**
     * Returns query result as an objects array based on the name of the entity.
     *
     * @param string       $entity
     * @param PDOStatement $q
     * @param bool         $getFkAsObject
     *
     * @return array $objects
     */
    public function asObjectArray($entity, $q, $getFkAsObject)
    {
        if ($entity == 'stdClass')
            $objects = $q->fetchAll(PDO::FETCH_CLASS, '\\'.$entity);
        else
            $objects = $q->fetchAll(PDO::FETCH_CLASS, 'Application\\Entity\\'.$entity);

        if ($getFkAsObject)
        {
            foreach ($objects as $object)
                $this->getFkAsObject($object);
        }
        return $objects;
    }

    /**
     * Parses the query to return the correct number of parameter.
     *
     * @param string       $inputQuery
     * @param string|array $data
     *
     * @return array $param
     */
    private function parseQuery($inputQuery, $data)
    {
        $param = [];

        if (strpos($inputQuery, '?') !== false)
        {
            if (isset($data))
            {
                if (is_array($data))
                {
                    foreach ($data as $value)
                        $param[] = $value;
                }
                else $param[] = $data;
            }
            else $param = null;
        }
        return $param;
    }

    /**
     * Parses input data and return the query and its parameters.
     *
     * @param array $data
     *
     * @return array
     */
    private function parseUpdateData($data)
    {
        $size = count($data);
        $i = 0;
        $q = null;
        $param = null;

        foreach($data as $attribute => $value)
        {
            $i = ++$i;

            if ($i == $size) $att = $attribute.' = ?';
            else $att = $attribute.' = ?,';

            $q = $q.$att;

            $param[] = $value;
        }
        return [$q, $param];
    }

    /**
     * Checks if a data exists in the database.
     *
     * @param string $entity
     * @param string $inputQuery
     * @param array  $data
     *
     * @return boolean
     */
    public function exists($entity, $inputQuery, $data)
    {
        $c = 'Application\\Entity\\'.$entity;
        if (isset($c::$tableName))
            $tableName = $c::$tableName;
        else
            $tableName = strtolower($entity);
        $param = $this->parseQuery($inputQuery, $data);
        $query = 'SELECT COUNT(*) FROM '.$tableName.' '.$inputQuery;

        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
        return (bool) $this->stmt[$query]->fetchColumn();
    }

    /**
     * Counts rows number.
     *
     * @param string $entity
     * @param string $inputQuery
     * @param array  $data
     *
     * @return int
     */
    public function count($entity, $inputQuery = null, $data = null)
    {
        $c = 'Application\\Entity\\'.$entity;
        if (isset($c::$tableName))
            $tableName = $c::$tableName;
        else
            $tableName = strtolower($entity);
        if ($inputQuery != null)
        {
            $param = $this->parseQuery($inputQuery, $data);
            $query = 'SELECT COUNT(*) FROM '.$tableName.' '.$inputQuery;
        }
        else
        {
            $param = null;
            $query = 'SELECT COUNT(*) FROM '.$tableName;
        }
        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
        return $this->stmt[$query]->fetchColumn();
    }

    /**
     * Performs a custom query.
     *
     * @param string $inputQuery
     * @param array  $data
     *
     * @return PDOStatement
     */
    public function doQuery($inputQuery, $data = null)
    {
        $param = $this->parseQuery($inputQuery, $data);
        try
        {
            if (!array_key_exists($inputQuery, $this->stmt))
                $this->stmt[$inputQuery] = $this->getPDO()->prepare($inputQuery);

            $this->stmt[$inputQuery]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
        return $this->stmt[$inputQuery];
    }

    /**
     * Inserts the data of the actual instance into the database and hydrates its id.
     *
     * @param string $entity
     * @param bool   $enableAI
     */
    public function insert($entity, $enableAI = true)
    {
        $data = get_object_vars($entity);
        $param = null;

        if ($enableAI === true)
        {
            if (!isset($entity->pk)) $id = 'id';
            else $id = $entity->pk;
            unset($data[$id]);
        }
        unset($data['fk']);
        unset($data['pk']);
        foreach ($data as $key => $value)
        {
            if (is_object($value))
            {
                if (!isset($value->pk)) $id = 'id';
                else $id = $value->pk;

                $data[$key] = $value->$id;
            }
        }

        $attributes = implode(', ', array_keys($data));

        $size = count($data);
        $i = 0;
        $q = null;

        foreach($data as $attribute => $value)
        {
            $i = ++$i;
            if ($i == $size)
                $att = ' ?';
            else
                $att = ' ?,';
            $q = $q.$att;
            $param[] = $value;
        }

        if (isset($entity::$tableName))
            $tableName = $entity::$tableName;
        else
        {
            $tableName = explode('\\', get_class($entity));
            $tableName = strtolower($tableName[count($tableName)-1]);
        }
        $query = 'INSERT INTO '.$tableName.' ('.$attributes.') VALUES ('.$q.')';
        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);

            $this->stmt[$query]->execute($param);

            if ($enableAI === true) $entity->$id = $this->getPDO()->lastInsertId();
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
    }

    /**
     * R of CRUD.
     *
     * @param string $entity
     * @param string $inputQuery
     * @param array  $data
     * @param bool   $getFkAsObject
     *
     * @return Model
     */
    public function select($entity, $inputQuery, $data = null, $getFkAsObject = true)
    {
        $c = 'Application\\Entity\\'.$entity;
        if (isset($c::$tableName))
            $tableName = $c::$tableName;
        else
            $tableName = strtolower($entity);
        $param = $this->parseQuery($inputQuery, $data);
        $query = 'SELECT * FROM '.$tableName.' '.$inputQuery;
        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
        return $this->asObject($entity, $this->stmt[$query], $getFkAsObject);
    }

    /**
     * Returns an array containing the selected rows as objects.
     *
     * @param string $entity
     * @param string $inputQuery
     * @param array  $data
     * @param bool   $getFkAsObject
     *
     * @return array
     */
    public function selectSeveral($entity, $inputQuery = null, $data = null, $getFkAsObject = true)
    {
        $c = 'Application\\Entity\\'.$entity;
        if (isset($c::$tableName))
            $tableName = $c::$tableName;
        else
            $tableName = strtolower($entity);
        if ($inputQuery != null)
        {
            $param = $this->parseQuery($inputQuery, $data);
            $query = 'SELECT * FROM '.$tableName.' '.$inputQuery;
        }
        else
        {
            $query = 'SELECT * FROM '.$tableName;
            $param = null;
        }
        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
        return $this->asObjectArray($entity, $this->stmt[$query], $getFkAsObject);
    }

    /**
     * U of CRUD.
     *
     * @param string $entity
     * @param array  $attributes
     * @param string $inputQuery
     * @param array  $data
     */
    public function update($entity, $attributes = null, $inputQuery = null, $data = null)
    {
        if (isset($entity::$tableName))
            $tableName = $entity::$tableName;
        else
        {
            $tableName = explode('\\', get_class($entity));
            $tableName = strtolower($tableName[count($tableName)-1]);
        }
        $param = null;
        if (is_object($entity))
        {
            $data = get_object_vars($entity);

            if (!isset($entity->pk)) $id = 'id';
            else $id = $entity->pk;

            unset($data['fk']);
            unset($data['pk']);

            foreach ($data as $key => $value)
            {
                if (is_object($value))
                {
                    if (!isset($value->pk)) $id = 'id';
                    else $id = $value->pk;
                    $data[$key] = $value->$id;
                }
            }

            $val = $data[$id];
            $data = $this->parseUpdateData($data);
            $inputQuery = "WHERE $id = ?";
            $param = $val;
            $param = $this->parseQuery($inputQuery, $param);
            $param = array_merge($data[1], $param);
            $query = 'UPDATE '.$tableName.' SET '.$data[0].' '.$inputQuery;
        }
        else
        {
            $param = null;
            $attributes = $this->parseUpdateData($attributes);

            if ($inputQuery != null)
            {
                $param = array_merge($attributes[1], $this->parseQuery($inputQuery, $data));
                $query = 'UPDATE '.$tableName.' SET '.$attributes[0].' '.$inputQuery;
            }
            else
                $query = 'UPDATE '.$tableName.' SET '.$attributes[0];
        }
        try
        {
            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
    }

    /**
     * Deletes all the table rows.
     *
     * @param string $entity
     * @param string $inputQuery
     * @param array  $data
     */
    public function delete($entity, $inputQuery = null, $data = null)
    {
        if (is_object($entity))
        {
            if (isset($entity::$tableName))
                $tableName = $entity::$tableName;
            else
            {
                $tableName = explode('\\', get_class($entity));
                $tableName = strtolower($tableName[count($tableName)-1]);
            }

            if (!isset($entity->pk)) $id = 'id';
            else $id = $entity->pk;

            $inputQuery = 'WHERE id = ?';
            $data = $entity->$id;
        }
        else
        {
            $c = 'Application\\Entity\\'.$entity;
            if (isset($c::$tableName))
                $tableName = $c::$tableName;
            else
                $tableName = strtolower($entity);
        }

        try
        {
            if ($data != null)
            {
                $param = $this->parseQuery($inputQuery, $data);
                $query = 'DELETE FROM '.$tableName.' '.$inputQuery;
            }

            else
            {
                $query = 'TRUNCATE TABLE '.$tableName;
                $param = null;
            }

            if (!array_key_exists($query, $this->stmt))
                $this->stmt[$query] = $this->getPDO()->prepare($query);
            $this->stmt[$query]->execute($param);
        }
        catch(PDOException $e)
        {
            $this->exceptionHandler->displayException($e);
        }
    }
}

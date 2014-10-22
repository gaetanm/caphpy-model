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

use \PDO;
use PDOException;
use System\Error\DbConnectionHandlerException;

/**
 * This class handles database connection.
 *
 * @author    Gaëtan Masson <gaetanmdev@gmail.com>
 * @version   0.1
 * @licence   GPL
 * @copyright Copyright (c) 2014, Gaëtan Masson
 */
class DbConnectionHandler
{
    private $pdo;
    private $dbInfo;

    /**
     * Constructor.
     */
    public function __construct($dbInfo)
    {
        $this->dbInfo = $dbInfo;
        $this->createPDOInstance();
    }

    /**
     * Parses the targeted array to returns an array to use as parameter for the PDO instance constructor.
     *
     * @param string $dbInfoKey The key to access to the array containing the data of the targeted database
     *
     * @return array
     *
     * @throws DbConnectionHandlerException
     */
    private function parseDbInfo($dbInfoKey)
    {
        if ($dbInfoKey === null)
        {
            if (!array_key_exists('main', $this->dbInfo))
                throw new DbConnectionHandlerException('<strong>DbConnectionHandler error</strong>: Missing database information key ('.$dbInfoKey.' given)');
            $dbInfo = $this->dbInfo['main'];
        }
        else
        {
            if (!array_key_exists($dbInfoKey, $this->dbInfo))
                throw new DbConnectionHandlerException('<strong>DbConnectionHandler error</strong>: Missing database information key ('.$dbInfoKey.' given)');
            $dbInfo = $this->dbInfo[$dbInfoKey];
        }
        return [$dbInfo['driver'].':host='.$dbInfo['host'].';dbname='.$dbInfo['db'], $dbInfo['usr'],
            $dbInfo['pwd']];
    }

    /**
     * Creates a PDO instance.
     *
     * @param string $dbInfoKey The key to access to the array containing the data of the targeted database
     *
     * @throws DbConnectionHandlerException
     * @throws PDOException
     */
    public function createPDOInstance($dbInfoKey = null)
    {
        try
        {
            $dbInfo = $this->parseDbInfo($dbInfoKey);

            $this->pdo = new PDO($dbInfo[0], $dbInfo[1], $dbInfo[2]);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        catch(DbConnectionHandlerException $e)
        {
            throw $e;
        }
        catch(PDOException $e)
        {
            throw new DbConnectionHandlerException('<strong>DbConnectionHandler error</strong>: error with PDO: ', null, $e);
        }
    }

    /**
     * Returns the PDO instance.
     *
     * @return PDO $pdo
     */
    public function getPDO()
    {
        return $this->pdo;
    }
}

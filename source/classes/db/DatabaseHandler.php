<?php
    declare(strict_types=1);
    if(!defined("INIT_INCLUDED") || !defined("access")) {
        http_response_code(400);
        echo "Initialisation failed: Initialisation file not included, OR access not permitted.";
        exit();
    }
    require_once theseusPath() . "/source/classes/interfaces/DatabaseHandlerInterface.php";

    /**
     * DatabaseHandler provides a secure interface for database operations.
     * 
     * This class implements DatabaseHandlerInterface and handles all database
     * interactions including connections, transactions, and only the necessary CRUD operations.
     * It includes built-in security measures for SQL injection prevention
     * through input sanitization and parameter binding.
     * 
     * @implements DatabaseHandlerInterface
     */
    class DatabaseHandler implements DatabaseHandlerInterface {
        private ?PDO $pdo = null;
        public function  __construct(?XMLHandler $xml = null) {

        }
        /**
         * Establishes a PDO database connection.
         *
         * Creates a new database connection using PDO. If $to is specified, 
         * connects to that specific database instead of the default one.
         *
         * @param string|null $to Optional database name to connect to
         * 
         * @throws RuntimeException When connection fails or is invalid
         * @throws PDOException When database credentials are incorrect
         * 
         * @return PDO The active database connection
         * 
        */
        public function connect(?string $to = null): PDO {
            try {
                $db = new Database();
                $this->pdo = $db->connect($to);
                $this->ensureConnection();
                return $this->pdo;
            } catch (PDOException $e) {
                throw new RuntimeException("Failed to connect to database: " . $e->getMessage());
            }
        }
    
        private function ensureConnection(): void {
            if (!$this->pdo instanceof PDO) {
                throw new RuntimeException("No database connection established");
            }
        }

        /**
         * Returns the current PDO connection instance.
         *
         * @return PDO|null
         */
        public function getConnection() : ?PDO {
            return $this->pdo;
        }
        
        /**
         * Disconnects from the database by setting the PDO instance to null.
         *
         * @return void
         */
        public function disconnect(): void {
            $this->pdo = null;
        }

        /**
         * Creates a transaction and returns a boolean status.
         *
         * @return bool
         * 
         */
        public function beginTransaction(): bool {
            $this->ensureConnection();
            return $this->pdo->beginTransaction();
        }

        /**
         * Rolls back the current transaction.
         *
         * @return bool
         */
        public function rollBack(): bool {
            $this->ensureConnection();
            return $this->pdo->rollBack();
        }

        /**
         * Commits the current transaction.
         *
         * @return bool
         */
        public function commit(): bool {
            $this->ensureConnection();
            return $this->pdo->commit();
        }

        public function lastInsertId() : string {
            $this->ensureConnection();
            return $this->pdo->lastInsertId();
        }

        /**
         * Checks if a transaction is active and returns a bool.
         *
         * @return bool
         * 
         */
        public function isTransactionActive(): bool {
            $this->ensureConnection();
            return $this->pdo->inTransaction();
        }

        public function executeQuery(string $query, array $params = []): bool {
            $this->ensureConnection();
            $statement = $this->pdo->prepare($query);
            return $statement->execute($params);
        }

        /**
         * Selects data from the database and returns an array of results.
         * 
         * @param string $table
         * @param array $columns
         * @param array $where
         * @param array $options
         * 
         */
        public function select(
            string $table, 
            array $columns = ['*'], 
            array $where = [], 
            array $options = []
        ): array {
            if (empty($table)) {
                throw new InvalidArgumentException("Table name cannot be empty");
            }
        
            // Ensure database connection exists
            $this->ensureConnection();
        
            try {
                $table = $this->sanitizeTableName($table);
                
                // Validate columns
                if ($columns[0] !== '*') {
                    $columns = array_map([$this, 'sanitizeColumnName'], $columns);
                }
                $columnList = $columns[0] === '*' ? '*' : implode(', ', $columns);
                
                $query = "SELECT {$columnList} FROM {$table}";
                
                // Handle WHERE conditions
                $params = [];
                if (!empty($where)) {
                    $whereConditions = array_map(function($column) {
                        $column = $this->sanitizeColumnName($column);
                        return "{$column} = :where_{$column}";
                    }, array_keys($where));
                    $query .= " WHERE " . implode(' AND ', $whereConditions);
                    
                    foreach ($where as $key => $value) {
                        $params["where_{$key}"] = $value;
                    }
                }
                
                // Handle optional clauses with validation
                if (!empty($options['orderBy'])) {
                    $orderBy = $this->sanitizeOrderBy($options['orderBy']);
                    $query .= " ORDER BY " . $orderBy;
                }
                
                if (!empty($options['limit'])) {
                    $limit = $this->validateLimit($options['limit']);
                    $query .= " LIMIT " . $limit;
                }
                
                if (!empty($options['offset'])) {
                    $offset = $this->validateOffset($options['offset']);
                    $query .= " OFFSET " . $offset;
                }
                
                $statement = $this->pdo->prepare($query);
                $statement->execute($params);
                
                return $statement->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                throw new RuntimeException("Failed to select data: " . $e->getMessage());
            }
        }

        /**
         * Inserts data into the specified table.
         *
         * @param string $table
         * @param array $data
         * @return bool
         */
        public function insert(string $table, array $data): bool {
            if (empty($table) || empty($data)) {
                throw new InvalidArgumentException("Table name and data array cannot be empty");
            }
            
            try {
                $table = $this->sanitizeTableName($table);
                $columns = implode(', ', array_keys($data));
                $placeholders = ':' . implode(', :', array_keys($data));
                
                $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
                $statement = $this->pdo->prepare($query);
                
                return $statement->execute($data);
            } catch (PDOException $e) {
                // Log error or handle appropriately
                throw new RuntimeException("Failed to insert data: " . $e->getMessage());
            }
        }
        
        /**
         * Updates data in the specified table.
         *
         * @param string $table
         * @param array $data
         * @param array $where
         * @return bool
         */
        public function update(string $table, array $data, array $where = []): bool {
            if (empty($table) || empty($data)) {
                throw new InvalidArgumentException("Table name and data cannot be empty");
            }
            
            // Only allow empty where clause for settings table
            if (empty($where) && $table !== 'settings') {
                throw new InvalidArgumentException("Where clause cannot be empty except for settings table");
            }
            
            try {
                $table = $this->sanitizeTableName($table);
                $set = array_map(function($column) {
                    return "{$column} = :{$column}";
                }, array_keys($data));
                
                $query = "UPDATE {$table} SET " . implode(', ', $set);
                $params = $data;
                
                // Add WHERE clause if conditions exist
                if (!empty($where)) {
                    $whereConditions = array_map(function($column) {
                        return "{$column} = :condition_{$column}";
                    }, array_keys($where));
                    
                    $query .= " WHERE " . implode(' AND ', $whereConditions);
                    
                    foreach ($where as $key => $value) {
                        $params["condition_{$key}"] = $value;
                    }
                }
                
                $statement = $this->pdo->prepare($query);
                return $statement->execute($params);
            } catch (PDOException $e) {
                throw new RuntimeException("Failed to update data: " . $e->getMessage());
            }
        }
        
        private $reservedWords = [ // List of reserved words in MySQL
            'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'asensitive',
            'before', 'between', 'bigint', 'binary', 'blob', 'both', 'by',
            'call', 'cascade', 'case', 'change', 'char', 'character', 'check',
            'collate', 'column', 'condition', 'constraint', 'continue',
            'convert', 'create', 'cross', 'current_date', 'current_time',
            'current_timestamp', 'current_user', 'cursor', 'database', 'databases',
            'day_hour', 'day_microsecond', 'day_minute', 'day_second', 'dec',
            'decimal', 'declare', 'default', 'delayed', 'delete', 'desc',
            'describe', 'deterministic', 'distinct', 'distinctrow', 'div',
            'double', 'drop', 'dual', 'each', 'else', 'elseif', 'enclosed',
            'escaped', 'exists', 'exit', 'explain', 'false', 'fetch', 'float',
            'float4', 'float8', 'for', 'force', 'foreign', 'from', 'fulltext',
            'grant', 'group', 'having', 'high_priority', 'hour_microsecond',
            'hour_minute', 'hour_second', 'if', 'ignore', 'in', 'index',
            'infile', 'inner', 'inout', 'insensitive', 'insert', 'int', 'int1',
            'int2', 'int3', 'int4', 'int8', 'integer', 'interval', 'into',
            'is', 'iterate', 'join', 'key', 'keys', 'kill', 'leading', 'leave',
            'left', 'like', 'limit', 'linear', 'lines', 'load', 'localtime',
            'localtimestamp', 'lock', 'long', 'longblob', 'longtext', 'loop',
            'low_priority', 'match', 'mediumblob', 'mediumint', 'mediumtext',
            'middleint', 'minute_microsecond', 'minute_second', 'mod', 'modifies',
            'natural', 'not', 'no_write_to_binlog', 'null', 'numeric', 'on',
            'optimize', 'option', 'optionally', 'or', 'order', 'out', 'outer',
            'outfile', 'precision', 'primary', 'procedure', 'purge', 'range',
            'read', 'reads', 'read_only', 'read_write', 'real', 'references',
            'regexp', 'release', 'rename', 'repeat', 'replace', 'require',
            'restrict', 'return', 'revoke', 'right', 'rlike', 'schema',
            'schemas', 'second_microsecond', 'select', 'sensitive', 'separator',
            'set', 'show', 'smallint', 'spatial', 'specific', 'sql', 'sqlexception',
            'sqlstate', 'sqlwarning', 'sql_big_result', 'sql_calc_found_rows',
            'sql_small_result', 'ssl', 'starting', 'straight_join', 'table',
            'terminated', 'then', 'tinyblob', 'tinyint', 'tinytext', 'to',
            'trailing', 'trigger', 'true', 'undo', 'union', 'unique', 'unlock',
            'unsigned', 'update', 'usage', 'use', 'using', 'utc_date', 'utc_time',
            'utc_timestamp', 'values', 'varbinary', 'varchar', 'varcharacter',
            'varying', 'when', 'where', 'while', 'with', 'write', 'xor', 'year_month',
            'zerofill'
        ];

        /**
         * Sanitizes the table name to prevent SQL injection.
         *
         * @param string $table
         * @return string
         */
        private function sanitizeTableName(string $table): string {
            // Check for reserved words
            if (in_array(strtolower($table), $this->reservedWords)) {
                throw new InvalidArgumentException("Table name '{$table}' is a reserved word");
            }
            
            // Basic sanitization - adjust based on your naming conventions
            $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            
            if (empty($sanitized)) {
                throw new InvalidArgumentException("Table name cannot be empty after sanitization");
            }
            
            return $sanitized;
        }

        /**
         * Sanitizes the column name to prevent SQL injection.
         *
         * @param string $column
         * @return string
         */
        private function sanitizeColumnName(string $column): string {
            if (empty($column)) {
                throw new InvalidArgumentException("Column name cannot be empty");
            }
        
            // Handle table.column notation
            if (str_contains($column, '.')) {
                $parts = explode('.', $column);
                if (count($parts) !== 2) {
                    throw new InvalidArgumentException(Data::getXml()->getString("//errors/dbInvalidNotation") . "{$column}");
                }
                return $this->sanitizeTableName($parts[0]) . '.' . 
                       preg_replace('/[^a-zA-Z0-9_]/', '', $parts[1]);
            }
        
            // Basic sanitization for column names
            $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            
            if (empty($sanitized)) {
                throw new InvalidArgumentException(Data::getXml()->getString("//errors/dbDataFailedSanitisation"));
            }
        
            if (in_array(strtolower($sanitized), $this->reservedWords)) {
                throw new InvalidArgumentException("Column name '{$sanitized}' is a reserved word");
            }
        
            return $sanitized;
        }
        
        /**
         * Sanitizes the ORDER BY clause to prevent SQL injection.
         *
         * @param string $orderBy
         * @return string
         */
        private function sanitizeOrderBy(string $orderBy): string {
            if (empty($orderBy)) {
                return 'id ASC';
            }
        
            $parts = explode(',', $orderBy);
            $sanitized = [];
            
            foreach ($parts as $part) {
                $part = trim($part);
                // Stricter pattern: column_name [ASC|DESC]
                if (preg_match('/^([a-zA-Z0-9_.]+)(\s+(ASC|DESC))?$/i', $part, $matches)) {
                    $column = $this->sanitizeColumnName($matches[1]);
                    $direction = isset($matches[3]) ? ' ' . strtoupper($matches[3]) : ' ASC';
                    $sanitized[] = $column . $direction;
                } else {
                    throw new InvalidArgumentException("Invalid ORDER BY clause: {$part}");
                }
            }
            
            return empty($sanitized) ? 'id ASC' : implode(', ', $sanitized);
        }
        
        /**
         * Validates the limit value for SQL queries.
         *
         * @param int $limit
         * @return int
         */
        private function validateLimit(int $limit): int {
            if ($limit < 0) {
                throw new InvalidArgumentException("Limit cannot be negative");
            }
            
            return min(max(1, $limit), 1000); // Cap between 1 and 1000
        }
        
        /**
         * Validates the offset value for SQL queries.
         *
         * @param int $offset
         * @return int
         */
        private function validateOffset(int $offset): int {
            if ($offset < 0) {
                throw new InvalidArgumentException("Offset cannot be negative");
            }
            
            return max(0, $offset);
        }
    }

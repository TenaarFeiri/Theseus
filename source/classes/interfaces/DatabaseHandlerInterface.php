<?php
    
    interface DatabaseHandlerInterface 
    {
        public function select(string $table, array $columns = ['*'], array $where = [], array $options = []): array;
        public function insert(string $table, array $data): bool;
        public function update(string $table, array $data, array $where): bool;
        public function getConnection() : ?PDO;
        public function disconnect(): void;
        public function connect(?string $to = null): ?PDO;
        public function rollBack(): bool;
        public function beginTransaction(): bool;
        public function commit(): bool;
        public function isTransactionActive(): bool;
        public function executeQuery(string $query, array $params = []): bool;
    }

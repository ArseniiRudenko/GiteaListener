<?php

namespace Leantime\Plugins\GiteaListener\Repositories;

use Illuminate\Contracts\Container\BindingResolutionException;
use Leantime\Core\Db\Db;

class GiteaListenerRepository {

    private Db $db;

    /**
     * @throws BindingResolutionException
     */
    public function __construct()
    {
        // Get DB Instance
        $this->db = app()->make(Db::class);
    }

    public function setup():void
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // Create tables if not exists.
        $sql = "CREATE TABLE IF NOT EXISTS `gitea_config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `repository_url` varchar(255) NOT NULL UNIQUE,
            `repository_access_token` varchar(255) NOT NULL,
            `hook_id` int(11) NOT NULL,
            `hook_secret` varchar(255) NOT NULL,
            `branch_filter` varchar(255) NOT NULL DEFAULT '*',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();
    }

    public function teardown():void
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();
        // Drop tables.
        $sql = "DROP TABLE IF EXISTS `gitea_config`;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();

    }

    /**
     * Return all saved configurations.
     *
     * @return array
     */
    public function getAll(): array
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, repository_url, repository_access_token, hook_id, hook_secret, branch_filter FROM gitea_config ORDER BY id DESC";
        $st = $pdo->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $rows ?: [];
    }

    /**
     * Find configuration by repository url
     *
     * @param string $url
     * @return array|null
     */
    public function getByUrl(string $url): ?array
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, repository_url, repository_access_token, hook_id, hook_secret, branch_filter FROM gitea_config WHERE repository_url = :url LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':url' => $url]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Find configuration by id
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, repository_url, repository_access_token, hook_id, hook_secret, branch_filter FROM gitea_config WHERE id = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Find configuration by hook_secret
     *
     * @param string $secret
     * @return array|null
     */
    public function getByHookSecret(string $secret): ?array
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, repository_url, repository_access_token, hook_id, hook_secret, branch_filter FROM gitea_config WHERE hook_secret = :secret LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':secret' => $secret]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Delete configuration by id
     *
     * @param int $id
     * @return bool
     */
    public function deleteById(int $id): bool
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "DELETE FROM gitea_config WHERE id = :id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':id' => $id]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Delete configuration by repository url
     *
     * @param string $url
     * @return bool
     */
    public function deleteByUrl(string $url): bool
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "DELETE FROM gitea_config WHERE repository_url = :url";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':url' => $url]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Update branch filter by id
     *
     * @param int $id
     * @param string $branchFilter
     * @return bool
     */
    public function updateBranchFilter(int $id, string $branchFilter): bool
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "UPDATE gitea_config SET branch_filter = :bf WHERE id = :id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':bf' => $branchFilter, ':id' => $id]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Update hook_id for a configuration by id
     *
     * @param int $id
     * @param int $hookId
     * @return bool
     */
    public function updateHookId(int $id, int $hookId): bool
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "UPDATE gitea_config SET hook_id = :hook_id WHERE id = :id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':hook_id' => $hookId, ':id' => $id]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Insert or replace a configuration.
     * If a config with the same repository_url exists it will be removed first and a new row will be inserted.
     *
     * @param array $data
     * @return int|null inserted id or null on failure
     */
    public function save(array $data): ?int
    {
        $conn = $this->db->getConnection();
        $pdo = $conn->getPdo();

        // Normalize values
        $url = $data['repository_url'] ?? '';
        $token = $data['repository_access_token'] ?? '';
        $hookSecret = $data['hook_secret'] ?? '';
        $branchFilter = $data['branch_filter'] ?? '*';
        $hookId = isset($data['hook_id']) ? (int)$data['hook_id'] : 0;

        try {
            // Begin transaction for atomic replace
            $pdo->beginTransaction();

            // If exists delete existing to "replace" with a fresh row
            $existing = $this->getByUrl($url);
            if ($existing !== null) {
                $del = $pdo->prepare("DELETE FROM gitea_config WHERE repository_url = :url");
                $del->execute([':url' => $url]);
                $del->closeCursor();
            }

            // Insert new row
            // @phpstan-ignore-next-line - table may not be visible to static analyzer
            $sql = "INSERT INTO gitea_config (repository_url, repository_access_token, hook_id, hook_secret, branch_filter)
                    VALUES (:url, :token, :hook_id, :hook_secret, :branch_filter)";

            $st = $pdo->prepare($sql);
            $ok = $st->execute([
                ':url' => $url,
                ':token' => $token,
                ':hook_id' => $hookId,
                ':hook_secret' => $hookSecret,
                ':branch_filter' => $branchFilter,
            ]);

            if (! $ok) {
                $st->closeCursor();
                $pdo->rollBack();
                return null;
            }

            $lastId = (int)$pdo->lastInsertId();
            $st->closeCursor();

            $pdo->commit();

            return $lastId > 0 ? $lastId : null;
        } catch (\Throwable $e) {
            try { $pdo->rollBack(); } catch (\Throwable $_) {}
            return null;
        }
    }

}

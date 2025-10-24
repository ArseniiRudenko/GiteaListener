<?php

namespace Leantime\Plugins\GiteaListener\Repositories;
use Illuminate\Support\Facades\Log;
use Leantime\Core\Db\Db;

class TicketHistoryRepository
{
    private Db $db;

    public function __construct()
    {
        // Get DB Instance
        $this->db = app(Db::class);
    }

    public function TicketExists(int $ticketId) : bool
    {
        $$pdo = $this->db->pdo();
        $sql = 'SELECT id FROM zp_tickets WHERE id = :id LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $ticketId]);
        $found = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $found !== false;
    }

    /**
     * Try to find a user id by exact username match.
     */
    public function GetUserIdByEmail(?string $email): ?int
    {
        $email = is_string($email) ? trim($email) : '';
        if ($email === '') return null;
        $pdo = $this->db->pdo();
        $sql = 'SELECT id FROM zp_user WHERE username = :u LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([':u' => $email]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $row && isset($row['id']) ? (int)$row['id'] : null;
    }

    /**
     * Try to find a user id by first and last name (case-insensitive) and try both orders (First Last, Last First).
     */
    public function GetUserIdByFullName(?string $fullName): ?int
    {
        $fullName = is_string($fullName) ? trim($fullName) : '';
        if ($fullName === '') return null;

        // Normalize whitespace and split; first token as firstname, last token as lastname
        $fullName = preg_replace('/\s+/', ' ', $fullName);
        $parts = preg_split('/\s+/', $fullName);
        if (!$parts || count($parts) < 2) return null;
        $firstname = $parts[0];
        $lastname = $parts[count($parts)-1];

        $pdo = $this->db->pdo();
        // Case-insensitive comparison using LOWER() and trying both orders
        $sql = 'SELECT id FROM zp_user WHERE (
                    LOWER(firstname) = LOWER(:fn1) AND LOWER(lastname) = LOWER(:ln1)
                ) OR (
                    LOWER(firstname) = LOWER(:fn2) AND LOWER(lastname) = LOWER(:ln2)
                ) LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([
            ':fn1' => $firstname,
            ':ln1' => $lastname,
            ':fn2' => $lastname, // swapped order
            ':ln2' => $firstname,
        ]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $row && isset($row['id']) ? (int)$row['id'] : null;
    }

    /**
     * Try to find a user id by partial name as best-effort (prioritize surname/lastname if provided).
     * Examples matched (case-insensitive depending on collation):
     *  - lastname only
     *  - firstname only
     *  - either contained in either field
     */
    public function GetUserIdByPartialName(?string $fullName): ?int
    {
        $fullName = is_string($fullName) ? trim($fullName) : '';
        if ($fullName === '') return null;
        $parts = preg_split('/\s+/', $fullName);
        $firstname = $parts[0] ?? '';
        $lastname = $parts[count($parts)-1] ?? '';
        $pdo = $this->db->pdo();
        // Prefer matching by lastname if present
        if ($lastname !== '' && $lastname !== $firstname) {
            $sql = 'SELECT id FROM zp_user WHERE lastname = :ln OR lastname LIKE :lnLike LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([':ln' => $lastname, ':lnLike' => '%'.$lastname.'%']);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($row && isset($row['id'])) return (int)$row['id'];
        }

        // Fallback to firstname or any partial in either field
        if ($firstname !== '') {
            $sql = 'SELECT id FROM zp_user WHERE firstname = :fn OR firstname LIKE :fnLike OR lastname LIKE :fnLike LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([':fn' => $firstname, ':fnLike' => '%'.$firstname.'%']);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($row && isset($row['id'])) return (int)$row['id'];
        }

        return null;
    }

    public function AddHistory(int $ticketId, int $userId, string $changeType, string $changeValue) : bool
    {
        $pdo = $this->db->pdo();
        $insert = 'INSERT INTO zp_tickethistory (userId, ticketId, changeType, changeValue, dateModified)
                        VALUES (:userId, :ticketId, :changeType, :changeValue, :date)';
        $st2 = $pdo->prepare($insert);
        $st2->bindValue(':userId', $userId, \PDO::PARAM_INT);
        $st2->bindValue(':ticketId', $ticketId, \PDO::PARAM_INT);
        $st2->bindValue(':changeType', $changeType, \PDO::PARAM_STR);
        $st2->bindValue(':changeValue', $changeValue, \PDO::PARAM_STR);
        $st2->bindValue(':date', date('Y-m-d H:i:s'), \PDO::PARAM_STR);
        $result = $st2->execute();
        $st2->closeCursor();
        return $result;
    }

}

<?php

namespace Leantime\Plugins\GiteaListener\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\GiteaListener\Repositories\GiteaListenerRepository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class Hook extends Controller
{
    private GiteaListenerRepository $repository;

    public function init(GiteaListenerRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Receive webhook POST from Gitea
     *
     * @param array $params
     * @return Response
     */
    public function post(array $params): Response
    {
        // Raw payload
        $raw = $this->incomingRequest->getContent();

        if (empty($raw)) {
            Log::warning('GiteaListener Hook: empty payload');
            return new Response('Empty payload', 400);
        }

        // signature headers
        $sigHeaders = [
            'X-Gitea-Signature',
            'X-Hub-Signature',
            'X-Hub-Signature-256',
        ];
        $signature = null;
        foreach ($sigHeaders as $h) {
            $v = $this->incomingRequest->headers->get($h);
            if (!empty($v)) { $signature = $v; break; }
        }

        // decode JSON
        $payload = @json_decode($raw, true);
        if (!is_array($payload)) {
            Log::warning('GiteaListener Hook: invalid JSON');
            return new Response('Invalid JSON', 400);
        }

        // Try to find matching configuration
        $configs = $this->repository->getAll();
        $matched = null;

        // If signature present, use it to match
        if ($signature !== null) {
            foreach ($configs as $cfg) {
                $secret = $cfg['hook_secret'] ?? '';
                if ($secret === '') continue;
                // compute expected signature
                $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
                if (hash_equals($expected, $signature) || hash_equals($secret, $signature)) {
                    $matched = $cfg;
                    break;
                }
            }
        }

        // If not matched by signature, attempt to match by repository url in payload
        if ($matched === null && isset($payload['repository'])) {
            $repoInfo = $payload['repository'];
            $repoUrls = [];
            foreach (['html_url','url','clone_url','git_http_url','ssh_url'] as $k) {
                if (!empty($repoInfo[$k])) $repoUrls[] = (string)$repoInfo[$k];
            }

            foreach ($configs as $cfg) {
                foreach ($repoUrls as $u) {
                    if ($u === '') continue;
                    // compare normalized urls (strip .git)
                    $a = preg_replace('/\.git$/','', rtrim($u, '/'));
                    $b = preg_replace('/\.git$/','', rtrim($cfg['repository_url'] ?? '', '/'));
                    if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
                        $matched = $cfg;
                        break 2;
                    }
                }
            }
        }

        if ($matched === null) {
            Log::warning('GiteaListener Hook: no matching config for incoming webhook');
            return new Response(json_encode(['success' => false, 'message' => 'No matching repository configuration found']), 404, ['Content-Type' => 'application/json']);
        }

        // Verify signature if secret exists
        $secret = $matched['hook_secret'] ?? '';
        if ($signature !== null && $secret !== '') {
            $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
            if (!hash_equals($expected, $signature) && !hash_equals($secret, $signature)) {
                Log::warning('GiteaListener Hook: signature mismatch');
                return new Response(json_encode(['success' => false, 'message' => 'Signature mismatch']), 403, ['Content-Type' => 'application/json']);
            }
        }

        // Extract branch name
        $branch = '';
        if (!empty($payload['ref'])) {
            // ref like refs/heads/main
            $parts = explode('/', $payload['ref']);
            $branch = end($parts);
        } elseif (!empty($payload['push']) && !empty($payload['push']['changes'][0]['new']['name'])) {
            $branch = $payload['push']['changes'][0]['new']['name'];
        }

        // Extract commit SHA and message
        $commitSha = '';
        $commitMessage = '';
        if (!empty($payload['head_commit'])) {
            $commitSha = $payload['head_commit']['id'] ?? ($payload['head_commit']['sha'] ?? '');
            $commitMessage = $payload['head_commit']['message'] ?? '';
        }

        if ($commitSha === '' && !empty($payload['after'])) {
            $commitSha = $payload['after'];
        }

        if ($commitSha === '' && !empty($payload['commits']) && is_array($payload['commits'])) {
            $first = $payload['commits'][0] ?? null;
            if ($first) {
                $commitSha = $first['id'] ?? ($first['sha'] ?? '');
                $commitMessage = $first['message'] ?? $commitMessage;
            }
        }

        // Build commit link using repository_url from matched config
        $repoUrl = $matched['repository_url'] ?? '';
        $commitLink = '';
        if ($repoUrl !== '' && $commitSha !== '') {
            $parsed = parse_url(rtrim($repoUrl, '/'));
            if ($parsed !== false && isset($parsed['path'])) {
                $path = trim($parsed['path'], '/');
                $parts = explode('/', $path);
                $count = count($parts);
                if ($count >= 2) {
                    $owner = $parts[$count-2];
                    $repo = $parts[$count-1];
                    $base = $parsed['scheme'].'://'.$parsed['host'];
                    if (isset($parsed['port'])) $base .= ':'.$parsed['port'];
                    // account for subpath installations where path includes app prefix
                    $prefix = '';
                    if ($count > 2) {
                        $prefix = implode('/', array_slice($parts, 0, $count-2));
                        $prefix = '/'.$prefix;
                    }
                    $commitLink = rtrim($base, '/').$prefix.'/'.rawurlencode($owner).'/'.rawurlencode($repo).'/commit/'.rawurlencode($commitSha);
                }
            }
        }

        // Build response
        $resp = [
            'success' => true,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'commit_message' => $commitMessage,
            'commit_link' => $commitLink,
        ];

        //parse branch and commit message for ticket references (#123), check tickets and insert into zp_tickethistory
        try {
            $matches = [];

            // find all #number in commit message
            if (!empty($commitMessage)) {
                if (preg_match_all('/#(\d+)/', $commitMessage, $m)) {
                    foreach ($m[1] as $num) $matches[] = (int)$num;
                }
            }

            // find all #number in branch
            if (!empty($branch)) {
                if (preg_match_all('/#(\d+)/', $branch, $m2)) {
                    foreach ($m2[1] as $num) $matches[] = (int)$num;
                }
            }

            // dedupe and sanitize
            $ticketIds = array_values(array_unique(array_filter(array_map('intval', $matches))));

            if (!empty($ticketIds)) {
                $db = app()->make(\Leantime\Core\Db\Db::class);
                $pdo = $db->database;

                $linked = [];
                $errors = [];

                foreach ($ticketIds as $ticketId) {
                    try {
                        // Check if ticket exists
                        $sql = 'SELECT id FROM zp_tickets WHERE id = :id LIMIT 1';
                        $st = $pdo->prepare($sql);
                        $st->execute([':id' => $ticketId]);
                        $found = $st->fetch(\PDO::FETCH_ASSOC);
                        $st->closeCursor();

                        if ($found) {
                            // Insert into zp_tickethistory
                            $insert = 'INSERT INTO zp_tickethistory (userId, ticketId, changeType, changeValue, dateModified) VALUES (:userId, :ticketId, :changeType, :changeValue, :date)';
                            $st2 = $pdo->prepare($insert);
                            $st2->bindValue(':userId', 0, \PDO::PARAM_INT);
                            $st2->bindValue(':ticketId', $ticketId, \PDO::PARAM_INT);
                            $st2->bindValue(':changeType', 'commit', \PDO::PARAM_STR);
                            $st2->bindValue(':changeValue', $commitLink ?: ($commitMessage ?: ''), \PDO::PARAM_STR);
                            $st2->bindValue(':date', date('Y-m-d H:i:s'), \PDO::PARAM_STR);
                            $st2->execute();
                            $st2->closeCursor();

                            Log::info('GiteaListener Hook: recorded commit for ticket #'.$ticketId.' commit '.$commitSha);
                            $linked[] = $ticketId;
                        }
                    } catch (\Throwable $e) {
                        Log::error('GiteaListener Hook: error writing history for ticket #'.$ticketId.': '.$e->getMessage());
                        $errors[$ticketId] = $e->getMessage();
                    }
                }

                if (!empty($linked)) {
                    $resp['tickets_linked'] = $linked;
                }
                if (!empty($errors)) {
                    $resp['ticket_errors'] = $errors;
                }
            }
        } catch (\Throwable $e) {
            Log::error('GiteaListener Hook: error while processing ticket linking: '.$e->getMessage());
            $resp['ticket_error'] = $e->getMessage();
        }

        return new Response(json_encode($resp), 200, ['Content-Type' => 'application/json']);
    }
}


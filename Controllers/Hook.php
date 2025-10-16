<?php

namespace Leantime\Plugins\GiteaListener\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\GiteaListener\Repositories\GiteaListenerRepository;
use Leantime\Plugins\GiteaListener\Repositories\TicketHistoryRepository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class Hook extends Controller
{
    private GiteaListenerRepository $repository;

    private TicketHistoryRepository $ticketHistoryRepository;

    public function init(GiteaListenerRepository $repository, TicketHistoryRepository $ticketHistoryRepository ): void
    {
        $this->repository = $repository;
        $this->ticketHistoryRepository = $ticketHistoryRepository;
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

        // Determine commit author and try to resolve to a Leantime user id
        $authorUsername = '';
        $authorName = '';
        $authorEmail = '';

        if (!empty($payload['pusher']) && is_array($payload['pusher'])) {
            $authorUsername = $payload['pusher']['username'] ?? ($payload['pusher']['login'] ?? ($payload['pusher']['name'] ?? ''));
            $authorName = $payload['pusher']['full_name'] ?? ($payload['pusher']['fullname'] ?? ($payload['pusher']['name'] ?? ''));
            $authorEmail = $payload['pusher']['email'] ?? $authorEmail;
        }
        if ($authorUsername === '' && !empty($payload['sender']) && is_array($payload['sender'])) {
            $authorUsername = $payload['sender']['login'] ?? ($payload['sender']['username'] ?? '');
            if ($authorName === '') {
                $authorName = $payload['sender']['full_name'] ?? ($payload['sender']['fullname'] ?? ($payload['sender']['name'] ?? ''));
            }
            if ($authorEmail === '' && !empty($payload['sender']['email'])) {
                $authorEmail = $payload['sender']['email'];
            }
        }
        // Try author from commits
        if ($authorName === '' || $authorUsername === '' || $authorEmail === '') {
            $ac = $payload['head_commit']['author'] ?? null;
            if (is_array($ac)) {
                if ($authorUsername === '') $authorUsername = $ac['username'] ?? '';
                if ($authorName === '') $authorName = $ac['name'] ?? '';
                if ($authorEmail === '') $authorEmail = $ac['email'] ?? '';
            } elseif (!empty($payload['commits']) && is_array($payload['commits'])) {
                $firstCommit = $payload['commits'][0] ?? null;
                if (is_array($firstCommit) && !empty($firstCommit['author']) && is_array($firstCommit['author'])) {
                    $a = $firstCommit['author'];
                    if ($authorUsername === '') $authorUsername = $a['username'] ?? '';
                    if ($authorName === '') $authorName = $a['name'] ?? '';
                    if ($authorEmail === '') $authorEmail = $a['email'] ?? '';
                }
            }
        }

        $userId = 0; // default to system/unknown user
        try {
            $uid = null;
            // Try by username first
            if ($authorUsername !== '') {
                $uid = $this->ticketHistoryRepository->GetUserIdByEmail($authorUsername);
            }
            // Then try by email (best unique identifier)
            if ($uid === null && $authorEmail !== '') {
                $uid = $this->ticketHistoryRepository->GetUserIdByEmail($authorEmail);
            }
            // Then by full name exact
            if ($uid === null && $authorName !== '') {
                $uid = $this->ticketHistoryRepository->GetUserIdByFullName($authorName);
            }
            // Finally, best-effort partial name (prioritize surname)
            if ($uid === null && $authorName !== '') {
                $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($authorName);
            }

            // as a last ditch try to match username against names
            if ($uid === null && $authorUsername !== '') {
                $uid = $this->ticketHistoryRepository->GetUserIdByPartialName($authorUsername);
            }

            if (is_int($uid)) { $userId = $uid; }
        } catch (\Throwable $e) {
            Log::warning('GiteaListener Hook: could not resolve author to user id: '.$e->getMessage());
        }

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
                foreach ($ticketIds as $ticketId) {
                    try {
                        // Check if ticket exists
                        $found = $this->ticketHistoryRepository->TicketExists($ticketId);

                        if ($found) {
                            // Insert into zp_tickethistory
                            $result = $this->ticketHistoryRepository->AddHistory($ticketId, $userId, 'commit', $commitLink ?: ($commitMessage ?: ''));

                            if (!$result) {
                                Log::error('GiteaListener Hook: failed to record history for ticket #'.$ticketId);
                                continue;
                            }
                            Log::info('GiteaListener Hook: recorded commit for ticket #'.$ticketId.' commit '.$commitSha);
                        }
                    } catch (\Throwable $e) {
                        Log::error('GiteaListener Hook: error writing history for ticket #'.$ticketId.': '.$e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('GiteaListener Hook: error while processing ticket linking: '.$e->getMessage());
        }

        return new Response(null,200, ['Content-Type' => 'application/json']);
    }
}

